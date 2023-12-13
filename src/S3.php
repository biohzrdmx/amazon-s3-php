<?php

declare(strict_types = 1);

namespace S3;

use S3\S3Request;
use S3\S3Response;

class S3 {

	/**
	 * Default endpoint
	 */
	const DEFAULT_ENDPOINT = 's3.amazonaws.com';

	/**
	 * Access key
	 * @var string
	 */
	private $access_key = '';

	/**
	 * Secret key
	 * @var string
	 */
	private $secret_key = '';

	/**
	 * Endpoint URL
	 * @var string
	 */
	private $endpoint = '';

	/**
	 * CurlMulti handle
	 * @var mixed
	 */
	private $multi_curl = null;

	/**
	 * Curl options array
	 * @var array
	 */
	private $curl_opts = [];

	/**
	 * Constructor
	 * @param string $access_key Access key
	 * @param string $secret_key Secret key
	 * @param string $endpoint   Endpoint URL
	 */
	public function __construct(string $access_key, string $secret_key, string $endpoint = '') {
		$this->access_key = $access_key;
		$this->secret_key = $secret_key;
		$this->endpoint = $endpoint ?: self::DEFAULT_ENDPOINT;

		$this->multi_curl = curl_multi_init();

		$this->curl_opts = [
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_LOW_SPEED_LIMIT => 1,
			CURLOPT_LOW_SPEED_TIME => 30
		];
	}

	/**
	 * Destructor
	 */
	public function __destruct() {
		if ($this->multi_curl) {
			curl_multi_close($this->multi_curl);
		}
	}

	/**
	 * Override Curl options
	 * @param  array $curl_opts Options array
	 * @return $this
	 */
	public function useCurlOpts(array $curl_opts) {
		$this->curl_opts = $curl_opts;
		return $this;
	}

	/**
	 * Put object
	 * @param  string $bucket  Bucket name
	 * @param  string $path    Object path
	 * @param  mixed  $file    File contents or file handle
	 * @param  array  $headers Additional headers
	 * @return S3Response
	 */
	public function putObject(string $bucket, string $path, $file, array $headers = []): S3Response {
		$uri = "$bucket/$path";

		$request = (new S3Request('PUT', $this->endpoint, $uri))
			->setFileContents($file)
			->setHeaders($headers)
			->useMultiCurl($this->multi_curl)
			->useCurlOpts($this->curl_opts)
			->sign($this->access_key, $this->secret_key, $this->endpoint);

		return $request->getResponse();
	}

	/**
	 * Get object info
	 * @param  string   $bucket  Bucket name
	 * @param  string   $path    Object path
	 * @param  array    $headers Additional headers
	 * @return S3Response
	 */
	public function getObjectInfo(string $bucket, string $path, array $headers = []): S3Response {
		$uri = "$bucket/$path";

		$request = (new S3Request('HEAD', $this->endpoint, $uri))
			->setHeaders($headers)
			->useMultiCurl($this->multi_curl)
			->useCurlOpts($this->curl_opts)
			->sign($this->access_key, $this->secret_key, $this->endpoint);

		return $request->getResponse();
	}

	/**
	 * Get object
	 * @param  string $bucket   Bucket name
	 * @param  string $path     Object path
	 * @param  mixed  $resource File resource
	 * @param  array  $headers  Additional headers
	 * @return S3Response
	 */
	public function getObject(string $bucket, string $path, $resource = null, array $headers = []): S3Response {
		$uri = "$bucket/$path";

		$request = (new S3Request('GET', $this->endpoint, $uri))
			->setHeaders($headers)
			->useMultiCurl($this->multi_curl)
			->useCurlOpts($this->curl_opts)
			->sign($this->access_key, $this->secret_key, $this->endpoint);

		if (is_resource($resource)) {
			$request->saveToResource($resource);
		}

		return $request->getResponse();
	}

	/**
	 * Delete object
	 * @param  string $bucket   Bucket name
	 * @param  string $path     Object path
	 * @param  array  $headers  Additional headers
	 * @return S3Response
	 */
	public function deleteObject(string $bucket, string $path, array $headers = []): S3Response {
		$uri = "$bucket/$path";

		$request = (new S3Request('DELETE', $this->endpoint, $uri))
			->setHeaders($headers)
			->useMultiCurl($this->multi_curl)
			->useCurlOpts($this->curl_opts)
			->sign($this->access_key, $this->secret_key, $this->endpoint);

		return $request->getResponse();
	}

	/**
	 * Get bucket
	 * @param  string $bucket   Bucket name
	 * @param  array  $headers  Additional headers
	 * @return S3Response
	 */
	public function getBucket(string $bucket, array $headers = []): S3Response {
		$request = (new S3Request('GET', $this->endpoint, $bucket))
			->setHeaders($headers)
			->useMultiCurl($this->multi_curl)
			->useCurlOpts($this->curl_opts)
			->sign($this->access_key, $this->secret_key, $this->endpoint);

		$response = $request->getResponse();

		if (!isset($response->error)) {
			$body = simplexml_load_string($response->body ?? '');

			if ($body) {
				$response->body = $body;
			}
		}

		return $response;
	}
}
