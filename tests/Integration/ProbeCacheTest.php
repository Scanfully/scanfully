<?php
/**
 * Probe response caching integration tests.
 *
 * Runs in real WordPress (wp-env) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Scanfully\WooCheckout\Controller;

/**
 * Verifies probe responses are marked uncacheable, and normal ones aren't.
 */
final class ProbeCacheTest extends TestCase {

	private const SECRET = 'integration-probe-secret';

	/**
	 * Number of times WordPress built no-cache headers.
	 *
	 * @var int
	 */
	private int $nocache_calls = 0;

	/**
	 * The nocache_headers filter callback.
	 *
	 * @var callable
	 */
	private $counter;

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'nocache_headers' ) ) {
			$this->markTestSkipped( 'WordPress runtime not available. Run this suite under wp-env.' );
		}

		update_option( Controller::OPTION_PROBE_SECRET, self::SECRET, false );
		$this->nocache_calls = 0;
		$this->counter       = function ( $headers ) {
			++$this->nocache_calls;
			return $headers;
		};
		add_filter( 'nocache_headers', $this->counter );
		$this->reset_probe_cache();
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_X_SCANFULLY_PROBE'] );
		if ( function_exists( 'remove_filter' ) ) {
			remove_filter( 'nocache_headers', $this->counter );
			delete_option( Controller::OPTION_PROBE_SECRET );
			$this->reset_probe_cache();
		}
		parent::tearDown();
	}

	/**
	 * Forget the memoised probe check.
	 */
	private function reset_probe_cache(): void {
		$cache = new ReflectionProperty( Controller::class, 'probe_request_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );
	}

	public function test_a_normal_request_is_left_alone(): void {
		$litespeed = did_action( 'litespeed_control_set_nocache' );

		Controller::prime_probe_request_check();

		$this->assertSame( 0, $this->nocache_calls );
		$this->assertSame( $litespeed, did_action( 'litespeed_control_set_nocache' ) );
	}

	/**
	 * Runs in its own process: WordPress only sends headers while nothing has
	 * been output yet, and PHPUnit has printed progress in the main process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_probe_request_is_marked_uncacheable(): void {
		$scan_id                           = 'sf-' . time() . '-abcd';
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = $scan_id . ':' . hash_hmac( 'sha256', $scan_id, self::SECRET );
		$litespeed                         = did_action( 'litespeed_control_set_nocache' );

		Controller::prime_probe_request_check();

		$this->assertSame( 1, $this->nocache_calls, 'No-cache headers must be sent.' );
		$this->assertSame( $litespeed + 1, did_action( 'litespeed_control_set_nocache' ) );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}
}
