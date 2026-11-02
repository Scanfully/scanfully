<?php
/**
 * Unit tests of the record of failed requests to Scanfully.
 *
 * @package Scanfully\Tests\Unit\API
 */

namespace Scanfully\Tests\Unit\API;

use Brain\Monkey\Functions;
use Scanfully\API\Request;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\API\Request
 */
final class RequestFailureTest extends TestCase {

	/**
	 * Stored options, keyed by option name.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * What the next request answers: a response array or a WP_Error.
	 *
	 * @var mixed
	 */
	private $answer;

	protected function setUp(): void {
		parent::setUp();
		$this->options = [];
		$this->answer  = [ 'response' => [ 'code' => 200 ] ];

		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_remote_post' )->alias( fn() => $this->answer );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	/**
	 * A minimal concrete request.
	 *
	 * @return Request
	 */
	private function make_request(): Request {
		return new class() extends Request {
			/**
			 * Get the url for the request.
			 *
			 * @param string $endpoint The endpoint.
			 *
			 * @return string
			 */
			public function get_url( string $endpoint ): string {
				return 'https://api.scanfully.test/' . $endpoint;
			}

			/**
			 * Get the body for the request.
			 *
			 * @param array $data The data.
			 *
			 * @return array
			 */
			public function get_body( array $data ): array {
				return $data;
			}
		};
	}

	public function test_a_request_without_an_answer_keeps_its_error(): void {
		$this->answer = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 60001 milliseconds' );

		$this->assertNull( $this->make_request()->do_request( 'sites/s1/data', [] ) );

		$error = Request::last_error();
		$this->assertNotNull( $error );
		$this->assertSame( 0, $error['status'] );
		$this->assertSame( 'cURL error 28: Operation timed out after 60001 milliseconds', $error['message'] );
		$this->assertGreaterThan( 0, $error['at'] );
	}

	public function test_an_error_status_keeps_the_status(): void {
		$this->answer = [ 'response' => [ 'code' => 503 ] ];

		$this->make_request()->do_request_with_response( 'sites/s1/email', [] );

		$this->assertSame( 503, Request::last_error()['status'] ?? null );
	}

	public function test_a_refusal_of_the_connection_is_kept(): void {
		foreach ( [ 401, 403, 407 ] as $status ) {
			$this->answer = [ 'response' => [ 'code' => $status ] ];

			$this->make_request()->do_request( 'sites/s1/data', [] );

			$this->assertSame( $status, Request::last_error()['status'] ?? null );
		}
	}

	public function test_an_answer_about_one_request_is_not_a_failure_to_reach_scanfully(): void {
		foreach ( [ 400, 404, 409, 422, 429 ] as $status ) {
			$this->answer = [ 'response' => [ 'code' => $status ] ];

			$this->make_request()->do_request( 'sites/s1/timeline', [] );
			$this->make_request()->do_request_with_response( 'sites/s1/email', [] );

			$this->assertNull( Request::last_error(), "status $status" );
		}
	}

	public function test_a_long_error_is_cut(): void {
		$this->answer = new \WP_Error( 'http_request_failed', str_repeat( 'x', 500 ) );

		$this->make_request()->do_request( 'sites/s1/data', [] );

		$this->assertSame( 200, strlen( Request::last_error()['message'] ?? '' ) );
	}

	public function test_a_success_keeps_when_and_leaves_the_last_error(): void {
		$this->options[ Request::OPTION_LAST_ERROR ] = [
			'at'      => 1000,
			'status'  => 503,
			'message' => '',
		];

		$this->assertSame( 200, $this->make_request()->do_request( 'sites/s1/data', [] ) );

		$this->assertArrayHasKey( 'scanfully_connect_last_used', $this->options );
		$this->assertSame( 1000, Request::last_error()['at'] ?? null );
	}

	public function test_no_error_before_any_failed(): void {
		$this->assertNull( Request::last_error() );
	}
}
