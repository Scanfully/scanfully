<?php
/**
 * WooCheckout probe detection unit tests.
 *
 * @package Scanfully\Tests\Unit\WooCheckout
 */

namespace Scanfully\Tests\Unit\WooCheckout;

use Brain\Monkey\Functions;
use ReflectionProperty;
use Scanfully\WooCheckout\Controller;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\WooCheckout\Controller::is_probe_request
 */
final class ControllerProbeTest extends TestCase {

	private const SECRET = 'test-secret';

	protected function setUp(): void {
		parent::setUp();
		$this->reset_cache();
		unset( $_SERVER['HTTP_X_SCANFULLY_PROBE'], $_SERVER['REDIRECT_HTTP_X_SCANFULLY_PROBE'] );

		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) {
				return Controller::OPTION_PROBE_SECRET === $key ? self::SECRET : $default;
			}
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		// Calling rest_get_server() on `init` fires rest_api_init too early.
		Functions\expect( 'rest_get_server' )->never();
	}

	protected function tearDown(): void {
		$this->reset_cache();
		unset( $_SERVER['HTTP_X_SCANFULLY_PROBE'], $_SERVER['REDIRECT_HTTP_X_SCANFULLY_PROBE'] );
		parent::tearDown();
	}

	/**
	 * Clear the memoised probe result between tests.
	 */
	private function reset_cache(): void {
		$property = new ReflectionProperty( Controller::class, 'probe_request_cache' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/**
	 * Build a valid header value for a scan id.
	 *
	 * @param string $scan_id Scan id.
	 *
	 * @return string
	 */
	private function header_for( string $scan_id ): string {
		return $scan_id . ':' . hash_hmac( 'sha256', $scan_id, self::SECRET );
	}

	/**
	 * A scan ID issued at the given offset from now.
	 *
	 * @param int $offset Seconds from now; negative is in the past.
	 *
	 * @return string
	 */
	private function fresh_scan_id( int $offset = 0 ): string {
		return 'sf-' . ( time() + $offset ) . '-abcd';
	}

	public function test_a_valid_header_is_a_probe_request(): void {
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = $this->header_for( $this->fresh_scan_id() );

		$this->assertTrue( Controller::is_probe_request() );
	}

	public function test_the_redirect_prefixed_header_is_accepted(): void {
		$_SERVER['REDIRECT_HTTP_X_SCANFULLY_PROBE'] = $this->header_for( $this->fresh_scan_id() );

		$this->assertTrue( Controller::is_probe_request() );
	}

	public function test_a_missing_header_is_not_a_probe_request(): void {
		$this->assertFalse( Controller::is_probe_request() );
	}

	public function test_a_wrong_signature_is_not_a_probe_request(): void {
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = $this->fresh_scan_id() . ':' . str_repeat( '0', 64 );

		$this->assertFalse( Controller::is_probe_request() );
	}

	public function test_a_malformed_header_is_not_a_probe_request(): void {
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = 'no-separator';

		$this->assertFalse( Controller::is_probe_request() );
	}

	public function test_priming_on_init_does_not_touch_the_rest_server(): void {
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = $this->header_for( $this->fresh_scan_id() );
		// A verified probe response is marked uncacheable.
		Functions\expect( 'nocache_headers' )->once();

		Controller::prime_probe_request_check();

		$this->assertTrue( Controller::is_probe_request() );
	}

	public function test_a_scan_id_older_than_two_hours_is_rejected(): void {
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = $this->header_for( $this->fresh_scan_id( -2 * HOUR_IN_SECONDS - 60 ) );

		$this->assertFalse( Controller::is_probe_request() );
	}

	public function test_a_scan_id_just_inside_the_window_is_accepted(): void {
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = $this->header_for( $this->fresh_scan_id( -2 * HOUR_IN_SECONDS + 60 ) );

		$this->assertTrue( Controller::is_probe_request() );
	}

	public function test_a_scan_id_far_in_the_future_is_rejected(): void {
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = $this->header_for( $this->fresh_scan_id( 2 * HOUR_IN_SECONDS + 60 ) );

		$this->assertFalse( Controller::is_probe_request() );
	}

	public function test_a_leaked_header_from_2023_is_rejected(): void {
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = $this->header_for( 'sf-1700000000-abcd' );

		$this->assertFalse( Controller::is_probe_request() );
	}

	public function test_a_signed_scan_id_in_another_format_is_rejected(): void {
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = $this->header_for( 'anything-signed' );

		$this->assertFalse( Controller::is_probe_request() );
	}

	public function test_the_max_age_is_filterable(): void {
		\Brain\Monkey\Filters\expectApplied( 'scanfully_woocheckout_probe_max_age' )->andReturn( 60 );
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = $this->header_for( $this->fresh_scan_id( -120 ) );

		$this->assertFalse( Controller::is_probe_request() );
	}
}
