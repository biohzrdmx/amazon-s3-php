<?php

declare(strict_types = 1);

namespace S3;

use DateTime;

use S3\S3Response;

class S3Request {

	/**
	 * Action
	 * @var string
	 */
	private $action = '';

	/**
	 * Endpoint
	 * @var string
	 */
	private $endpoint = '';

	/**
	 * URI
	 * @var string
	 */
	private $uri = '';

	/**
	 * Headers array
	 * @var array
	 */
	private $headers = [];

	/**
	 * Curl handle
	 * @var mixed
	 */
	private $curl = null;

	/**
	 * Response
	 * @var mixed
	 */
	private $response = null;

	/**
	 * CurlMulti handle
	 * @var mixed
	 */
	private $multi_curl = null;

	/**
	 * Constructor
	 * @param string $action   Action
	 * @param string $endpoint Endpoint
	 * @param string $uri      URI
	 */
	public function __construct(string $action, string $endpoint, string $uri) {
		$this->action = $action;
		$this->endpoint = $endpoint;
		$this->uri = $uri;

		$this->headers = array(
			'Content-MD5' => '',
			'Content-Type' => '',
			'Date' => gmdate('D, d M Y H:i:s T'),
			'Host' => $this->endpoint
		);

		$this->curl = curl_init();
		$this->response = new S3Response();
	}

	/**
	 * Save to resource
	 * @param  resource $resource Resource handle
	 * @return void
	 */
	public function saveToResource($resource): void {
		$this->response->saveToResource($resource);
	}

	/**
	 * Set contents
	 * @param  mixed $file File data or file handle
	 * @return $this
	 */
	public function setFileContents($file) {
		if ( is_resource($file) ) {
			$hash_ctx = hash_init('md5');
			$length = hash_update_stream($hash_ctx, $file);
			$md5 = hash_final($hash_ctx, true);
			rewind($file);
			$hash_ctx = hash_init('sha256');
			$length = hash_update_stream($hash_ctx, $file);
			$sha256 = hash_final($hash_ctx);
			rewind($file);
			curl_setopt($this->curl, CURLOPT_PUT, true);
			curl_setopt($this->curl, CURLOPT_INFILE, $file);
			curl_setopt($this->curl, CURLOPT_INFILESIZE, $length);
		} else {
			curl_setopt($this->curl, CURLOPT_POSTFIELDS, $file);
			$md5 = md5($file, true);
			$sha256 = hash('sha256', $file);
		}

		$this->headers['Content-MD5'] = base64_encode($md5);
		$this->headers['x-amz-content-sha256'] = $sha256;

		return $this;
	}

	/**
	 * Set custom headers
	 * @param array $custom_headers Headers array
	 * @return $this
	 */
	public function setHeaders(array $custom_headers) {
		$this->headers = array_merge($this->headers, $custom_headers);
		return $this;
	}

	/**
	 * Sign payload
	 * @param  string $access_key Access key
	 * @param  string $secret_key Secret key
	 * @param  string $endpoint   Endpoint
	 * @return $this
	 */
	public function sign(string $access_key, string $secret_key, string $endpoint) {
		# Prepare date, parse region name from the endpoint and set service name
		$date = new DateTime( 'UTC' );
		$service = 's3';
		$region = $this->getRegion($endpoint);

		# Define algorithm
		$algorithm = 'AWS4-HMAC-SHA256';
		$algorithm_name = 'sha256';

		# Remove empty headers
		$this->headers = array_filter($this->headers);

		# Add extra headers
		$this->headers['host'] = $endpoint;
		$this->headers['x-amz-date'] = $date->format('Ymd\THis\Z');
		# Check content signature
		if (! isset( $this->headers['x-amz-content-sha256'] ) ) {
			# No content, add empty content signature
			$this->headers['x-amz-content-sha256'] = hash($algorithm_name, '');
		}

		# Part 1: Canonical request
		$canonical_request = [];
		# Method
		$canonical_request[] = $this->action;
		# URI
		$canonical_request[] = '/' . trim($this->uri, '/');
		# Query string
		$canonical_request[] = '';
		# Headers
		$headers = [];
		foreach ( $this->headers as $key => $value ) {
			$headers[ strtolower( $key ) ] = trim( $value );
		}
		uksort($headers, 'strcmp');
		foreach ( $headers as $key => $value ) {
			$canonical_request[] = $key . ':' . $value;
		}
		# Blank line
		$canonical_request[] = '';
		# Signed headers
		$canonical_request[] = implode( ';', array_keys( $headers ) );
		# Payload
		$canonical_request[] = $headers['x-amz-content-sha256'];
		# Build canonical request
		$canonical_request = implode( "\n", $canonical_request );

		# Part 2: String to sign
		$string_to_sign = [];
		# Algorithm
		$string_to_sign[] = $algorithm;
		# Date
		$string_to_sign[] = $date->format( 'Ymd\THis\Z' );
		# Credential scope
		$scope = [
			$date->format( 'Ymd' ),
		];
		$scope[] = $region;
		$scope[] = $service;
		$scope[] = 'aws4_request';
		$string_to_sign[] = implode('/', $scope);
		# Canonical request
		$string_to_sign[] = hash($algorithm_name, $canonical_request);
		# Build string to sign
		$string_to_sign = implode("\n", $string_to_sign);

		# Part 3: Signature
		$key_secret = 'AWS4' . $secret_key;
		$key_date = hash_hmac($algorithm_name, $date->format('Ymd'), $key_secret, true);
		$key_region = hash_hmac($algorithm_name, $region, $key_date, true);
		$key_service = hash_hmac($algorithm_name, $service, $key_region, true);
		$key_signing = hash_hmac($algorithm_name, 'aws4_request', $key_service, true);
		# Build final signature
		$signature = hash_hmac($algorithm_name, $string_to_sign, $key_signing);

		# Part 4: Authorization header
		$authorization = [
			'Credential=' . $access_key . '/' . implode( '/', $scope ),
			'SignedHeaders=' . implode( ';', array_keys( $headers ) ),
			'Signature=' . $signature,
		];
		$authorization = $algorithm . ' ' . implode( ',', $authorization );

		# Finally, add the header
		$this->headers['Authorization'] = $authorization;
		return $this;
	}

