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

	public function test_an_accepted_sync_starts_the_queue_runner_now(): void {
		if ( ! class_exists( \ActionScheduler::class ) ) {
			$this->markTestSkipped( 'Action Scheduler not loaded.' );
		}

		// Catch the runner's loopback request instead of sending it.
		$requests = [];
		$catch    = static function ( $response, array $args, string $url ) use ( &$requests ) {
			$requests[] = [
				'url'  => $url,
				'args' => $args,
			];
			return [
				'headers'  => [],
				'body'     => '',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $catch, 10, 3 );
		\ActionScheduler::lock()->set( 'async-request-runner' ); // as if an admin page just started a runner.

		try {
			$this->assertSame( 202, $this->post_sync( [ 'Authorization' => 'Bearer ' . self::TOKEN ] ) );
		} finally {
			remove_filter( 'pre_http_request', $catch, 10 );
			as_unschedule_all_actions( 'scanfully_sync_site_health', [], 'scanfully' );
			as_unschedule_all_actions( 'scanfully_sync_directories', [], 'scanfully' );
		}

		$runners = array_filter(
			$requests,
			static fn( array $request ) => false !== strpos( $request['url'], 'admin-ajax.php' ) && false !== strpos( $request['url'], 'as_async_request_queue_runner' )
		);
		$this->assertCount( 1, $runners, 'The sync must start the queue runner, not wait for WP-Cron.' );
		$this->assertFalse( reset( $runners )['args']['blocking'], 'The runner request must not hold up the response.' );
	}
}
