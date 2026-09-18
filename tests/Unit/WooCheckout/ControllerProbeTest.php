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

	public function test_a_valid_header_is_a_probe_request(): void {
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = $this->header_for( 'sf-1700000000-abcd' );

		$this->assertTrue( Controller::is_probe_request() );
	}

	public function test_the_redirect_prefixed_header_is_accepted(): void {
		$_SERVER['REDIRECT_HTTP_X_SCANFULLY_PROBE'] = $this->header_for( 'sf-1700000000-abcd' );

		$this->assertTrue( Controller::is_probe_request() );
	}

	public function test_a_missing_header_is_not_a_probe_request(): void {
		$this->assertFalse( Controller::is_probe_request() );
	}

	public function test_a_wrong_signature_is_not_a_probe_request(): void {
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = 'sf-1700000000-abcd:' . str_repeat( '0', 64 );

		$this->assertFalse( Controller::is_probe_request() );
	}

	public function test_a_malformed_header_is_not_a_probe_request(): void {
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = 'no-separator';

		$this->assertFalse( Controller::is_probe_request() );
	}

	public function test_priming_on_init_does_not_touch_the_rest_server(): void {
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = $this->header_for( 'sf-1700000000-abcd' );

		Controller::prime_probe_request_check();

		$this->assertTrue( Controller::is_probe_request() );
	}
}
