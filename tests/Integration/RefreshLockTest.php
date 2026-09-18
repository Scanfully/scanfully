<?php
/**
 * Token refresh lock integration tests.
 *
 * Runs the lock queries against the real WordPress database (wp-env) and
 * skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Scanfully\Cron\Controller;

/**
 * Verifies the refresh lock is exclusive on a real MySQL options table.
 */
final class RefreshLockTest extends TestCase {

	private const LOCK = 'scanfully_refresh_lock';

	protected function setUp(): void {
		parent::setUp();

		if ( ! isset( $GLOBALS['wpdb'] ) || ! function_exists( 'delete_option' ) ) {
			$this->markTestSkipped( 'WordPress runtime not available. Run this suite under wp-env.' );
		}
		delete_option( self::LOCK );
	}

	protected function tearDown(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::LOCK );
		}
		parent::tearDown();
	}

	/**
	 * Call a private lock method.
	 *
	 * @param string $name Method name.
	 *
	 * @return mixed
	 */
	private function call( string $name ) {
		$method = new ReflectionMethod( Controller::class, $name );
		$method->setAccessible( true );
		return $method->invoke( null );
	}

	/**
	 * Read the lock row straight from the database.
	 *
	 * @return string|null
	 */
	private function lock_value(): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test inspects the raw lock row.
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
	}

	public function test_the_lock_is_exclusive_until_released(): void {
		$this->assertTrue( $this->call( 'acquire_refresh_lock' ) );
		$this->assertNotNull( $this->lock_value() );
		$this->assertFalse( $this->call( 'acquire_refresh_lock' ) );

		$this->call( 'release_refresh_lock' );

		$this->assertNull( $this->lock_value() );
		$this->assertTrue( $this->call( 'acquire_refresh_lock' ) );
	}

	public function test_an_abandoned_lock_is_taken_over(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test plants an old lock row.
		$wpdb->insert(
			$wpdb->options,
			[
				'option_name'  => self::LOCK,
				'option_value' => (string) ( time() - HOUR_IN_SECONDS ),
				'autoload'     => 'no',
			]
		);

		$this->assertTrue( $this->call( 'acquire_refresh_lock' ) );
		$this->assertGreaterThan( time() - MINUTE_IN_SECONDS, (int) $this->lock_value() );
	}
}
