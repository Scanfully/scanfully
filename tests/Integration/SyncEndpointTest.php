<?php
/**
 * Sync endpoint integration tests.
 *
 * Runs under a real WordPress runtime (wp-env) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the sync route rejects unauthenticated requests in real WordPress.
 */
final class SyncEndpointTest extends TestCase {

	private const TOKEN = 'integration-test-token';

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'rest_do_request' ) ) {
			$this->markTestSkipped( 'WordPress runtime not available. Run this suite under wp-env.' );
		}

		update_option( 'scanfully_connect_is_connected', 'yes' );
		update_option( 'scanfully_connect_access_token', self::TOKEN );
		delete_transient( 'scanfully_sync_last_run' );
	}

	protected function tearDown(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( 'scanfully_connect_is_connected' );
			delete_option( 'scanfully_connect_access_token' );
			delete_transient( 'scanfully_sync_last_run' );
		}
		parent::tearDown();
	}

	/**
	 * Dispatch a POST to the sync route.
	 *
	 * @param array<string, string> $headers Request headers.
	 *
	 * @return int The response status.
	 */
	private function post_sync( array $headers ): int {
		$request = new \WP_REST_Request( 'POST', '/scanfully/v1/sync' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return rest_do_request( $request )->get_status();
	}

	public function test_an_anonymous_request_is_rejected(): void {
		$this->assertSame( 401, $this->post_sync( [] ) );
	}

	public function test_a_wrong_token_is_rejected(): void {
		$this->assertSame( 401, $this->post_sync( [ 'Authorization' => 'Bearer wrong' ] ) );
	}

	public function test_the_access_token_is_accepted(): void {
		$this->assertSame( 202, $this->post_sync( [ 'Authorization' => 'Bearer ' . self::TOKEN ] ) );
	}

	public function test_the_fallback_header_is_accepted(): void {
		$this->assertSame( 202, $this->post_sync( [ 'X-Scanfully-Token' => self::TOKEN ] ) );
	}
}
