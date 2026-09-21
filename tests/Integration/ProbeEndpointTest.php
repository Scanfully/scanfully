<?php
/**
 * Probe ping and product search integration tests.
 *
 * Builds the endpoint responses against real WooCommerce (wp-env) and skips
 * otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Scanfully\WooCheckout\Controller;
use Scanfully\WooCheckout\ProbePing;
use Scanfully\WooCheckout\ProductSearch;

/**
 * Verifies the endpoints only sign for the scan in the probe header.
 */
final class ProbeEndpointTest extends TestCase {

	private const SECRET = 'integration-probe-secret';

	/**
	 * The scan ID in the probe header.
	 *
	 * @var string
	 */
	private string $scan_id = '';

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wc_get_products' ) ) {
			$this->markTestSkipped( 'WooCommerce not available. Run this suite under wp-env.' );
		}

		update_option( Controller::OPTION_PROBE_SECRET, self::SECRET, false );
		$this->scan_id                     = 'sf-' . time() . '-abcd';
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = $this->scan_id . ':' . hash_hmac( 'sha256', $this->scan_id, self::SECRET );
		$this->reset_probe_cache();
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_X_SCANFULLY_PROBE'] );
		$_GET = [];
		if ( function_exists( 'delete_option' ) ) {
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

	/**
	 * Build an endpoint's response for a request with the given scan ID.
	 *
	 * @param string $class   Endpoint class.
	 * @param string $scan_id Scan ID in the URL.
	 *
	 * @return array|null
	 */
	private function respond( string $class, string $scan_id ): ?array {
		$_GET   = [
			'scanfully_scan_id' => $scan_id,
			'scanfully_nonce'   => 'nonce123',
			'scanfully_search'  => '',
		];
		$method = new ReflectionMethod( $class, 'build_response' );
		$method->setAccessible( true );
		return $method->invoke( null );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function provide_endpoints(): array {
		return [
			'ping'           => [ ProbePing::class, '' ],
			'product search' => [ ProductSearch::class, ':products' ],
		];
	}

	/**
	 * @dataProvider provide_endpoints
	 *
	 * @param string $class  Endpoint class.
	 * @param string $suffix Signature suffix.
	 */
	public function test_the_scan_in_the_header_gets_a_signed_response( string $class, string $suffix ): void {
		$response = $this->respond( $class, $this->scan_id );

		$this->assertIsArray( $response );
		$this->assertSame( hash_hmac( 'sha256', $this->scan_id . ':nonce123' . $suffix, self::SECRET ), $response['pong'] );
	}

	/**
	 * @dataProvider provide_endpoints
	 *
	 * @param string $class Endpoint class.
	 */
	public function test_another_scan_id_gets_nothing( string $class ): void {
		$this->assertNull( $this->respond( $class, 'sf-' . time() . '-ffff' ) );
	}
}
