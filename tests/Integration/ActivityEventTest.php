<?php
/**
 * Activity event integration tests: users, settings, themes, plugin installs
 * and deletions, failed updates.
 *
 * Runs the real WordPress hooks (wp-env) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Events\Controller as EventsController;

/**
 * Verifies which WordPress changes queue which activity events.
 */
final class ActivityEventTest extends TestCase {

	/**
	 * Users created by a test.
	 *
	 * @var array<int, int>
	 */
	private array $users = [];

	/**
	 * Options to restore after a test, with their value before it.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'WordPress not available. Run this suite under wp-env.' );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		update_option( 'scanfully_connect_is_connected', 'yes' );
		delete_transient( 'scanfully_user_events_rate' );
		as_unschedule_all_actions( '', [], 'scanfully' );
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( $this->users as $id ) {
				wp_delete_user( $id );
			}
			foreach ( $this->options as $option => $value ) {
				update_option( $option, $value );
			}
			wp_set_current_user( 0 );
			as_unschedule_all_actions( '', [], 'scanfully' );
			delete_option( 'scanfully_connect_is_connected' );
		}
		parent::tearDown();
	}

	/**
	 * The events queued so far, oldest first, as [type, data] pairs.
	 *
	 * @return array<int, array{string, array<string, mixed>}>
	 */
	private function queued(): array {
		$actions = as_get_scheduled_actions(
			[
				'hook'     => EventsController::ACTION_SEND_EVENT,
				'group'    => 'scanfully',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			]
		);

		$events = [];
		foreach ( $actions as $action ) {
			$args     = $action->get_args();
			$events[] = [ (string) $args['type'], (array) $args['data'] ];
		}

		return $events;
	}

	/**
	 * The types of the events queued so far.
	 *
	 * @return array<int, string>
	 */
	private function queued_types(): array {
		return array_column( $this->queued(), 0 );
	}

	/**
	 * Remember an option's value so tearDown restores it.
	 *
	 * @param string $option The option.
	 */
	private function restore_later( string $option ): void {
		if ( ! array_key_exists( $option, $this->options ) ) {
			$this->options[ $option ] = get_option( $option );
		}
	}

	/**
	 * Create a user.
	 *
	 * @param string $role The role.
	 *
	 * @return int
	 */
	private function create_user( string $role ): int {
		$login = 'scanfully-' . wp_generate_password( 8, false );
		$id    = wp_insert_user(
			[
				'user_login'   => $login,
				'user_email'   => $login . '@scanfully.com',
				'user_pass'    => wp_generate_password(),
				'display_name' => 'Scanfully Test',
				'role'         => $role,
			]
		);
		$this->assertIsInt( $id );
		$this->users[] = $id;

		return $id;
	}

	public function test_creating_a_user_is_one_event_without_a_role_change(): void {
		$id = $this->create_user( 'editor' );

		$this->assertSame(
			[
				[
					'UserCreated',
					[
						'id'    => $id,
						'name'  => 'Scanfully Test',
						'roles' => [ 'editor' ],
					],
				],
			],
			$this->queued()
		);
	}

	public function test_a_visitor_signing_up_is_not_reported(): void {
		wp_set_current_user( 0 );

		$this->create_user( (string) get_option( 'default_role' ) );

		$this->assertSame( [], $this->queued_types() );
	}

	public function test_a_sign_up_as_administrator_is_reported_when_that_is_the_default_role(): void {
		$this->restore_later( 'default_role' );
		update_option( 'default_role', 'administrator' );
		as_unschedule_all_actions( '', [], 'scanfully' );
		wp_set_current_user( 0 );

		$this->create_user( 'administrator' );

		$this->assertSame( [ 'UserCreated' ], $this->queued_types() );
	}

	public function test_a_role_another_plugin_sets_on_sign_up_is_not_a_role_change(): void {
		wp_set_current_user( 0 );
		if ( ! get_role( 'customer' ) ) {
			$this->markTestSkipped( 'Needs the WooCommerce customer role.' );
		}
		$set_role = static function ( $user_id ) {
			( new \WP_User( $user_id ) )->set_role( 'customer' );
		};
		add_action( 'user_register', $set_role, 20 );

		$this->create_user( 'subscriber' );
		remove_action( 'user_register', $set_role, 20 );

		$this->assertSame( [], $this->queued_types() );
	}

	public function test_changing_and_deleting_a_user_is_reported(): void {
		$id = $this->create_user( 'editor' );
		as_unschedule_all_actions( '', [], 'scanfully' );

		wp_update_user(
			[
				'ID'   => $id,
				'role' => 'administrator',
			]
		);
		wp_delete_user( $id );
		$this->users = [];

		$events = $this->queued();
		$this->assertSame( [ 'UserRoleChanged', 'UserDeleted' ], array_column( $events, 0 ) );
		$this->assertSame( [ 'administrator' ], $events[0][1]['roles'] );
		$this->assertSame( [ 'editor' ], $events[0][1]['old_roles'] );
		$this->assertSame( [ 'administrator' ], $events[1][1]['roles'] );

		$encoded = (string) wp_json_encode( $events );
		$this->assertStringNotContainsString( '@scanfully.com', $encoded, 'Never the email address.' );
		$this->assertStringNotContainsString( 'scanfully-', $encoded, 'Never the login.' );
	}

