<?php
/**
 * Account security event integration tests: application passwords,
 * password and email changes.
 *
 * Runs the real WordPress hooks (wp-env) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Events\Controller as EventsController;

/**
 * Verifies which account changes queue which activity events.
 */
final class AccountSecurityEventTest extends TestCase {

	/**
	 * Users created by a test.
	 *
	 * @var array<int, int>
	 */
	private array $users = [];

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( \WP_Application_Passwords::class ) ) {
			$this->markTestSkipped( 'WordPress not available. Run this suite under wp-env.' );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';

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
	 * Create a user and forget the events that queued.
	 *
	 * @param string $role The role.
	 *
	 * @return int
	 */
	private function create_user( string $role ): int {
		$login = 'scanfully-' . strtolower( wp_generate_password( 8, false ) );
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
		as_unschedule_all_actions( '', [], 'scanfully' );

		return $id;
	}

	/**
	 * Create an application password.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return array{0: string, 1: array<string, mixed>} The password and its record.
	 */
	private function create_app_password( int $user_id ): array {
		$created = \WP_Application_Passwords::create_new_application_password( $user_id, [ 'name' => 'Zapier' ] );
		$this->assertIsArray( $created );

		return $created;
	}

	public function test_creating_an_application_password_is_reported_for_elevated_accounts_only(): void {
		$subscriber = $this->create_user( 'subscriber' );
		$admin      = $this->create_user( 'administrator' );

		$this->create_app_password( $subscriber );
		[ $password, $item ] = $this->create_app_password( $admin );

		$events = $this->queued();
		$this->assertSame( [ 'AppPasswordCreated' ], array_column( $events, 0 ) );
		$this->assertSame( $admin, $events[0][1]['id'] );
		$this->assertSame( 'Zapier', $events[0][1]['app_name'] );
		$this->assertSame( $item['uuid'], $events[0][1]['app_id'] );
		$this->assertStringNotContainsString( $password, (string) wp_json_encode( $events ), 'Never the password.' );
	}
}
