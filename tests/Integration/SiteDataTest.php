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

	public function test_plugin_slugs_use_the_folder_or_the_single_file_name(): void {
		$method = new \ReflectionMethod( Controller::class, 'get_plugins' );
		$method->setAccessible( true );
		$slugs = array_column( $method->invoke( null ), 'slug', 'name' );

		$this->assertSame( 'hello', $slugs['Hello Dolly'] ?? null, 'A single-file plugin must not get the slug ".".' );
		$this->assertNotContains( '.', $slugs );
		$this->assertSame( dirname( plugin_basename( SCANFULLY_PLUGIN_FILE ) ), $slugs['Scanfully'] ?? null );
	}

	public function test_the_memory_limit_is_reported_as_it_was_before_being_raised(): void {
		$original = (string) ini_get( 'memory_limit' );
		ini_set( 'memory_limit', '96M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Simulates the site's normal limit.
		Controller::record_boot_memory_limit();

		// Action Scheduler raises the limit before running the health sync.
		ini_set( 'memory_limit', '512M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Simulates Action Scheduler.
		$reported = $this->find( Controller::get_site_data(), 'php_memory_limit' );

		ini_set( 'memory_limit', $original ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restore.
		Controller::record_boot_memory_limit();

		$this->assertSame( '96M', $reported, 'A low normal limit must not be hidden by the raised one.' );
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
