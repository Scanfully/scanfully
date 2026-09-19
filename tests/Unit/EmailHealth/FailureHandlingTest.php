<?php
/**
 * Email check failure handling unit tests.
 *
 * Drives a full check cycle against a fake Scanfully API and a fake wp_mail().
 *
 * @package Scanfully\Tests\Unit\EmailHealth
 */

namespace Scanfully\Tests\Unit\EmailHealth;

use Brain\Monkey\Functions;
use Scanfully\EmailHealth\Controller;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\EmailHealth\Controller
 * @covers \Scanfully\API\Request
 */
final class FailureHandlingTest extends TestCase {

	/**
	 * Stored options, keyed by name without the plugin prefix.
	 *
	 * @var array<string, string>
	 */
	private array $options = [];

	/**
	 * Fake API responses by endpoint: [status, raw body], or null for a
	 * transport error.
	 *
	 * @var array<string, array{int, string}|null>
	 */
	private array $api = [];

	/**
	 * Endpoints the plugin called, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * What wp_mail() returns.
	 *
	 * @var bool
	 */
	private bool $mail_result = true;

	/**
	 * Number of wp_mail() calls.
	 *
	 * @var int
	 */
	private int $mails = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->stubTranslationFunctions();

		$this->options = [
			'is_connected'                         => 'yes',
			'site_id'                              => 'site-1',
			'access_token'                         => 'token',
			'email_deliverability_secret'          => 'secret',
			'email_deliverability_inbound_address' => 'ping+' . str_repeat( 'a', 26 ) . '.{nonce}@inbox.scanfully.com',
		];
		$this->api         = [ 'attempt' => [ 202, '' ] ];
		$this->calls       = [];
		$this->mail_result = true;
		$this->mails       = 0;

		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				$key = self::key( $name );
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ self::key( $name ) ] = (string) $value;
				return true;
			}
		);
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'get_plugins' )->justReturn( [] );
		Functions\when( 'wp_generate_uuid4' )->justReturn( '0e5a6c9e-3b1f-4d2a-9c7e-5f8b2a1d4c6e' );
		Functions\when( 'get_bloginfo' )->justReturn( '7.1' );
		Functions\when( 'is_email' )->returnArg();
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => null === $thing );
		Functions\when( 'wp_remote_post' )->alias( fn( string $url ) => $this->respond( $url ) );
		Functions\when( 'wp_remote_get' )->alias( fn( string $url ) => $this->respond( $url ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $response ) => $response[0] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $response ) => $response[1] );
		Functions\when( 'wp_mail' )->alias(
			function () {
				++$this->mails;
				return $this->mail_result;
			}
		);
	}

	/**
	 * The option name without the plugin prefix.
	 *
	 * @param string $name Full option name.
	 *
	 * @return string
	 */
	private static function key( string $name ): string {
		return 0 === strpos( $name, 'scanfully_connect_' ) ? substr( $name, 18 ) : $name;
	}

	/**
	 * Answer a fake API request.
	 *
	 * @param string $url Request URL.
	 *
	 * @return array{int, string}|null
	 */
	private function respond( string $url ) {
		$endpoint      = substr( $url, strrpos( $url, '/' ) + 1 );
		$this->calls[] = $endpoint;
		return array_key_exists( $endpoint, $this->api ) ? $this->api[ $endpoint ] : [ 200, '{}' ];
	}

	/**
	 * Run one manual check cycle (manual runs don't reschedule).
	 */
	private function run_check(): void {
		Controller::run_ping( 'manual' );
	}

	public function test_an_unreachable_api_is_an_api_error_not_a_mail_failure(): void {
		$this->api['attempt'] = null;

		$this->run_check();

		$this->assertSame( 'The Scanfully API could not be reached.', $this->options['email_deliverability_last_api_error'] );
		$this->assertArrayNotHasKey( 'email_deliverability_last_failure_at', $this->options );
		$this->assertSame( 0, $this->mails );
	}

	public function test_a_server_error_is_an_api_error_not_a_mail_failure(): void {
		$this->api['attempt'] = [ 503, 'email health not configured' ];

		$this->run_check();

		$this->assertStringContainsString( '503', $this->options['email_deliverability_last_api_error'] );
		$this->assertArrayNotHasKey( 'email_deliverability_last_failure_at', $this->options );
	}

	public function test_clock_drift_gets_its_own_message(): void {
		$this->api['attempt'] = [ 400, "timestamp out of range\n" ];

		$this->run_check();

		$this->assertStringContainsString( 'clock', $this->options['email_deliverability_last_api_error'] );
	}

	public function test_a_mail_failure_switches_to_the_fast_cadence_without_asking_the_api(): void {
		$this->mail_result = false;

		$this->run_check();

		$this->assertNotEmpty( $this->options['email_deliverability_last_failure_at'] );
		$this->assertContains( 'attempt-result', $this->calls );
		$this->assertNotContains( 'state', $this->calls, 'A healthy server state must not cancel the faster cadence.' );
	}

	public function test_a_repeated_mail_failure_keeps_the_original_window(): void {
		$this->mail_result = false;
		$window_start      = gmdate( 'Y-m-d\TH:i:s\Z', time() - HOUR_IN_SECONDS );
		$this->options['email_deliverability_last_failure_at'] = $window_start;

		$this->run_check();

		$this->assertSame( $window_start, $this->options['email_deliverability_last_failure_at'] );
	}

	public function test_a_mail_failure_after_the_window_starts_a_new_one(): void {
		$this->mail_result = false;
		$this->options['email_deliverability_last_failure_at'] = gmdate( 'Y-m-d\TH:i:s\Z', time() - 2 * DAY_IN_SECONDS );

		$this->run_check();

		$this->assertGreaterThan( time() - MINUTE_IN_SECONDS, strtotime( $this->options['email_deliverability_last_failure_at'] ) );
	}

	public function test_a_successful_attempt_clears_the_last_api_error(): void {
		$this->options['email_deliverability_last_api_error']    = 'The Scanfully API could not be reached.';
		$this->options['email_deliverability_last_api_error_at'] = '2026-09-18T10:00:00Z';

		$this->run_check();

		$this->assertSame( '', $this->options['email_deliverability_last_api_error'] );
		$this->assertSame( 1, $this->mails );
	}
}
