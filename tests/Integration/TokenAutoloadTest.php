<?php
/**
 * Token autoload integration tests.
 *
 * Checks the autoload column in the real options table (wp-env) and skips
 * otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Options\Controller;
use Scanfully\Options\Options;

/**
 * Verifies the API tokens are never autoloaded.
 */
final class TokenAutoloadTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wp_autoload_values_to_autoload' ) ) {
			$this->markTestSkipped( 'WordPress runtime not available. Run this suite under wp-env.' );
		}
	}

	protected function tearDown(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( 'scanfully_connect_access_token' );
			delete_option( 'scanfully_connect_refresh_token' );
			update_option( 'scanfully_tokens_autoload_off', 1, true );
		}
		parent::tearDown();
	}

	/**
	 * Whether an option is autoloaded, read from the database.
	 *
	 * @param string $name Option name.
	 *
	 * @return bool
	 */
	private function is_autoloaded( string $name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test inspects the raw autoload column.
		$autoload = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		return in_array( $autoload, wp_autoload_values_to_autoload(), true );
	}

	public function test_new_tokens_are_stored_without_autoload(): void {
		Controller::set_options( new Options( true, 'site-1', 'access', 'refresh', '2030-01-01 00:00:00', '', '' ) );

		$this->assertFalse( $this->is_autoloaded( 'scanfully_connect_access_token' ) );
		$this->assertFalse( $this->is_autoloaded( 'scanfully_connect_refresh_token' ) );
		$this->assertSame( 'access', Controller::get_option( 'access_token' ) );
	}

	public function test_tokens_stored_by_earlier_versions_stop_autoloading_once(): void {
		delete_option( 'scanfully_connect_access_token' );
		delete_option( 'scanfully_connect_refresh_token' );
		add_option( 'scanfully_connect_access_token', 'old-access', '', 'yes' );
		add_option( 'scanfully_connect_refresh_token', 'old-refresh', '', 'yes' );
		delete_option( 'scanfully_tokens_autoload_off' );
		$this->assertTrue( $this->is_autoloaded( 'scanfully_connect_access_token' ) );

		Controller::maybe_stop_autoloading_tokens();

		$this->assertFalse( $this->is_autoloaded( 'scanfully_connect_access_token' ) );
		$this->assertFalse( $this->is_autoloaded( 'scanfully_connect_refresh_token' ) );
		$this->assertSame( 'old-access', get_option( 'scanfully_connect_access_token' ), 'The value must be kept.' );
		$this->assertTrue( $this->is_autoloaded( 'scanfully_tokens_autoload_off' ), 'The flag itself is autoloaded so the check is free.' );
	}
}
