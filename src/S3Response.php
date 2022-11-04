<?php

declare(strict_types = 1);

namespace S3;

class S3Response {

	/**
	 * Error
	 * @var mixed
	 */
	public $error = null;

	/**
	 * Status code
	 * @var mixed
	 */
	public $code = null;

	/**
	 * Headers array
	 * @var array
	 */
	public $headers = [];

	/**
	 * Body
	 * @var mixed
	 */
	public $body = null;

	/**
	 * Save to resource
	 * @param  resource $resource Resource handle
	 * @return void
	 */
	public function saveToResource($resource): void {
		$this->body = $resource;
	}

	/**
	 * Callback for CURLOPT_WRITEFUNCTION
	 * @param  mixed  $ch   Curl handle
	 * @param  string $data Data to write
	 * @return mixed
	 */
	public function __curlWriteFunction($ch, $data) {
		if (is_resource($this->body)) {
			return fwrite($this->body, $data);
		} else {
			$this->body .= $data;
			return strlen($data);
		}
	}

	/**
	 * Callback for CURLOPT_HEADERFUNCTION
	 * @param  mixed  $ch   Curl handle
	 * @param  string $data Data to write
	 * @return mixed
	 */
	public function __curlHeaderFunction($ch, $data) {
		$header = explode(':', $data, 2);

		if (count($header) == 2) {
			list($key, $value) = $header;
			$this->headers[$key] = trim($value);
		}

		return strlen($data);
	}

	/**
	 * Finalize response
	 * @param  mixed $ch Curl handle
	 * @return void
	 */
	public function finalize($ch): void {
		if (is_resource($this->body)) {
			rewind($this->body);
		}

		if (curl_errno($ch) || curl_error($ch)) {
			$this->error = array(
				'code' => curl_errno($ch),
				'message' => curl_error($ch),
			);
		} else {
			$this->code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

			if ($this->code > 300 && $content_type == 'application/xml') {
				if (is_resource($this->body)) {
					$contents = stream_get_contents($this->body);
					$response = simplexml_load_string($contents ?: '');

					rewind($this->body);
				} else {
					$response = simplexml_load_string($this->body ?? '');
				}

				if ($response) {
					$error = array(
						'code' => (string)$response->Code,
						'message' => (string)$response->Message,
					);

					if (isset($response->Resource)) {
						$error['resource'] = (string)$response->Resource;
					}

					$this->error = $error;
				}
			}
		}
	}
}