	public function test_a_key_setting_change_is_reported_and_a_type_only_change_is_not(): void {
		$this->restore_later( 'blog_public' );
		$this->restore_later( 'users_can_register' );
		update_option( 'blog_public', '1' );
		update_option( 'users_can_register', '0' );
		as_unschedule_all_actions( '', [], 'scanfully' );

		// What the settings pages save.
		update_option( 'users_can_register', sanitize_option( 'users_can_register', null ) );
		update_option( 'blog_public', sanitize_option( 'blog_public', '0' ) );

		$this->assertSame(
			[
				[
					'SiteOptionChanged',
					[
						'option' => 'blog_public',
						'old'    => '1',
						'new'    => '0',
					],
				],
			],
			$this->queued()
		);
	}

	public function test_a_permalink_change_is_reported_and_rewrite_flushes_are_not(): void {
		$this->restore_later( 'permalink_structure' );
		$old = (string) get_option( 'permalink_structure' );
		$new = '/scanfully-test/%postname%/' === $old ? '/%postname%/' : '/scanfully-test/%postname%/';

		update_option( 'permalink_structure', $new );
		flush_rewrite_rules();

		$this->assertSame(
			[
				[
					'PermalinksChanged',
					[
						'old' => $old,
						'new' => $new,
					],
				],
			],
			$this->queued()
		);
	}

	public function test_switching_the_theme_is_reported(): void {
		$old = wp_get_theme();
		$new = null;
		foreach ( wp_get_themes() as $theme ) {
			if ( $theme->get_stylesheet() !== $old->get_stylesheet() && ! $theme->parent() ) {
				$new = $theme;
				break;
			}
		}
		if ( null === $new ) {
			$this->markTestSkipped( 'Needs a second theme.' );
		}

		switch_theme( $new->get_stylesheet() );
		$events = $this->queued();
		switch_theme( $old->get_stylesheet() );

		$this->assertSame(
			[
				[
					'ThemeSwitched',
					[
						'name'     => $new->get( 'Name' ),
						'slug'     => $new->get_stylesheet(),
						'old_name' => $old->get( 'Name' ),
						'old_slug' => $old->get_stylesheet(),
					],
				],
			],
			$events
		);
	}

	public function test_installing_and_deleting_a_plugin_is_reported(): void {
		if ( ! class_exists( \ZipArchive::class ) || 'direct' !== get_filesystem_method() ) {
			$this->markTestSkipped( 'Needs ZipArchive and direct file system access.' );
		}

		$slug = 'scanfully-test-' . strtolower( wp_generate_password( 6, false ) );
		$zip  = get_temp_dir() . $slug . '.zip';
		$pack = new \ZipArchive();
		$pack->open( $zip, \ZipArchive::CREATE );
		$pack->addFromString( $slug . '/' . $slug . '.php', "<?php\n/**\n * Plugin Name: Scanfully Test Plugin\n * Version: 1.2.3\n * Author: Scanfully\n */\n" );
		$pack->close();

		WP_Filesystem();
		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$this->assertTrue( $upgrader->install( $zip ) );
		wp_delete_file( $zip );

		$plugin = $slug . '/' . $slug . '.php';
		$this->assertSame( [ true ], array_values( array_unique( (array) delete_plugins( [ $plugin ] ) ) ) );

		$this->assertSame(
			[
				[
					'PluginInstalled',
					[
						'name'    => 'Scanfully Test Plugin',
						'slug'    => $plugin,
						'version' => '1.2.3',
						'author'  => 'Scanfully',
					],
				],
				[
					'PluginDeleted',
					[
						'name'    => 'Scanfully Test Plugin',
						'slug'    => $plugin,
						'version' => '1.2.3',
					],
				],
			],
			$this->queued()
		);
	}

	public function test_a_failed_plugin_update_is_reported(): void {
		global $wpdb;
		// The same failure is reported once a day; forget earlier runs.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%scanfully\\_update\\_failed\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test cleanup.
		wp_cache_flush();

		$slug    = 'hello.php';
		$options = apply_filters(
			'upgrader_package_options',
			[ 'hook_extra' => [ 'plugin' => $slug ] ]
		);

		$failed = new \WP_Error( 'incompatible_php_required_version', 'The package requires a newer PHP version.' );
		apply_filters( 'upgrader_install_package_result', $failed, $options['hook_extra'] );

		$events = $this->queued();
		$this->assertSame( [ 'PluginUpdateFailed' ], array_column( $events, 0 ) );
		$this->assertSame( $slug, $events[0][1]['slug'] );
		$this->assertSame( 'Hello Dolly', $events[0][1]['name'] );
		$this->assertSame( 'The package requires a newer PHP version.', $events[0][1]['error'] );
	}

	public function test_a_failed_automatic_core_update_is_reported(): void {
		$version = '99.' . wp_rand( 0, 9999 );

		do_action(
			'automatic_updates_complete',
			[
				'core' => [
					(object) [
						'item'     => (object) [
							'current' => $version,
							'version' => $version,
						],
						'result'   => new \WP_Error( 'download_failed', 'Download failed.', 'Not Found' ),
						'name'     => 'WordPress ' . $version,
						'messages' => [],
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'CoreUpdateFailed',
					[
						'version'     => $version,
						'old_version' => get_bloginfo( 'version' ),
						'error'       => 'Download failed. Not Found',
					],
				],
			],
			$this->queued()
		);
	}
}
