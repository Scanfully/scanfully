<?php
/**
 * Sync endpoint unit tests.
 *
 * @package Scanfully\Tests\Unit\Sync
 */

namespace Scanfully\Tests\Unit\Sync;

use Brain\Monkey\Functions;
use Scanfully\Cron\Controller as CronController;
use Scanfully\Sync\Controller;
use WP_REST_Request;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Sync\Controller
 */
final class ControllerTest extends TestCase {

	private const TOKEN = 'site-access-token';

	/**
	 * Stored options, keyed by option name without prefix.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * In-memory transient store.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	/**
	 * Actions scheduled through Action Scheduler.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $scheduled = [];

	protected function setUp(): void {
		parent::setUp();
		$this->options = [
			'is_connected' => 'yes',
			'access_token' => self::TOKEN,
		];

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default = false ) => $this->options[ str_replace( 'scanfully_connect_', '', $name ) ] ?? $default
		);
		Functions\when( 'get_transient' )->alias( fn( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		$GLOBALS['scanfully_test_action_scheduler'] = [
			'locks'      => [],
			'dispatched' => [],
		];
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( ...$args ) {
				$this->scheduled[] = $args;
				return 1;
			}
		);
	}

	/**
	 * Build a request with the given headers.
	 *
	 * @param array<string, string> $headers Request headers.
	 *
	 * @return WP_REST_Request
	 */
	private function request( array $headers ): WP_REST_Request {
		$request = new WP_REST_Request();
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return $request;
	}

	public function test_a_request_without_a_token_is_rejected(): void {
		$this->assertFalse( Controller::check_permission( $this->request( [] ) ) );
	}

	public function test_a_wrong_token_is_rejected(): void {
		$this->assertFalse( Controller::check_permission( $this->request( [ 'Authorization' => 'Bearer wrong' ] ) ) );
	}

	public function test_the_access_token_as_bearer_is_accepted(): void {
		$this->assertTrue( Controller::check_permission( $this->request( [ 'Authorization' => 'Bearer ' . self::TOKEN ] ) ) );
	}

	public function test_the_bearer_scheme_is_case_insensitive(): void {
		$this->assertTrue( Controller::check_permission( $this->request( [ 'Authorization' => 'bearer ' . self::TOKEN ] ) ) );
	}

	public function test_the_fallback_header_is_accepted_when_authorization_is_stripped(): void {
		$this->assertTrue( Controller::check_permission( $this->request( [ 'X-Scanfully-Token' => self::TOKEN ] ) ) );
	}

	public function test_a_disconnected_site_rejects_every_request(): void {
		$this->options['is_connected'] = 'no';

		$this->assertFalse( Controller::check_permission( $this->request( [ 'Authorization' => 'Bearer ' . self::TOKEN ] ) ) );
	}

	public function test_an_empty_stored_token_never_matches(): void {
		$this->options['access_token'] = '';

		$this->assertFalse( Controller::check_permission( $this->request( [ 'Authorization' => 'Bearer ' ] ) ) );
		$this->assertFalse( Controller::check_permission( $this->request( [ 'X-Scanfully-Token' => '' ] ) ) );
	}

	public function test_a_sync_schedules_health_and_directory_jobs_beside_the_recurring_ones(): void {
		$response = Controller::handle_sync();

		$this->assertSame( 202, $response->get_status() );
		$this->assertCount( 2, $this->scheduled );
		$hooks = array_column( $this->scheduled, 1 );
		$this->assertContains( CronController::ACTION_SYNC_SITE_HEALTH, $hooks );
		$this->assertContains( CronController::ACTION_SYNC_DIRECTORIES, $hooks );
		foreach ( $this->scheduled as $args ) {
			$this->assertFalse( $args[4] ?? false, 'A unique job is skipped while the recurring sync is pending.' );
		}
	}

	public function test_a_sync_starts_the_queue_runner_now(): void {
		Controller::handle_sync();

		$this->assertCount( 1, $GLOBALS['scanfully_test_action_scheduler']['dispatched'], 'The syncs must not wait for WP-Cron.' );
		$this->assertSame( [ 'async-request-runner' ], $GLOBALS['scanfully_test_action_scheduler']['locks'] );
	}

	public function test_a_sync_answers_how_the_plugin_reaches_scanfully(): void {
		$this->options['last_used']          = '2026-10-28 03:00:00';
		$this->options['last_request_error'] = [
			'at'      => 1793181600,
			'status'  => 0,
			'message' => 'cURL error 28: Operation timed out',
		];

		$data = Controller::handle_sync()->get_data();

		$this->assertSame( 'scheduled', $data['status'] );
		$this->assertSame( SCANFULLY_VERSION, $data['version'] );
		$this->assertSame( '2026-10-28T03:00:00+00:00', $data['last_success_at'] );
		$this->assertSame(
			[
				'at'      => '2026-10-28T10:00:00+00:00',
				'status'  => 0,
				'message' => 'cURL error 28: Operation timed out',
			],
			$data['last_error']
		);

		// A rate limited sync answers the same.
		$data = Controller::handle_sync()->get_data();
		$this->assertSame( 'rate_limited', $data['status'] );
		$this->assertSame( SCANFULLY_VERSION, $data['version'] );
	}

	public function test_a_sync_before_any_request_answers_nulls(): void {
		$data = Controller::handle_sync()->get_data();

		$this->assertNull( $data['last_success_at'] );
		$this->assertNull( $data['last_error'] );
	}

	public function test_a_second_sync_within_the_window_is_rate_limited(): void {
		Controller::handle_sync();
		$response = Controller::handle_sync();

		$this->assertSame( 429, $response->get_status() );
		$this->assertArrayHasKey( 'Retry-After', $response->get_headers() );
		$this->assertCount( 2, $this->scheduled );
		$this->assertCount( 1, $GLOBALS['scanfully_test_action_scheduler']['dispatched'], 'A refused sync starts no runner.' );
	}
}
