<?php
/**
 * Failed login integration tests.
 *
 * Runs against real WordPress (wp-env) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Events\Controller as EventsController;
use Scanfully\Events\FailedLogins\Counter;
use Scanfully\Events\FailedLogins\Reporter;
use Scanfully\Events\FailedLogins\Store;

/**
 * Fails real logins and checks what is counted and sent.
 */
final class FailedLoginsTest extends TestCase {

	/**
	 * Users created by a test.
	 *
	 * @var array<int, int>
	 */
	private array $users = [];

	/**
	 * Bodies sent to the failed logins endpoint.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wp_signon' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'WordPress not available. Run this suite under wp-env.' );
		}

		update_option( 'scanfully_connect_is_connected', 'yes' );
		Store::delete_all();
		Counter::reset();
		as_unschedule_all_actions( '', [], 'scanfully' );
		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );
	}

	protected function tearDown(): void {
		if ( function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
			remove_all_filters( 'application_password_is_api_request' );
			remove_filter( 'send_auth_cookies', '__return_false' );
			foreach ( $this->users as $id ) {
				wp_delete_user( $id );
			}
			Store::delete_all();
			Counter::reset();
			as_unschedule_all_actions( '', [], 'scanfully' );
			delete_option( 'scanfully_connect_is_connected' );
		}
		parent::tearDown();
	}

	/**
	 * `pre_http_request`: keep what is sent to the failed logins endpoint and accept it.
	 *
	 * @param mixed  $preempt Whether to preempt the request.
	 * @param array  $args    The request arguments.
	 * @param string $url     The URL.
	 *
	 * @return mixed
	 */
	public function capture_request( $preempt, $args, $url ) {
		if ( false === strpos( (string) $url, '/logins/failed' ) ) {
			return $preempt;
		}
		$this->sent[] = json_decode( (string) ( $args['body'] ?? '' ), true );

		return [
			'headers'  => [],
			'body'     => '',
			'response' => [
				'code'    => 204,
				'message' => 'No Content',
			],
			'cookies'  => [],
		];
	}

	/**
	 * A user with a role.
	 *
	 * @param string $role The role.
	 *
	 * @return \WP_User
	 */
	private function user( string $role ): \WP_User {
		$login         = 'scanfully_lf_' . $role . '_' . wp_generate_password( 6, false );
		$id            = wp_insert_user(
			[
				'user_login'   => $login,
				'user_pass'    => 'right-password-123',
				'user_email'   => $login . '@scanfully.com',
				'display_name' => ucfirst( $role ) . ' Tester',
				'role'         => $role,
			]
		);
		$this->users[] = $id;

		return get_userdata( $id );
	}

