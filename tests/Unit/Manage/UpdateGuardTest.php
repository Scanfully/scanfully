<?php
/**
 * Manage update guard unit tests: the guard of an update of the Scanfully
 * plugin itself, as the job puts it in place and removes it, and the guard
 * file itself, run against real folders as WordPress loads it.
 *
 * @package Scanfully\Tests\Unit\Manage
 */

namespace Scanfully\Tests\Unit\Manage;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Scanfully\Manage\UpdateGuard;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Manage\UpdateGuard
 */
final class UpdateGuardTest extends TestCase {

	private const JOB = 'a1a1a1a1-1111-4111-8111-111111111111';

	/**
	 * A folder for the must-use plugins.
	 *
	 * @var string
	 */
	private string $tmp;

	/**
	 * The plugin's slug in the plugins folder, unique per test.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * What the test made that tearDown removes.
	 *
	 * @var string[]
	 */
	private array $made = [];

	/**
	 * Network options, by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $site_options = [];

	/**
	 * Site options, by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * Whether the site is a multisite network.
	 *
	 * @var bool
	 */
	private bool $multisite = false;

	/**
	 * The callbacks the guard file added, by hook (the last one).
	 *
	 * @var array<string, callable>
	 */
	private array $hooks = [];

	/**
	 * Shutdown functions the class registered.
	 *
	 * @var callable[]
	 */
	private array $shutdown = [];

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
		}
		$this->tmp  = sys_get_temp_dir() . '/scanfully-guard-' . uniqid( '', true );
		$this->slug = 'guard-fixture-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tmp . '/mu', 0777, true );
		foreach ( [ WP_PLUGIN_DIR, WP_CONTENT_DIR . '/upgrade-temp-backup/plugins', WP_CONTENT_DIR . '/upgrade' ] as $dir ) {
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0777, true );
				$this->made[] = $dir;
			}
		}
		UpdateGuard::$dir         = $this->tmp . '/mu';
		UpdateGuard::$on_shutdown = function ( callable $fn ): void {
			$this->shutdown[] = $fn;
		};

		Functions\when( 'get_site_option' )->alias( fn( string $name, $default = false ) => $this->site_options[ $name ] ?? $default );
		Functions\when( 'update_site_option' )->alias(
			function ( string $name, $value ): bool {
				$this->site_options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_site_option' )->alias(
			function ( string $name ): bool {
				unset( $this->site_options[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'is_multisite' )->alias( fn() => $this->multisite );
		Functions\when( 'wp_delete_file' )->alias(
			static function ( string $file ): void {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
		);
		Functions\when( 'wp_mkdir_p' )->alias( static fn( string $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0777, true ) );
		Functions\when( 'wp_is_writable' )->alias( 'is_writable' );
		Functions\when( 'wp_normalize_path' )->alias( static fn( string $path ): string => str_replace( '\\', '/', $path ) );
		Functions\when( 'get_file_data' )->alias(
			static function ( string $file ): array {
				return [ 'Version' => preg_match( '/Version: (\S+)/', (string) file_get_contents( $file ), $m ) ? $m[1] : '' ];
			}
		);
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'esc_html' )->returnArg();
		foreach ( [ 'wp_loaded', 'admin_notices' ] as $hook ) {
			Actions\expectAdded( $hook )->zeroOrMoreTimes()->whenHappen(
				function ( callable $callback ) use ( $hook ): void {
					$this->hooks[ $hook ] = $callback;
				}
			);
		}
		Filters\expectAdded( 'wp_php_error_message' )->zeroOrMoreTimes()->whenHappen(
			function ( callable $callback ): void {
				$this->hooks['wp_php_error_message'] = $callback;
			}
		);
	}

	protected function tearDown(): void {
		UpdateGuard::$dir         = null;
		UpdateGuard::$on_shutdown = null;
		$this->remove( WP_PLUGIN_DIR . '/' . $this->slug );
		$this->remove( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/' . $this->slug );
		foreach ( (array) glob( WP_CONTENT_DIR . '/upgrade/' . $this->slug . '-*' ) as $left ) {
			$this->remove( (string) $left );
		}
		foreach ( array_reverse( $this->made ) as $dir ) {
			$this->remove( $dir );
		}
		if ( ABSPATH . 'wp-content' === WP_CONTENT_DIR && is_dir( WP_CONTENT_DIR ) && [ '.', '..' ] === scandir( WP_CONTENT_DIR ) ) {
			rmdir( WP_CONTENT_DIR );
		}
		$this->remove( $this->tmp );
		parent::tearDown();
	}

	/**
	 * Remove a folder and what is in it, never following a link.
	 *
	 * @param string $path The folder.
	 */
	private function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$this->remove( $path . '/' . $name );
			}
		}
		rmdir( $path );
	}

	private function basename(): string {
		return $this->slug . '/' . $this->slug . '.php';
	}

	/**
	 * Write the plugin's main file at a version, in the plugins folder or
	 * WordPress's backup of it.
	 *
	 * @param string $version The version.
	 * @param bool   $backup  Whether in the backup.
	 */
	private function write_plugin( string $version, bool $backup = false ): void {
		$dir = $backup ? WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/' . $this->slug : WP_PLUGIN_DIR . '/' . $this->slug;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $dir . '/' . $this->slug . '.php', "<?php\n/**\n * Plugin Name: Guard Fixture\n * Version: $version\n */\n" );
		file_put_contents( $dir . '/extra.php', "<?php\n// $version\n" );
	}

	private function version(): string {
		$file = WP_PLUGIN_DIR . '/' . $this->basename();
		return is_file( $file ) && preg_match( '/Version: (\S+)/', (string) file_get_contents( $file ), $m ) ? $m[1] : '';
	}

	/**
	 * The guard file in the must-use folder, with a state that is due.
	 *
	 * @param array $state Fields over the defaults.
	 */
	private function guard( array $state = [] ): void {
		copy( dirname( __DIR__, 3 ) . '/src/Manage/update-guard.php', UpdateGuard::path() );
		$this->site_options[ UpdateGuard::OPTION ] = array_merge(
			[
				'job'      => self::JOB,
				'basename' => $this->basename(),
				'previous' => '1.0.0',
				'target'   => '2.0.0',
				'network'  => false,
				'armed'    => time() - 30 * MINUTE_IN_SECONDS,
				'due'      => time() - MINUTE_IN_SECONDS,
			],
			$state
		);
	}

	/**
	 * Load the guard file, as WordPress loads a must-use plugin.
	 */
	private function load_guard(): void {
		$this->hooks = [];
		include UpdateGuard::path();
	}

	public function test_the_guard_is_written_whole_with_what_it_guards(): void {
		$this->assertNull( UpdateGuard::arm( self::JOB, 'scanfully/scanfully.php', '1.10.0', '1.12.0', false ) );

		$this->assertFileEquals( dirname( __DIR__, 3 ) . '/src/Manage/update-guard.php', UpdateGuard::path() );
		$this->assertSame( [ '0-scanfully-update-guard.php' ], array_values( array_diff( (array) scandir( $this->tmp . '/mu' ), [ '.', '..' ] ) ), 'no half-written copy left' );
		$state = $this->site_options[ UpdateGuard::OPTION ];
		$this->assertSame( self::JOB, $state['job'] );
		$this->assertSame( 'scanfully/scanfully.php', $state['basename'] );
		$this->assertSame( '1.10.0', $state['previous'] );
		$this->assertEqualsWithDelta( time() + 20 * MINUTE_IN_SECONDS, $state['due'], 2 );
		$this->assertCount( 1, $this->shutdown );
		$this->assertArrayHasKey( 'wp_php_error_message', $this->hooks );
	}

	public function test_the_guard_is_due_soon_after_the_job_s_request_ends_or_php_stops(): void {
		UpdateGuard::arm( self::JOB, 'scanfully/scanfully.php', '1.10.0', '1.12.0', false );

		$this->assertSame( 'message', call_user_func( $this->hooks['wp_php_error_message'], 'message' ) );
		$this->assertEqualsWithDelta( time() + MINUTE_IN_SECONDS, $this->site_options[ UpdateGuard::OPTION ]['due'], 2 );

		$this->site_options[ UpdateGuard::OPTION ]['due'] = time() + 10;
		call_user_func( $this->shutdown[0] );
		$this->assertEqualsWithDelta( time() + 10, $this->site_options[ UpdateGuard::OPTION ]['due'], 2, 'an earlier due time stays' );

		$this->site_options[ UpdateGuard::OPTION ]['job'] = 'b2b2b2b2-2222-4222-8222-222222222222';
		$this->site_options[ UpdateGuard::OPTION ]['due'] = time() + 900;
		UpdateGuard::due_soon( self::JOB );
		$this->assertEqualsWithDelta( time() + 900, $this->site_options[ UpdateGuard::OPTION ]['due'], 2, 'another job\'s guard is left alone' );
	}

	public function test_no_guard_without_a_writable_must_use_folder(): void {
		Functions\when( 'wp_is_writable' )->justReturn( false );

		$this->assertSame( 'the must-use plugins folder is not writable', UpdateGuard::arm( self::JOB, 'scanfully/scanfully.php', '1.10.0', '1.12.0', false ) );
		$this->assertFileDoesNotExist( UpdateGuard::path() );
		$this->assertArrayNotHasKey( UpdateGuard::OPTION, $this->site_options );
	}

	public function test_release_removes_its_own_job_s_guard_only(): void {
		UpdateGuard::arm( self::JOB, 'scanfully/scanfully.php', '1.10.0', '1.12.0', false );

		UpdateGuard::release( 'b2b2b2b2-2222-4222-8222-222222222222' );
		$this->assertFileExists( UpdateGuard::path() );

		UpdateGuard::release( self::JOB );
		$this->assertFileDoesNotExist( UpdateGuard::path() );
		$this->assertArrayNotHasKey( UpdateGuard::OPTION, $this->site_options );

		file_put_contents( UpdateGuard::path(), "<?php\n// Someone else's file.\n" );
		UpdateGuard::release( self::JOB );
		$this->assertFileExists( UpdateGuard::path(), 'a file that is not Scanfully\'s stays' );
	}

	public function test_the_note_is_the_job_s_until_it_is_forgotten(): void {
		$this->site_options[ UpdateGuard::NOTE_OPTION ] = [
			'job'  => self::JOB,
			'at'   => time(),
			'text' => 'Scanfully\'s update guard put version 1.10.0 back on the site.',
		];

		$this->assertNull( UpdateGuard::note( 'b2b2b2b2-2222-4222-8222-222222222222' ) );
		$this->assertSame( 'Scanfully\'s update guard put version 1.10.0 back on the site.', UpdateGuard::note( self::JOB ) );
		UpdateGuard::forget_note( self::JOB );
		$this->assertNull( UpdateGuard::note( self::JOB ) );
	}

	public function test_a_copy_left_behind_removes_itself(): void {
		copy( dirname( __DIR__, 3 ) . '/src/Manage/update-guard.php', UpdateGuard::path() );

		$this->load_guard();

		$this->assertFileDoesNotExist( UpdateGuard::path() );
	}

	public function test_nothing_happens_while_the_update_may_still_run(): void {
		$this->write_plugin( '2.0.0' );
		$this->write_plugin( '1.0.0', true );
		$this->guard( [ 'due' => time() + 300 ] );

		$this->load_guard();

		$this->assertSame( '2.0.0', $this->version() );
		$this->assertSame( [], $this->hooks );
		$this->assertFileExists( UpdateGuard::path() );
	}

	public function test_an_unfinished_update_gets_the_version_before_it_back_and_the_plugin_on(): void {
		$this->write_plugin( '2.0.0' );
		unlink( WP_PLUGIN_DIR . '/' . $this->basename() );
		$this->write_plugin( '1.0.0', true );
		$this->options['active_plugins'] = [ 'akismet/akismet.php' ];
		$this->guard();

		$this->load_guard();

		$this->assertSame( '1.0.0', $this->version(), 'the backup is back' );
		$this->assertStringContainsString( '1.0.0', (string) file_get_contents( WP_PLUGIN_DIR . '/' . $this->slug . '/extra.php' ), 'whole, not mixed with the new files' );
		$this->assertDirectoryDoesNotExist( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/' . $this->slug );
		$this->assertSame( [], (array) glob( WP_CONTENT_DIR . '/upgrade/' . $this->slug . '-*' ), 'the unfinished files are gone' );
		$this->assertSame( [ 'akismet/akismet.php', $this->basename() ], $this->options['active_plugins'] );
		$this->assertSame( '1.0.0', $this->site_options[ UpdateGuard::OPTION ]['restored'] );

		call_user_func( $this->hooks['wp_loaded'] );
		$this->assertSame( 'Scanfully\'s update guard put version 1.0.0 back on the site and switched the plugin on again.', UpdateGuard::note( self::JOB ) );
		$this->assertArrayNotHasKey( UpdateGuard::OPTION, $this->site_options );
		$this->assertFileDoesNotExist( UpdateGuard::path(), 'the guard goes once Scanfully runs' );
	}

	public function test_a_backup_of_another_version_is_not_this_update_s(): void {
		$this->write_plugin( '1.0.0' );
		$this->write_plugin( '0.9.0', true );
		$this->options['active_plugins'] = [ $this->basename() ];
		$this->guard();

		$this->load_guard();

		$this->assertSame( '1.0.0', $this->version() );
		$this->assertDirectoryExists( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/' . $this->slug );
		call_user_func( $this->hooks['wp_loaded'] );
		$this->assertNull( UpdateGuard::note( self::JOB ), 'nothing to say' );
		$this->assertFileDoesNotExist( UpdateGuard::path() );
	}

	public function test_a_plugin_left_off_is_switched_on_for_the_whole_network(): void {
		$this->multisite = true;
		$this->write_plugin( '2.0.0' );
		$this->site_options['active_sitewide_plugins'] = [ 'akismet/akismet.php' => 1 ];
		$this->guard( [ 'network' => true ] );

		$this->load_guard();

		$this->assertArrayHasKey( $this->basename(), $this->site_options['active_sitewide_plugins'] );
		$this->assertArrayHasKey( 'akismet/akismet.php', $this->site_options['active_sitewide_plugins'] );
		call_user_func( $this->hooks['wp_loaded'] );
		$this->assertSame( 'Scanfully\'s update guard switched the plugin on again, at version 2.0.0.', UpdateGuard::note( self::JOB ) );
	}

	public function test_a_php_error_in_the_plugin_s_files_switches_it_off_for_good(): void {
		$this->write_plugin( '2.0.0' );
		$this->options['active_plugins'] = [];
		$this->guard();
		$this->load_guard();
		$this->assertSame( [ $this->basename() ], $this->options['active_plugins'] );

		$error = [
			'type'    => E_ERROR,
			'message' => 'Uncaught Error: Call to undefined function scanfully_missing()',
			'file'    => WP_PLUGIN_DIR . '/' . $this->slug . '/extra.php',
			'line'    => 3,
		];
		$this->assertSame( 'page', call_user_func( $this->hooks['wp_php_error_message'], 'page', $error ) );

		$this->assertSame( [], $this->options['active_plugins'] );
		$this->assertSame( 'Uncaught Error: Call to undefined function scanfully_missing()', $this->site_options[ UpdateGuard::OPTION ]['gave_up'] );

		$this->load_guard();
		$this->assertSame( [], $this->options['active_plugins'], 'not switched on again' );
		ob_start();
		call_user_func( $this->hooks['admin_notices'] );
		$this->assertStringContainsString( 'stopped with a PHP error after its update through Scanfully, so it was switched off again: Uncaught Error', (string) ob_get_clean() );
		$this->assertFileExists( UpdateGuard::path(), 'stays to say so' );
	}

	public function test_a_php_error_elsewhere_leaves_the_plugin_on(): void {
		$this->write_plugin( '2.0.0' );
		$this->options['active_plugins'] = [];
		$this->guard();
		$this->load_guard();

		call_user_func(
			$this->hooks['wp_php_error_message'],
			'page',
			[
				'type'    => E_ERROR,
				'message' => 'Another plugin broke.',
				'file'    => WP_PLUGIN_DIR . '/another/another.php',
				'line'    => 1,
			]
		);

		$this->assertSame( [ $this->basename() ], $this->options['active_plugins'] );
		$this->assertArrayNotHasKey( 'gave_up', $this->site_options[ UpdateGuard::OPTION ] );
	}

	public function test_no_files_and_no_backup_say_so_in_wp_admin(): void {
		$this->guard();

		$this->load_guard();

		$this->assertTrue( $this->site_options[ UpdateGuard::OPTION ]['missing'] );
		ob_start();
		call_user_func( $this->hooks['admin_notices'] );
		$this->assertStringContainsString( 'did not finish, and the plugin\'s files are missing', (string) ob_get_clean() );
	}

	public function test_the_guard_goes_a_week_after_the_update(): void {
		$this->guard( [ 'due' => time() - 8 * DAY_IN_SECONDS ] );

		$this->load_guard();

		$this->assertFileDoesNotExist( UpdateGuard::path() );
		$this->assertArrayNotHasKey( UpdateGuard::OPTION, $this->site_options );
	}
}
