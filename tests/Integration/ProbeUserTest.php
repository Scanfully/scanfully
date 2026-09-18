<?php
/**
 * WooCheckout probe user integration tests.
 *
 * Runs against real WordPress users and roles (wp-env) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Scanfully\WooCheckout\LoginBridge;

/**
 * Verifies the probe account can't be hijacked or kept after a login.
 */
final class ProbeUserTest extends TestCase {

	/**
	 * Users created by a test.
	 *
	 * @var array<int, int>
	 */
	private array $users = [];

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wp_insert_user' ) ) {
			$this->markTestSkipped( 'WordPress runtime not available. Run this suite under wp-env.' );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( ! get_role( 'customer' ) ) {
			add_role( 'customer', 'Customer', [ 'read' => true ] );
		}
		$this->remove_probe_accounts();
	}

	protected function tearDown(): void {
		if ( function_exists( 'wp_delete_user' ) ) {
			$this->remove_probe_accounts();
			foreach ( $this->users as $id ) {
				wp_delete_user( $id );
			}
		}
		parent::tearDown();
	}

	/**
	 * Remove probe accounts and the stored probe ID.
	 */
	private function remove_probe_accounts(): void {
		$stored = (int) get_option( LoginBridge::OPTION_USER_ID, 0 );
		if ( $stored > 0 && ! in_array( $stored, $this->users, true ) ) {
			wp_delete_user( $stored );
		}
		foreach ( get_users(
			[
				'search' => 'scanfully_probe*',
				'search_columns' => [ 'user_login' ],
			]
		) as $user ) {
			if ( ! in_array( (int) $user->ID, $this->users, true ) ) {
				wp_delete_user( $user->ID );
			}
		}
		delete_option( LoginBridge::OPTION_USER_ID );
	}

	/**
	 * Create a user that the test cleans up.
	 *
	 * @param string $login Login.
	 * @param string $role  Role.
	 * @param string $email Email.
	 *
	 * @return int
	 */
	private function make_user( string $login, string $role, string $email ): int {
		$id            = wp_insert_user(
			[
				'user_login' => $login,
				'user_pass'  => 'known-password',
				'user_email' => $email,
				'role'       => $role,
			]
		);
		$this->users[] = $id;
		return $id;
	}

	/**
	 * Call a private LoginBridge method.
	 *
	 * @param string $name Method name.
	 * @param mixed  ...$args Arguments.
	 *
	 * @return mixed
	 */
	private function call( string $name, ...$args ) {
		$method = new ReflectionMethod( LoginBridge::class, $name );
		$method->setAccessible( true );
		return $method->invoke( null, ...$args );
	}

	public function test_a_new_probe_user_is_a_plain_customer(): void {
		$id   = LoginBridge::get_or_create_probe_user();
		$user = get_userdata( $id );

		$this->assertSame( 'scanfully_probe', $user->user_login );
		$this->assertSame( [ 'customer' ], array_values( $user->roles ) );
		$this->assertStringEndsWith( '@scanfully.invalid', $user->user_email );
		$this->assertSame( $id, (int) get_option( LoginBridge::OPTION_USER_ID ) );
	}

	public function test_an_existing_scanfully_probe_administrator_is_not_adopted(): void {
		$squatter = $this->make_user( 'scanfully_probe', 'administrator', 'owner@scanfully.test' );

		$id = LoginBridge::get_or_create_probe_user();

		$this->assertNotSame( $squatter, $id );
		$this->assertStringStartsWith( 'scanfully_probe_', get_userdata( $id )->user_login );
		$this->assertSame( [ 'administrator' ], array_values( get_userdata( $squatter )->roles ), 'The other account must be left alone.' );
	}

	public function test_a_stored_probe_user_that_was_promoted_is_replaced(): void {
		$id = LoginBridge::get_or_create_probe_user();
		( new \WP_User( $id ) )->set_role( 'editor' );
		$this->users[] = $id;

		$new_id = LoginBridge::get_or_create_probe_user();

		$this->assertNotSame( $id, $new_id );
		$this->assertSame( [ 'editor' ], array_values( get_userdata( $id )->roles ) );
	}

	public function test_a_login_undoes_changes_made_through_the_account(): void {
		$id = LoginBridge::get_or_create_probe_user();
		wp_update_user(
			[
				'ID'         => $id,
				'user_email' => 'attacker@scanfully.test',
			]
		);
		wp_set_password( 'attacker-password', $id );
		$manager = \WP_Session_Tokens::get_instance( $id );
		$manager->create( time() + HOUR_IN_SECONDS );
		\WP_Application_Passwords::create_new_application_password( $id, [ 'name' => 'attacker' ] );

		$this->assertTrue( $this->call( 'lock_down_probe_user', $id ) );

		$user = get_userdata( $id );
		$this->assertStringEndsWith( '@scanfully.invalid', $user->user_email );
		$this->assertFalse( wp_check_password( 'attacker-password', $user->user_pass, $id ) );
		$this->assertSame( [], $manager->get_all() );
		$this->assertSame( [], \WP_Application_Passwords::get_user_application_passwords( $id ) );
	}

	public function test_the_probe_user_cannot_reset_its_password_or_use_application_passwords(): void {
		LoginBridge::setup();
		$id    = LoginBridge::get_or_create_probe_user();
		$other = $this->make_user( 'scanfully_pu_other', 'customer', 'other@scanfully.test' );

		$this->assertFalse( apply_filters( 'allow_password_reset', true, $id ) );
		$this->assertFalse( apply_filters( 'wp_is_application_passwords_available_for_user', true, get_userdata( $id ) ) );
		$this->assertTrue( apply_filters( 'allow_password_reset', true, $other ) );
		$this->assertTrue( apply_filters( 'wp_is_application_passwords_available_for_user', true, get_userdata( $other ) ) );
	}

	public function test_the_probe_user_cannot_change_its_account_details(): void {
		LoginBridge::setup();
		$id     = LoginBridge::get_or_create_probe_user();
		$errors = new \WP_Error();
		$user   = (object) [ 'ID' => $id ];

		do_action_ref_array( 'woocommerce_save_account_details_errors', [ &$errors, &$user ] );

		$this->assertTrue( $errors->has_errors() );
	}
}