	/**
	 * Fail a login, as a new request would.
	 *
	 * @param string $login    The login.
	 * @param string $password The password.
	 *
	 * @return void
	 */
	private function fail_login( string $login, string $password = 'wrong' ): void {
		Counter::reset();
		$result = wp_signon(
			[
				'user_login'    => $login,
				'user_password' => $password,
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * The counters of this hour.
	 *
	 * @return array<string, int>
	 */
	private function counters(): array {
		return Store::hours_before( Store::hour() + 1 )[ Store::hour() ] ?? [];
	}

	public function test_wrong_passwords_and_unknown_accounts_are_counted_by_reason(): void {
		$admin    = $this->user( 'administrator' );
		$customer = $this->user( 'subscriber' );

		$this->fail_login( $admin->user_login );
		$this->fail_login( $admin->user_email );
		$this->fail_login( $customer->user_login );
		$this->fail_login( 'nobody-' . wp_generate_password( 6, false ) );
		$this->fail_login( '' );

		$counters = $this->counters();
		$this->assertSame( 4, $counters['total'], 'WordPress does not report an empty login as failed.' );
		$this->assertSame( 3, $counters['r_wrong_password'] );
		$this->assertSame( 1, $counters['r_unknown_account'] );
		$this->assertSame( 2, $counters[ 'u_' . $admin->ID ] );
		$this->assertArrayNotHasKey( 'u_' . $customer->ID, $counters, 'Only accounts with elevated rights are named.' );
		$this->assertSame( 4, $counters['c_other'], 'Not the login page, XML-RPC or an application password.' );
	}

	public function test_a_wrong_application_password_is_counted_once(): void {
		$admin = $this->user( 'administrator' );
		add_filter( 'application_password_is_api_request', '__return_true' );

		Counter::reset();
		$result = wp_authenticate( $admin->user_login, 'abcd efgh ijkl mnop qrst uvwx' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$counters = $this->counters();
		$this->assertSame( 1, $counters['total'] );
		$this->assertSame( 1, $counters['c_app_password'] );
		$this->assertSame( 1, $counters[ 'u_' . $admin->ID ] );
	}

	public function test_the_hourly_job_sends_finished_hours_and_removes_them(): void {
		$admin = $this->user( 'administrator' );
		$hour  = Store::hour() - 1;
		Store::increment( [ 'total', 'r_wrong_password', 'c_login_page', 'u_' . $admin->ID ], $hour );
		Store::increment( [ 'total', 'r_wrong_password', 'c_login_page', 'u_' . $admin->ID ], $hour );
		Store::increment( [ 'total', 'r_blocked', 'c_xmlrpc' ], $hour );
		Store::increment( [ 'total' ], Store::hour() );

		Reporter::send();

		$this->assertCount( 1, $this->sent );
		$sent = $this->sent[0]['hours'][0];
		$this->assertSame( 3, $sent['total'] );
		$this->assertSame( 2, $sent['reasons']['wrong_password'] );
		$this->assertSame( 1, $sent['reasons']['blocked'] );
		$this->assertSame( 1, $sent['channels']['xmlrpc'] );
		$this->assertSame( $admin->ID, $sent['accounts'][0]['id'] );
		$this->assertSame( 'Administrator Tester', $sent['accounts'][0]['name'] );
		$this->assertSame( 2, $sent['accounts'][0]['count'] );
		$this->assertArrayNotHasKey( 'email', $sent['accounts'][0] );
		$this->assertSame( [ Store::hour() ], array_keys( Store::hours_before( Store::hour() + 1 ) ), 'The finished hour is removed, the current one kept.' );
	}

	public function test_a_login_after_wrong_passwords_says_how_many(): void {
		$admin = $this->user( 'administrator' );
		$this->fail_login( $admin->user_login );
		$this->fail_login( $admin->user_login );
		$this->fail_login( $admin->user_login );

		add_filter( 'send_auth_cookies', '__return_false' );
		$user = wp_signon(
			[
				'user_login'    => $admin->user_login,
				'user_password' => 'right-password-123',
			]
		);
		$this->assertInstanceOf( \WP_User::class, $user );

		$logins = array_values(
			array_filter(
				array_map(
					static fn( $action ) => $action->get_args(),
					as_get_scheduled_actions(
						[
							'hook'     => EventsController::ACTION_SEND_EVENT,
							'group'    => 'scanfully',
							'status'   => \ActionScheduler_Store::STATUS_PENDING,
							'per_page' => -1,
						]
					)
				),
				static fn( array $args ) => 'UserLogin' === ( $args['type'] ?? '' )
			)
		);
		$this->assertCount( 1, $logins );
		$this->assertSame( 3, $logins[0]['data']['failed_before'] );
	}

	public function test_counting_is_atomic_in_the_real_options_table(): void {
		$hour = Store::hour() - 3;
		for ( $i = 0; $i < 25; $i++ ) {
			Store::increment( [ 'total' ], $hour );
		}

		$this->assertSame( 25, Store::count( 'total', $hour ) );
		$this->assertFalse( wp_load_alloptions()[ 'scanfully_lf_' . $hour . '_total' ] ?? false, 'Counters are never autoloaded.' );
	}
}
