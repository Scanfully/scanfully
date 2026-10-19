<?php
/**
 * MU plugins and drop-ins integration tests.
 *
 * Runs against real WordPress (wp-env) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Cron\Controller as CronController;
use Scanfully\Health\Controller as HealthController;
use Scanfully\Health\MuPlugins;

/**
 * Writes MU plugins, a folder and a drop-in into the site and checks what the
 * site data sync sends, and that the hourly check starts a sync on a change.
 */
final class MuPluginsTest extends TestCase {

	private const FILE = 'scanfully-test-mu.php';

	private const FOLDER = 'scanfully-test-mu';

	private const DROPIN = 'db-error.php';

	/**
	 * Whether the MU plugins folder existed before the test.
	 *
	 * @var bool
	 */
	private bool $had_mu_dir = true;

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			$this->markTestSkipped( 'WordPress not available. Run this suite under wp-env.' );
		}
		if ( file_exists( WP_CONTENT_DIR . '/' . self::DROPIN ) ) {
			$this->markTestSkipped( 'The site has a db-error.php drop-in of its own.' );
		}

		$this->had_mu_dir = is_dir( WPMU_PLUGIN_DIR );
		if ( ! $this->had_mu_dir ) {
			mkdir( WPMU_PLUGIN_DIR, 0777, true );
		}
		update_option( 'scanfully_connect_is_connected', 'yes' );
		as_unschedule_all_actions( '', [], 'scanfully' );
		delete_option( MuPlugins::HASH_OPTION );
		delete_option( MuPlugins::STAT_OPTION );
	}

	protected function tearDown(): void {
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			foreach ( [ WPMU_PLUGIN_DIR . '/' . self::FILE, WPMU_PLUGIN_DIR . '/' . self::FOLDER . '/includes/admin.php', WPMU_PLUGIN_DIR . '/' . self::FOLDER . '/main.php', WP_CONTENT_DIR . '/' . self::DROPIN ] as $file ) {
				if ( file_exists( $file ) ) {
					unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
				}
			}
			foreach ( [ WPMU_PLUGIN_DIR . '/' . self::FOLDER . '/includes', WPMU_PLUGIN_DIR . '/' . self::FOLDER ] as $dir ) {
				if ( is_dir( $dir ) ) {
					rmdir( $dir );
				}
			}
			if ( ! $this->had_mu_dir && is_dir( WPMU_PLUGIN_DIR ) ) {
				rmdir( WPMU_PLUGIN_DIR );
			}
			delete_option( MuPlugins::HASH_OPTION );
			delete_option( MuPlugins::STAT_OPTION );
			as_unschedule_all_actions( '', [], 'scanfully' );
			delete_option( 'scanfully_connect_is_connected' );
		}
		parent::tearDown();
	}

	/**
	 * Write a file, creating its folder.
	 *
	 * @param string $path    The full path.
	 * @param string $content The content.
	 *
	 * @return void
	 */
	private function write( string $path, string $content ): void {
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		clearstatcache();
	}

	/**
	 * The MU plugin entries of the site data, by kind and file.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function sent(): array {
		$out = [];
		foreach ( HealthController::get_site_data()['mu_plugins'] as $entry ) {
			$out[ $entry['kind'] . ':' . $entry['file'] ] = $entry;
		}

		return $out;
	}

	public function test_the_site_data_has_the_mu_plugins_their_folders_and_drop_ins(): void {
		$plugin = "<?php\n/**\n * Plugin Name: Scanfully Test MU\n * Version: 1.4.0\n * Author: Scanfully\n */\n";
		$this->write( WPMU_PLUGIN_DIR . '/' . self::FILE, $plugin );
		$this->write( WPMU_PLUGIN_DIR . '/' . self::FOLDER . '/main.php', '<?php // main' );
		$this->write( WPMU_PLUGIN_DIR . '/' . self::FOLDER . '/includes/admin.php', '<?php // admin' );
		$this->write( WP_CONTENT_DIR . '/' . self::DROPIN, "<?php\n/**\n * Plugin Name: Test DB Error\n */\n" );

		$sent = $this->sent();

		$file = $sent[ 'mu:' . self::FILE ] ?? [];
		$this->assertSame( 'Scanfully Test MU', $file['name'] ?? null );
		$this->assertSame( '1.4.0', $file['version'] ?? null );
		$this->assertSame( 'sha256:' . hash( 'sha256', $plugin ), $file['hash'] ?? null );

		$folder = $sent[ 'mu_folder:' . self::FOLDER ] ?? [];
		$this->assertSame( 2, $folder['files'] ?? null );
		$this->assertFalse( $folder['partial'] ?? true );

		$this->assertSame( 'Test DB Error', $sent[ 'dropin:' . self::DROPIN ]['name'] ?? null );
	}

	public function test_the_hourly_check_syncs_only_when_something_changed(): void {
		$this->write( WPMU_PLUGIN_DIR . '/' . self::FILE, '<?php // one' );
		$this->sent();

		CronController::check_mu_plugins();
		$this->assertSame( [], $this->debounced_syncs(), 'Nothing changed since the sync.' );

		$this->write( WPMU_PLUGIN_DIR . '/' . self::FOLDER . '/main.php', '<?php // new folder' );
		CronController::check_mu_plugins();

		$this->assertCount( 1, $this->debounced_syncs() );
	}

	public function test_the_hourly_check_does_nothing_before_the_first_sync(): void {
		$this->write( WPMU_PLUGIN_DIR . '/' . self::FILE, '<?php // one' );

		CronController::check_mu_plugins();

		$this->assertSame( [], $this->debounced_syncs() );
	}

	/**
	 * The pending debounced site data syncs.
	 *
	 * @return array<int, int>
	 */
	private function debounced_syncs(): array {
		return as_get_scheduled_actions(
			[
				'hook'     => CronController::ACTION_SYNC_SITE_HEALTH,
				'args'     => [ 'trigger' => 'debounced' ],
				'group'    => 'scanfully',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			],
			'ids'
		);
	}
}