	/**
	 * Get region
	 * @param  string $endpoint Endpoint
	 */
	protected function getRegion(string $endpoint): string {
		$region = '';
		# Parse region from endpoint if not specific
		if ( preg_match('/s3[.-](?:website-|dualstack\.)?(.+)\.amazonaws\.com/i', $endpoint, $match) !== 0 && strtolower($match[1]) !== 'external-1' ) {
			$region = $match[1];
		}
		return empty($region) ? 'us-east-1' : $region;
	}

	/**
	 * Use CurlMulti handle
	 * @param  mixed $mh CurlMulti handle
	 * @return $this
	 */
	public function useMultiCurl($mh) {
		$this->multi_curl = $mh;
		return $this;
	}

	/**
	 * Override Curl options
	 * @param  array $curl_opts Options array
	 * @return $this
	 */
	public function useCurlOpts(array $curl_opts) {
		curl_setopt_array($this->curl, $curl_opts);

		return $this;
	}

	/**
	 * Get response
	 * @return S3Response
	 */
	public function getResponse(): S3Response {
		$http_headers = array_map(
			function($header, $value) {
				return "{$header}: {$value}";
			},
			array_keys($this->headers),
			array_values($this->headers)
		);

		curl_setopt_array($this->curl, [
			CURLOPT_USERAGENT => 'biohzrdmx/amazon-s3-php',
			CURLOPT_URL => "https://{$this->endpoint}/{$this->uri}",
			CURLOPT_HTTPHEADER => $http_headers,
			CURLOPT_HEADER => false,
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_WRITEFUNCTION => [ $this->response, '__curlWriteFunction' ],
			CURLOPT_HEADERFUNCTION => [ $this->response, '__curlHeaderFunction' ]
		]);

		switch ($this->action) {
			case 'DELETE':
				curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
				break;
			case 'HEAD':
				curl_setopt($this->curl, CURLOPT_NOBODY, true);
				break;
			case 'POST':
				curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'POST');
				break;
			case 'PUT':
				curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'PUT');
				break;
		}

		if (isset($this->multi_curl)) {
			curl_multi_add_handle($this->multi_curl, $this->curl);

			$running = null;
			do {
				curl_multi_exec($this->multi_curl, $running);
				curl_multi_select($this->multi_curl);
			} while ($running > 0);

			curl_multi_remove_handle($this->multi_curl, $this->curl);
		} else {
			curl_exec($this->curl);
		}

		$this->response->finalize($this->curl);

		curl_close($this->curl);

		return $this->response;
	}

	/**
	 * Get canonical Amazon headers
	 * @return array
	 */
	private function getCanonicalAmzHeaders(): array {
		$canonical_amz_headers = [];

		foreach ($this->headers as $header => $value) {
			$header = trim(strtolower($header));
			$value = trim($value);

			if (strpos($header, 'x-amz-') === 0) {
				$canonical_amz_headers[$header] = "{$header}:{$value}";
			}
		}

		ksort($canonical_amz_headers);

		return $canonical_amz_headers;
	}
}
