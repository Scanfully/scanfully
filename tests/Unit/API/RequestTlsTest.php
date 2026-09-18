<?php
/**
 * TLS verification unit tests for API requests.
 *
 * @package Scanfully\Tests\Unit\API
 */

namespace Scanfully\Tests\Unit\API;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Scanfully\API\Request;
use Scanfully\Connect\Controller as ConnectController;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\API\Request
 * @covers \Scanfully\Connect\Controller::refresh_access_token
 * @covers \Scanfully\Main::get_sslverify
 */
final class RequestTlsTest extends TestCase {

	/**
	 * Request arguments captured from wp_remote_* calls.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $captured = [];

	protected function setUp(): void {
		parent::setUp();
		$this->captured = [];

		$capture = function ( string $url, array $args ) {
			$this->captured[] = $args;
			// Treated as a transport error below, so no response is processed.
			return null;
		};
		Functions\when( 'wp_remote_post' )->alias( $capture );
		Functions\when( 'wp_remote_get' )->alias( $capture );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => null === $thing );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'get_option' )->justReturn( 'token' );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url );
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

	/**
	 * Send one request through every Request transport.
	 */
	private function send_all(): void {
		$request = $this->make_request();
		$request->do_request( 'a', [ 'x' => 1 ] );
		$request->do_request_with_response( 'b', [ 'x' => 1 ] );
		$request->do_get_request( 'c' );
		ConnectController::refresh_access_token( 'refresh', 'site' );
	}

	public function test_every_api_request_verifies_tls_by_default(): void {
		$this->send_all();

		$this->assertCount( 4, $this->captured );
		foreach ( $this->captured as $args ) {
			$this->assertTrue( $args['sslverify'] );
		}
	}

	public function test_tls_verification_can_be_turned_off_with_a_filter(): void {
		Filters\expectApplied( 'scanfully_sslverify' )->andReturn( false );

		$this->send_all();

		foreach ( $this->captured as $args ) {
			$this->assertFalse( $args['sslverify'] );
		}
	}
}
