<?php

namespace S3\Tests;

use PHPUnit\Framework\TestCase;

use S3\S3;
use S3\S3Response;

class S3Test extends TestCase {

	/**
	 * Test file contents
	 * @var string
	 */
	protected $contents = 'Lorem ipsum dolor sit, amet consectetur, adipisicing elit. Dolorum, dolor laudantium odit a laborum saepe sequi eius minus labore ipsum.';

	/**
	 * Test file contents hash
	 * @var string
	 */
	protected $hash = '';

	/**
	 * Access key
	 * @var string
	 */
	protected $access_key = '';

	/**
	 * Secret key
	 * @var string
	 */
	protected $secret_key = '';

	/**
	 * Bucket name
	 * @var string
	 */
	protected $bucket = '';

	/**
	 * Endpoint
	 * @var string
	 */
	protected $endpoint = '';

	/**
	 * Setup tests
	 */
	protected function setUp(): void {
		$this->access_key =  getenv('AWS_ACCESS_KEY') ?? '';
		$this->secret_key = getenv('AWS_SECRET_KEY') ?? '';
		$this->bucket = getenv('AWS_BUCKET') ?? '';
		$this->endpoint = getenv('AWS_ENDPOINT') ?? '';
		$this->hash = sha1($this->contents);
	}

	/**
	 * Upload an object
	 */
	public function testPutObject() {
		$headers = [
			'Content-Type' => 'text/plain',
			'x-amz-acl' => 'public-read'
		];
		$s3 = new S3($this->access_key, $this->secret_key, $this->endpoint);
		$response = $s3->putObject($this->bucket, 'amazon-s3-test.txt', $this->contents, $headers);
		$this->assertInstanceOf(S3Response::class, $response);
		$this->assertEquals(200, $response->code);
	}

	/**
	 * Get object info
	 */
	public function testGetObjectInfo() {
		$s3 = new S3($this->access_key, $this->secret_key, $this->endpoint);
		$response = $s3->getObjectInfo($this->bucket, 'amazon-s3-test.txt');
		$this->assertInstanceOf(S3Response::class, $response);
		$this->assertEquals(200, $response->code);
	}

	/**
	 * Retrieve an object
	 */
	public function testGetObject() {
		$s3 = new S3($this->access_key, $this->secret_key, $this->endpoint);
		$response = $s3->getObject($this->bucket, 'amazon-s3-test.txt');
		$this->assertInstanceOf(S3Response::class, $response);
		$this->assertEquals(200, $response->code);
		$this->assertEquals($this->hash, sha1($response->body));
	}

	/**
	 * Get a bucket
	 */
	public function testGetBucket() {
		$s3 = new S3($this->access_key, $this->secret_key, $this->endpoint);
		$response = $s3->getBucket($this->bucket);
		$this->assertInstanceOf(S3Response::class, $response);
		$this->assertEquals(200, $response->code);
	}

	/**
	 * Delete an object
	 */
	public function testDeleteObject() {
		$s3 = new S3($this->access_key, $this->secret_key, $this->endpoint);
		$response = $s3->deleteObject($this->bucket, 'amazon-s3-test.txt');
		$this->assertInstanceOf(S3Response::class, $response);
		$this->assertEquals(204, $response->code);
	}
}
