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
	 * Start what counts as a new request for the password events: the users
	 * created so far were created in an earlier request.
	 *
	 * @return void
	 */
	private function new_request(): void {
		\Scanfully\Events\PasswordChanged::flush();
		as_unschedule_all_actions( '', [], 'scanfully' );
		$created = new \ReflectionProperty( \Scanfully\Events\PasswordChanged::class, 'created' );
		$created->setAccessible( true );
		$created->setValue( null, [] );
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

	public function test_revoking_application_passwords_is_reported_once_per_password(): void {
		$admin = $this->create_user( 'administrator' );
		$first = $this->create_app_password( $admin )[1];
		$this->create_app_password( $admin );
		as_unschedule_all_actions( '', [], 'scanfully' );

		\WP_Application_Passwords::delete_application_password( $admin, $first['uuid'] );
		\WP_Application_Passwords::delete_all_application_passwords( $admin );

		$events = $this->queued();
		$this->assertSame( [ 'AppPasswordRevoked', 'AppPasswordRevoked' ], array_column( $events, 0 ) );
		$this->assertSame( $first['uuid'], $events[0][1]['app_id'] );
		$this->assertNotSame( $first['uuid'], $events[1][1]['app_id'] );
		$this->assertSame( '', $events[0][1]['last_used'] );
	}

	/**
	 * Sign a request in with an application password through HTTP Basic
	 * authentication, as a REST request does.
	 *
	 * @param int    $user_id  The user ID.
	 * @param string $password The application password.
	 *
	 * @return int The authenticated user ID, or 0.
	 */
	private function sign_request_in( int $user_id, string $password ): int {
		$api_request = '__return_true';
		add_filter( 'application_password_is_api_request', $api_request );
		add_filter( 'wp_is_application_passwords_available', $api_request );
		$_SERVER['PHP_AUTH_USER'] = get_userdata( $user_id )->user_login;
		$_SERVER['PHP_AUTH_PW']   = $password;
		$_SERVER['REMOTE_ADDR']   = '203.0.113.9';

		$authenticated = apply_filters( 'determine_current_user', false );

		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['REMOTE_ADDR'] );
		remove_filter( 'application_password_is_api_request', $api_request );
		remove_filter( 'wp_is_application_passwords_available', $api_request );

		return (int) $authenticated;
	}

	public function test_the_first_use_of_an_application_password_is_reported_once(): void {
		$admin               = $this->create_user( 'administrator' );
		[ $password, $item ] = $this->create_app_password( $admin );
		$subscriber          = $this->create_user( 'subscriber' );
		$other_password      = $this->create_app_password( $subscriber )[0];
		as_unschedule_all_actions( '', [], 'scanfully' );

		$this->assertSame( $admin, $this->sign_request_in( $admin, $password ) );
		$this->assertSame( $admin, $this->sign_request_in( $admin, $password ), 'A second request.' );
		$this->assertSame( $subscriber, $this->sign_request_in( $subscriber, $other_password ) );

		$events = $this->queued();
		$this->assertSame( [ 'AppPasswordFirstUsed' ], array_column( $events, 0 ) );
		$this->assertSame( $item['uuid'], $events[0][1]['app_id'] );
		$this->assertSame( '203.0.113.9', $events[0][1]['ip'] );
		$this->assertStringNotContainsString( $password, (string) wp_json_encode( $events ), 'Never the password.' );
	}

	public function test_password_changes_are_reported_with_how_they_were_made(): void {
		$profile    = $this->create_user( 'administrator' );
		$reset      = $this->create_user( 'administrator' );
		$direct     = $this->create_user( 'editor' );
		$subscriber = $this->create_user( 'subscriber' );
		$this->new_request();

		wp_update_user(
			[
				'ID'        => $profile,
				'user_pass' => 'Profile-' . wp_generate_password(),
			]
		);
		reset_password( get_userdata( $reset ), 'Reset-' . wp_generate_password() );
		wp_set_password( 'Direct-' . wp_generate_password(), $direct );
		wp_set_password( 'Direct-' . wp_generate_password(), $subscriber );
		$this->assertSame( [], $this->queued_types(), 'Nothing is sent before the end of the request.' );
		\Scanfully\Events\PasswordChanged::flush();

		$events = $this->queued();
		$this->assertSame( [ 'PasswordChanged', 'PasswordChanged', 'PasswordChanged' ], array_column( $events, 0 ) );
		$this->assertSame( [ $profile, $reset, $direct ], array_column( array_column( $events, 1 ), 'id' ) );
		$this->assertSame( [ 'profile', 'reset', 'other' ], array_column( array_column( $events, 1 ), 'via' ) );
		$encoded = (string) wp_json_encode( $events );
		$this->assertStringNotContainsString( 'Profile-', $encoded, 'Never the password.' );
		$this->assertStringNotContainsString( '$', $encoded, 'Never the hash.' );
	}

	public function test_the_password_of_a_new_user_is_not_a_change(): void {
		$this->new_request();
		as_unschedule_all_actions( '', [], 'scanfully' );
		$id = wp_insert_user(
			[
				'user_login' => 'scanfully-' . strtolower( wp_generate_password( 8, false ) ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'administrator',
			]
		);
		$this->assertIsInt( $id );
		$this->users[] = $id;
		wp_set_password( wp_generate_password(), $id );
		\Scanfully\Events\PasswordChanged::flush();

		$this->assertSame( [ 'UserCreated' ], $this->queued_types() );
	}

	public function test_logging_in_is_not_a_password_change(): void {
		$admin    = $this->create_user( 'administrator' );
		$password = 'Login-' . wp_generate_password();
		wp_set_password( $password, $admin );
		$this->new_request();
		// Store the password with an old hash, so logging in rehashes it.
		global $wpdb;
		$wpdb->update( $wpdb->users, [ 'user_pass' => md5( $password ) ], [ 'ID' => $admin ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test setup.
		clean_user_cache( $admin );

		$user = wp_authenticate( get_userdata( $admin )->user_login, $password );
		\Scanfully\Events\PasswordChanged::flush();

		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertNotSame( md5( $password ), get_userdata( $admin )->user_pass, 'WordPress stored a newer hash.' );
		$this->assertSame( [], $this->queued_types() );
	}

	public function test_an_email_change_is_reported_for_elevated_accounts_only(): void {
		$admin      = $this->create_user( 'administrator' );
		$subscriber = $this->create_user( 'subscriber' );
		$old_email  = get_userdata( $admin )->user_email;
		$new_email  = "o'brien-" . strtolower( wp_generate_password( 6, false ) ) . '@scanfully.com';

		wp_update_user(
			[
				'ID'         => $admin,
				'user_email' => $new_email,
			]
		);
		wp_update_user(
			[
				'ID'         => $subscriber,
				'user_email' => 'new-' . get_userdata( $subscriber )->user_email,
			]
		);
		wp_update_user(
			[
				'ID'           => $admin,
				'display_name' => 'Renamed',
			]
		);

		$events = $this->queued();
		$this->assertSame( [ 'EmailChanged' ], array_column( $events, 0 ) );
		$this->assertSame( $new_email, $events[0][1]['email'] );
		$this->assertSame( $old_email, $events[0][1]['old_email'] );
	}
}
