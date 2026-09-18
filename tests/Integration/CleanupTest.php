<?php
/**
 * Deactivation and uninstall cleanup integration tests.
 *
 * Runs against the real WordPress database and Action Scheduler (wp-env)
 * and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Cron\Controller;

/**
 * Verifies deactivation and uninstall leave nothing behind.
 */
final class CleanupTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'wp_insert_user' ) ) {
			$this->markTestSkipped( 'WordPress runtime not available. Run this suite under wp-env.' );
		}
		if ( ! get_role( 'customer' ) ) {
			add_role( 'customer', 'Customer', [ 'read' => true ] );
		}
	}

	/**
	 * Count pending Scanfully jobs.
	 *
	 * @return int
	 */
	private function pending_jobs(): int {
		return count(
			as_get_scheduled_actions(
				[
					'group'    => 'scanfully',
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				],
				'ids'
			)
		);
	}

	/**
	 * Schedule jobs like the plugin does, including an event job with args.
	 */
	private function schedule_jobs(): void {
		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'scanfully_sync_site_health', [], 'scanfully' );
		as_schedule_single_action(
			time() + HOUR_IN_SECONDS,
			'scanfully_send_event',
			[
				'type' => 'PostSaved',
				'user' => [],
				'data' => [ 'id' => 1 ],
			],
			'scanfully'
		);
	}

	public function test_deactivation_cancels_event_jobs_too(): void {
		$this->schedule_jobs();
		$this->assertGreaterThanOrEqual( 2, $this->pending_jobs() );

		Controller::clear_scheduled_events();

		$this->assertSame( 0, $this->pending_jobs() );
	}

	public function test_uninstall_keeps_an_account_that_is_not_the_probe_user(): void {
		$admin_id = wp_insert_user(
			[
				'user_login' => 'scanfully_cleanup_admin',
				'user_pass'  => wp_generate_password(),
				'user_email' => 'admin@scanfully.test',
				'role'       => 'administrator',
			]
		);
		update_option( 'scanfully_woocheckout_probe_user_id', $admin_id, false );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'scanfully/scanfully.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertInstanceOf( \WP_User::class, get_userdata( $admin_id ), 'A real account must never be deleted.' );
		$this->assertFalse( get_option( 'scanfully_woocheckout_probe_user_id' ) );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $admin_id );
	}

	/**
	 * @depends test_uninstall_keeps_an_account_that_is_not_the_probe_user
	 */
	public function test_uninstall_removes_options_transients_jobs_and_the_probe_user(): void {
		$probe_id = wp_insert_user(
			[
				'user_login' => 'scanfully_probe',
				'user_pass'  => wp_generate_password(),
				'user_email' => 'probe+test@scanfully.invalid',
				'role'       => 'customer',
			]
		);
		update_option( 'scanfully_woocheckout_probe_user_id', $probe_id, false );
		update_option( 'scanfully_connect_access_token', 'token' );
		update_option( 'scanfully_connect_refresh_token', 'refresh' );
		update_option( 'scanfully_woocheckout_probe_secret', str_repeat( 'a', 64 ), false );
		update_option( 'unrelated_option_kept', 'yes' );
		set_transient( 'scanfully_refresh_failures', 2, HOUR_IN_SECONDS );
		$this->schedule_jobs();

		// uninstall.php was loaded by the previous test; run its cleanup again.
		scanfully_uninstall_site();

		$this->assertFalse( get_userdata( $probe_id ) );
		$this->assertFalse( get_option( 'scanfully_connect_access_token' ) );
		$this->assertFalse( get_option( 'scanfully_connect_refresh_token' ) );
		$this->assertFalse( get_option( 'scanfully_woocheckout_probe_secret' ) );
		$this->assertFalse( get_transient( 'scanfully_refresh_failures' ) );
		$this->assertSame( 0, $this->pending_jobs() );
		$this->assertSame( 'yes', get_option( 'unrelated_option_kept' ) );

		delete_option( 'unrelated_option_kept' );
	}
}
