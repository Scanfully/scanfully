<?php
/**
 * Site health data integration tests.
 *
 * Collects the real site data (wp-env) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Health\Controller;

/**
 * Verifies site data can be collected outside a web request.
 */
final class SiteDataTest extends TestCase {

	/**
	 * The original SERVER_SOFTWARE value, if any.
	 *
	 * @var string|null
	 */
	private $server_software;

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wp_get_environment_type' ) ) {
			$this->markTestSkipped( 'WordPress runtime not available. Run this suite under wp-env.' );
		}
		$this->server_software = $_SERVER['SERVER_SOFTWARE'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved as-is so tearDown() can restore it.
	}

	protected function tearDown(): void {
		if ( null !== $this->server_software ) {
			$_SERVER['SERVER_SOFTWARE'] = $this->server_software;
		}
		parent::tearDown();
	}

	/**
	 * The collected server data.
	 *
	 * @return array<string, mixed>
	 */
	private function server_data(): array {
		$data = Controller::get_site_data();
		return $data['server'] ?? $data;
	}

	public function test_site_data_without_a_web_server_reports_none(): void {
		// As under WP-CLI or a system cron.
		unset( $_SERVER['SERVER_SOFTWARE'] );

		$this->assertNull( $this->find( $this->server_data(), 'web_server' ) );
	}

	public function test_the_web_server_is_reported_when_known(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58 (Ubuntu)';

		$this->assertSame( 'Apache/2.4.58 (Ubuntu)', $this->find( $this->server_data(), 'web_server' ) );
	}

	/**
	 * Find a key anywhere in nested data.
	 *
	 * @param array<string, mixed> $data Data.
	 * @param string               $key  Key.
	 *
	 * @return mixed
	 */
	private function find( array $data, string $key ) {
		if ( array_key_exists( $key, $data ) ) {
			return $data[ $key ];
		}
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$found = $this->find( $value, $key );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}
}
