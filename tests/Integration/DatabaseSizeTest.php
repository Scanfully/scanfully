<?php
/**
 * Database size and user count integration tests.
 *
 * Runs against the real WordPress database (wp-env) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Scanfully\Health\Controller;

/**
 * Verifies the database size covers only this install's tables.
 */
final class DatabaseSizeTest extends TestCase {

	private const FOREIGN_TABLE = 'otherinstall_scanfully_test';

	protected function setUp(): void {
		parent::setUp();

		if ( ! isset( $GLOBALS['wpdb'] ) || ! function_exists( 'get_user_count' ) ) {
			$this->markTestSkipped( 'WordPress runtime not available. Run this suite under wp-env.' );
		}
	}

	protected function tearDown(): void {
		if ( isset( $GLOBALS['wpdb'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Test cleanup of its own fixed-name table.
			$GLOBALS['wpdb']->query( 'DROP TABLE IF EXISTS ' . self::FOREIGN_TABLE );
		}
		parent::tearDown();
	}

	/**
	 * The database size the plugin reports.
	 *
	 * @return int
	 */
	private function db_size(): int {
		$method = new ReflectionMethod( Controller::class, 'get_db_size' );
		$method->setAccessible( true );
		return $method->invoke( null );
	}

	public function test_tables_of_another_install_in_the_same_database_are_not_counted(): void {
		global $wpdb;
		$before = $this->db_size();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Creates a table with a fixed foreign-prefix name; table names can't be placeholders.
		$wpdb->query( 'CREATE TABLE ' . self::FOREIGN_TABLE . ' ( id INT PRIMARY KEY, payload TEXT )' );

		$this->assertGreaterThan( 0, $before );
		$this->assertSame( $before, $this->db_size() );
	}

	public function test_the_user_count_matches_wordpress(): void {
		$data = Controller::get_site_data();
		$json = (string) wp_json_encode( $data );

		$this->assertStringContainsString( '"user_count":' . count_users()['total_users'], $json );
	}
}
