<?php

declare(strict_types = 1);

namespace S3;

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

			curl_setopt($this->curl, CURLOPT_PUT, true);
			curl_setopt($this->curl, CURLOPT_INFILE, $file);
			curl_setopt($this->curl, CURLOPT_INFILESIZE, $length);
		} else {
			curl_setopt($this->curl, CURLOPT_POSTFIELDS, $file);
			$md5 = md5($file, true);
		}

		$this->headers['Content-MD5'] = base64_encode($md5);

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
	 * @return $this
	 */
	public function sign(string $access_key, string $secret_key) {
		$canonical_amz_headers = $this->getCanonicalAmzHeaders();

		$string_to_sign = '';
		$string_to_sign .= "{$this->action}\n";
		$string_to_sign .= "{$this->headers['Content-MD5']}\n";
		$string_to_sign .= "{$this->headers['Content-Type']}\n";
		$string_to_sign .= "{$this->headers['Date']}\n";

		if (!empty($canonical_amz_headers)) {
			$string_to_sign .= implode("\n", $canonical_amz_headers) . "\n";
		}

		$string_to_sign .= "/{$this->uri}";

		$signature = base64_encode(
			hash_hmac('sha1', $string_to_sign, $secret_key, true)
		);

		$this->headers['Authorization'] = "AWS $access_key:$signature";

		return $this;
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
