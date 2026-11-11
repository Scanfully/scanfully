<?php
/**
 * Capabilities unit tests: what the site tells Scanfully it can do.
 *
 * @package Scanfully\Tests\Unit\Health
 */

namespace Scanfully\Tests\Unit\Health;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use ReflectionMethod;
use Scanfully\Health\Capabilities;
use Scanfully\Options\Controller as OptionsController;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Health\Capabilities
 */
final class CapabilitiesTest extends TestCase {

	/**
	 * A temporary folder.
	 *
	 * @var string
	 */
	private $dir;

	protected function set_up(): void {
		parent::set_up();

		$this->dir = sys_get_temp_dir() . '/scanfully-capabilities-' . uniqid( '', true );
		mkdir( $this->dir );
		unset( $GLOBALS['scanfully_test_action_scheduler'] );

		Functions\when( 'wp_is_file_mod_allowed' )->justReturn( true );
		Functions\when( 'get_filesystem_method' )->justReturn( 'direct' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );
		Functions\when( 'wp_is_writable' )->alias( 'is_writable' );
		Functions\when( 'wp_is_using_https' )->justReturn( false );
		Functions\when( 'get_users' )->justReturn( [] );
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/scanfully-capabilities-content' );
		}
	}

	protected function tear_down(): void {
		unset( $GLOBALS['scanfully_test_action_scheduler'] );
		$this->remove( $this->dir );
		parent::tear_down();
	}

	/**
	 * Remove a folder and what is in it.
	 *
	 * @param string $dir The folder.
	 *
	 * @return void
	 */
	private function remove( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			is_dir( $path ) ? $this->remove( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	public function test_a_site_that_can_do_everything(): void {
		$this->assertSame(
			[
				'plugin_version' => SCANFULLY_VERSION,
				'actions' => [ 'ping', 'update_plugin', 'update_theme', 'update_core', 'update_translations', 'install_plugin', 'install_theme', 'install_plugin_upload', 'install_theme_upload', 'activate_plugin', 'deactivate_plugin', 'switch_theme', 'delete_plugin', 'delete_theme' ],
				'file_mods_allowed' => true,
				'auto_updater_enabled' => true,
				'fs_method' => 'direct',
				'multisite' => false,
				'connection_kind' => 'single',
				'action_scheduler' => true,
				'administrators' => [],
			],
			array_diff_key( Capabilities::get(), [ 'mu_plugin_writable' => true ] )
		);
		$this->assertIsBool( Capabilities::get()['mu_plugin_writable'] );
	}

	public function test_the_actions_key_is_a_list_even_when_empty(): void {
		$capabilities = Capabilities::get();

		$this->assertArrayHasKey( 'actions', $capabilities );
		$this->assertIsArray( $capabilities['actions'] );
		$this->assertSame( array_values( $capabilities['actions'] ), $capabilities['actions'] );
	}

	public function test_disallowed_file_mods_also_stop_the_automatic_updater(): void {
		Functions\when( 'wp_is_file_mod_allowed' )->justReturn( false );

		$capabilities = Capabilities::get();

		$this->assertFalse( $capabilities['file_mods_allowed'] );
		$this->assertFalse( $capabilities['auto_updater_enabled'] );
		$this->assertSame( [ 'ping', 'activate_plugin', 'deactivate_plugin', 'switch_theme' ], $capabilities['actions'], 'no update action where WordPress may not change files' );
	}

	public function test_the_core_filter_can_turn_the_automatic_updater_off(): void {
		Filters\expectApplied( 'automatic_updater_disabled' )->once()->with( false )->andReturn( true );

		$capabilities = Capabilities::get();

		$this->assertTrue( $capabilities['file_mods_allowed'] );
		$this->assertFalse( $capabilities['auto_updater_enabled'] );
	}

	public function test_a_method_that_needs_credentials_is_reported_as_it_is(): void {
		Functions\when( 'get_filesystem_method' )->justReturn( 'ftpext' );

		$this->assertSame( 'ftpext', Capabilities::get()['fs_method'] );
		$this->assertSame( [ 'ping', 'activate_plugin', 'deactivate_plugin', 'switch_theme' ], Capabilities::get()['actions'], 'no update action while WordPress would have to ask for FTP credentials' );
	}

	public function test_a_method_is_usable_when_it_needs_no_one_to_type_credentials(): void {
		$this->assertTrue( Capabilities::usable_fs_method( 'direct' ) );
		$this->assertFalse( Capabilities::usable_fs_method( 'ftpext' ), 'FTP without FTP_HOST and FTP_USER in wp-config.php' );
		$this->assertFalse( Capabilities::usable_fs_method( null ) );
		$this->assertFalse( Capabilities::usable_fs_method( 'carrier-pigeon' ) );
	}

	public function test_no_filesystem_method_is_null(): void {
		Functions\when( 'get_filesystem_method' )->justReturn( false );

		$this->assertNull( Capabilities::get()['fs_method'] );
		$this->assertSame( [ 'ping', 'activate_plugin', 'deactivate_plugin', 'switch_theme' ], Capabilities::get()['actions'], 'no update action when WordPress names no method' );
	}

	public function test_a_network_is_monitoring_only(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		$this->assertTrue( Capabilities::get()['multisite'] );
		$this->assertSame( [], Capabilities::get()['actions'], 'a connection on a network runs no job' );
	}

	public function test_action_scheduler_before_its_data_store_is_ready(): void {
		$GLOBALS['scanfully_test_action_scheduler']['initialized'] = false;

		$this->assertFalse( Capabilities::get()['action_scheduler'] );
	}

	public function test_a_check_that_throws_is_unknown_and_the_others_still_answer(): void {
		Functions\when( 'get_filesystem_method' )->alias(
			static function () {
				throw new \RuntimeException( 'open_basedir' );
			}
		);

		$capabilities = Capabilities::get();

		$this->assertNull( $capabilities['fs_method'] );
		$this->assertTrue( $capabilities['file_mods_allowed'] );
		$this->assertFalse( $capabilities['multisite'] );
	}

	/**
	 * Whether a file can be written to the folder.
	 *
	 * @param string $dir The folder.
	 *
	 * @return bool
	 */
	private function can_write_folder( string $dir ): bool {
		$method = new ReflectionMethod( Capabilities::class, 'can_write_folder' );
		$method->setAccessible( true );

		return $method->invoke( null, $dir );
	}

	public function test_a_writable_mu_plugins_folder(): void {
		mkdir( $this->dir . '/mu-plugins' );

		$this->assertTrue( $this->can_write_folder( $this->dir . '/mu-plugins' ) );
	}

	public function test_a_read_only_mu_plugins_folder(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'root can write to any folder' );
		}
		$mu = $this->dir . '/mu-plugins';
		mkdir( $mu );
		chmod( $mu, 0555 );

		try {
			$this->assertFalse( $this->can_write_folder( $mu ) );
		} finally {
			chmod( $mu, 0755 );
		}
	}

	public function test_a_missing_mu_plugins_folder_can_be_made_in_a_writable_folder(): void {
		$this->assertTrue( $this->can_write_folder( $this->dir . '/mu-plugins' ) );
	}

	public function test_a_missing_mu_plugins_folder_in_a_read_only_folder(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'root can write to any folder' );
		}
		chmod( $this->dir, 0555 );

		try {
			$this->assertFalse( $this->can_write_folder( $this->dir . '/mu-plugins' ) );
		} finally {
			chmod( $this->dir, 0755 );
		}
	}

	public function test_a_reader_can_skip_the_check(): void {
		Functions\expect( 'get_filesystem_method' )->never();

		$this->assertSame( [], Capabilities::skip( [ Capabilities::class, 'get' ] ) );
	}

	/**
	 * Connect the site with these scopes.
	 *
	 * @param string $scopes The scopes, space separated.
	 *
	 * @return void
	 */
	private function connect( string $scopes ): void {
		$options = [
			'scanfully_connect_is_connected' => 'yes',
			'scanfully_connect_site_id'      => 'site-1',
			'scanfully_connect_flow'         => 'oauth',
			'scanfully_connect_scopes'       => $scopes,
		];
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $fallback = false ) use ( $options ) {
				return $options[ $name ] ?? $fallback;
			}
		);
	}

	public function test_a_site_that_can_sign_in_names_login_among_the_actions(): void {
		$this->connect( 'wordpress:read wordpress:manage wordpress:login' );
		Functions\when( 'wp_is_using_https' )->justReturn( true );

		$actions = Capabilities::get()['actions'];

		$this->assertSame( 'login', end( $actions ) );
		$this->assertSame( array_values( $actions ), $actions, 'still a list' );
	}

	public function test_login_needs_https_and_the_scope(): void {
		$this->connect( 'wordpress:read wordpress:login' );
		$this->assertNotContains( 'login', Capabilities::get()['actions'], 'a site served over plain HTTP' );

		Functions\when( 'wp_is_using_https' )->justReturn( true );
		$this->connect( 'wordpress:read wordpress:manage' );
		$this->assertNotContains( 'login', Capabilities::get()['actions'], 'without wordpress:login in the grant' );
	}

	public function test_a_network_offers_no_job_and_no_login_whatever_the_grant(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_super_admins' )->justReturn( [ 'admin' ] );
		Functions\when( 'wp_is_using_https' )->justReturn( true );
		$this->connect( 'wordpress:read wordpress:manage wordpress:login' );

		$capabilities = Capabilities::get();
		$this->assertSame( [], $capabilities['actions'], 'neither the jobs nor login' );
		$this->assertSame( [], $capabilities['administrators'], 'no one to sign in as' );
	}

	public function test_the_network_connection_runs_the_network_actions_and_signs_in_super_admins(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_super_admins' )->justReturn( [ 'admin' ] );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'wp_is_using_https' )->justReturn( true );
		$this->network_connect( 'wordpress:read wordpress:manage wordpress:login' );

		$capabilities = OptionsController::with_scope( true, [ Capabilities::class, 'get' ] );
		$this->assertSame( 'network', $capabilities['connection_kind'] );
		$this->assertSame(
			[ 'ping', 'update_plugin', 'update_theme', 'update_core', 'update_translations', 'install_plugin', 'install_theme', 'install_plugin_upload', 'install_theme_upload', 'delete_plugin', 'delete_theme', 'login' ],
			$capabilities['actions'],
			'activating, deactivating and switching themes act per site: not on a network'
		);
		$this->assertSame( 'subsite', Capabilities::get()['connection_kind'], 'any other connection on a network' );
	}

	/**
	 * Stub the network's options as a connected network with the scopes.
	 *
	 * @param string $scopes The granted scopes.
	 */
	private function network_connect( string $scopes ): void {
		$options = [
			'scanfully_connect_is_connected' => 'yes',
			'scanfully_connect_site_id'      => 'network-1',
			'scanfully_connect_flow'         => 'oauth',
			'scanfully_connect_scopes'       => $scopes,
		];
		Functions\when( 'get_site_option' )->alias(
			static function ( string $name, $fallback = false ) use ( $options ) {
				return $options[ $name ] ?? $fallback;
			}
		);
		Functions\when( 'get_option' )->justReturn( false );
	}

	public function test_administrators_that_cannot_be_read_are_unknown(): void {
		Functions\when( 'get_users' )->alias(
			static function () {
				throw new \RuntimeException( 'no database' );
			}
		);

		$this->assertNull( Capabilities::get()['administrators'] );
	}
}
