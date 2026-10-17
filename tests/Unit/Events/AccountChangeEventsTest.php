<?php
/**
 * Password, email and super admin event unit tests.
 *
 * @package Scanfully\Tests\Unit\Events
 */

namespace Scanfully\Tests\Unit\Events;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use ReflectionProperty;
use Scanfully\Events\EmailChanged;
use Scanfully\Events\PasswordChanged;
use Scanfully\Events\SuperAdminGranted;
use Scanfully\Events\SuperAdminRevoked;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\PasswordChanged
 * @covers \Scanfully\Events\EmailChanged
 * @covers \Scanfully\Events\SuperAdminEvent
 * @covers \Scanfully\Events\SuperAdminGranted
 * @covers \Scanfully\Events\SuperAdminRevoked
 */
final class AccountChangeEventsTest extends TestCase {

	/**
	 * Users by ID.
	 *
	 * @var array<int, \WP_User>
	 */
	private array $users = [];

	/**
	 * In-memory transient store.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	/**
	 * Arguments of scheduled jobs.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $scheduled = [];

	/**
	 * The callbacks events added, by hook: [callback, accepted args].
	 *
	 * @var array<string, array{callable, int}>
	 */
	private array $listeners = [];

	protected function setUp(): void {
		parent::setUp();

		$defaults = [
			'pending'        => [],
			'resetting'      => [],
			'created'        => [],
			'authenticating' => false,
		];
		foreach ( $defaults as $property => $value ) {
			$reset = new ReflectionProperty( PasswordChanged::class, $property );
			$reset->setAccessible( true );
			$reset->setValue( null, $value );
		}

		$this->add_user( 4, 'Bob', [ 'administrator' ] );
		$this->add_user( 5, 'Sue', [ 'subscriber' ] );

		Functions\when( 'get_userdata' )->alias( fn( int $id ) => $this->users[ $id ] ?? false );
		Functions\when( 'user_can' )->alias( static fn( $user ) => in_array( 'administrator', (array) $user->roles, true ) );
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'get_transient' )->alias( fn( string $key ) => array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_option' )->justReturn( 'yes' );
		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) [
				'ID'           => 1,
				'display_name' => 'Admin',
			]
		);
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args ) {
				$this->scheduled[] = $args;
				return 1;
			}
		);
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
				$this->listeners[ $hook ] = [ $callback, $accepted_args ];
				return true;
			}
		);
		Actions\expectDone( PasswordChanged::ACTION )->zeroOrMoreTimes()->whenHappen(
			function ( ...$args ) {
				$this->fire( PasswordChanged::ACTION, ...$args );
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
				$this->listeners[ $hook ] = [ $callback, $accepted_args ];
				return true;
			}
		);
	}

	/**
	 * Add a user.
	 *
	 * @param int                $id    User ID.
	 * @param string             $name  Display name.
	 * @param array<int, string> $roles Roles.
	 *
	 * @return \WP_User
	 */
	private function add_user( int $id, string $name, array $roles ) {
		$user               = Mockery::mock( 'WP_User' );
		$user->ID           = $id;
		$user->display_name = $name;
		$user->first_name   = '';
		$user->last_name    = '';
		$user->roles        = $roles;
		$user->user_login   = strtolower( $name );
		$user->user_email   = strtolower( $name ) . '@scanfully.com';
		$user->user_pass    = '$wp$2y$10$new-hash';

		$this->users[ $id ] = $user;

		return $user;
	}

	/**
	 * Run a hook the way WordPress would, with the arguments its listener
	 * accepts.
	 *
	 * @param string $hook    The hook.
	 * @param mixed  ...$args The hook arguments.
	 *
	 * @return void
	 */
	private function fire( string $hook, ...$args ): void {
		$this->assertArrayHasKey( $hook, $this->listeners, "Nothing listens to $hook." );
		[ $callback, $accepted ] = $this->listeners[ $hook ];
		$callback( ...array_slice( $args, 0, $accepted ) );
	}

	/**
	 * The user as it was before a profile update.
	 *
	 * @param int                  $id     User ID.
	 * @param array<string, mixed> $fields Fields that were different.
	 *
	 * @return object
	 */
	private function before( int $id, array $fields ): object {
		$before = clone $this->users[ $id ];
		foreach ( $fields as $field => $value ) {
			$before->$field = $value;
		}

		return $before;
	}

	/**
	 * The types of the queued events.
	 *
	 * @return array<int, string>
	 */
	private function types(): array {
		return array_column( $this->scheduled, 'type' );
	}

	/**
	 * End the request: noted password changes are sent.
	 *
	 * @return void
	 */
	private function end_request(): void {
		$this->fire( 'shutdown' );
	}

	public function test_a_password_changed_through_a_profile_update_is_reported(): void {
		new PasswordChanged();

		$this->fire( 'profile_update', 4, $this->before( 4, [ 'user_pass' => '$wp$2y$10$old-hash' ] ), [] );
		$this->assertSame( [], $this->scheduled, 'Nothing is sent before the end of the request.' );
		$this->end_request();

		$this->assertSame( [ 'PasswordChanged' ], $this->types() );
		$this->assertSame(
			[
				'id'    => 4,
				'name'  => 'Bob',
				'email' => 'bob@scanfully.com',
				'roles' => [ 'administrator' ],
				'via'   => 'profile',
			],
			$this->scheduled[0]['data']
		);
		$this->assertStringNotContainsString( 'hash', (string) json_encode( $this->scheduled ), 'Never the hash.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
	}

	public function test_a_profile_update_on_newer_wordpress_is_reported_as_profile(): void {
		new PasswordChanged();

		// wp_update_user() fires wp_set_password before profile_update.
		$this->fire( 'wp_set_password', 'new-plain-password', 4, $this->users[4] );
		$this->fire( 'profile_update', 4, $this->before( 4, [ 'user_pass' => '$wp$2y$10$old-hash' ] ), [] );
		$this->end_request();

		$this->assertSame( [ 'PasswordChanged' ], $this->types() );
		$this->assertSame( 'profile', $this->scheduled[0]['data']['via'] );
	}

	public function test_a_profile_update_that_keeps_the_password_is_not_reported(): void {
		new PasswordChanged();

		$this->fire( 'profile_update', 4, $this->before( 4, [ 'display_name' => 'Robert' ] ), [] );
		$this->end_request();

		$this->assertSame( [], $this->scheduled );
	}

	public function test_a_reset_with_a_reset_link_is_reported_once_as_reset(): void {
		new PasswordChanged();

		$this->fire( 'password_reset', $this->users[4], 'new-plain-password' );
		$this->fire( 'wp_set_password', 'new-plain-password', 4, $this->users[4] );
		$this->fire( 'after_password_reset', $this->users[4], 'new-plain-password' );
		$this->end_request();

		$this->assertSame( [ 'PasswordChanged' ], $this->types() );
		$this->assertSame( 'reset', $this->scheduled[0]['data']['via'] );
		$this->assertStringNotContainsString( 'new-plain-password', (string) json_encode( $this->scheduled ), 'Never the password.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
	}

	public function test_a_reset_on_a_site_whose_wp_set_password_has_no_action_is_still_reported_as_reset(): void {
		new PasswordChanged();

		$this->fire( 'password_reset', $this->users[4], 'new-plain-password' );
		$this->fire( 'after_password_reset', $this->users[4], 'new-plain-password' );
		$this->end_request();

		$this->assertSame( 'reset', $this->scheduled[0]['data']['via'] );
	}

	public function test_setting_a_password_directly_is_reported_as_other(): void {
		new PasswordChanged();

		$this->fire( 'wp_set_password', 'new-plain-password', 4, $this->users[4] );
		$this->end_request();

		$this->assertSame( [ 'PasswordChanged' ], $this->types() );
		$this->assertSame( 'other', $this->scheduled[0]['data']['via'] );
	}

	public function test_a_newer_hash_stored_while_logging_in_is_not_a_change(): void {
		new PasswordChanged();

		PasswordChanged::note_authentication_started( null );
		$this->fire( 'wp_set_password', 'same-plain-password', 4, $this->users[4] );
		PasswordChanged::note_authentication_done( $this->users[4] );
		$this->end_request();

		$this->assertSame( [], $this->scheduled );
	}

	public function test_the_first_password_of_a_new_user_is_not_reported(): void {
		new PasswordChanged();

		// Newer WordPress fires wp_set_password for a new user before user_register.
		$this->fire( 'wp_set_password', 'new-plain-password', 4, $this->users[4] );
		$this->fire( 'user_register', 4, [] );
		$this->end_request();

		$this->assertSame( [], $this->scheduled );
	}

	public function test_a_password_change_of_an_account_without_elevated_rights_is_not_reported(): void {
		new PasswordChanged();

		$this->fire( 'wp_set_password', 'new-plain-password', 5, $this->users[5] );
		$this->fire( 'profile_update', 5, $this->before( 5, [ 'user_pass' => '$wp$2y$10$old-hash' ] ), [] );
		$this->end_request();

		$this->assertSame( [], $this->scheduled );
	}

	public function test_a_changed_email_address_is_reported_with_the_old_and_new_address(): void {
		new EmailChanged();

		// WordPress slashes the old address before profile_update.
		$this->fire( 'profile_update', 4, $this->before( 4, [ 'user_email' => "o\\'brien@scanfully.com" ] ), [] );

		$this->assertSame( [ 'EmailChanged' ], $this->types() );
		$this->assertSame( 'Admin', $this->scheduled[0]['user']['name'], 'The actor is who made the change.' );
		$this->assertSame(
			[
				'id'        => 4,
				'name'      => 'Bob',
				'email'     => 'bob@scanfully.com',
				'roles'     => [ 'administrator' ],
				'old_email' => "o'brien@scanfully.com",
			],
			$this->scheduled[0]['data']
		);
	}

	public function test_a_profile_update_that_keeps_the_email_address_is_not_reported(): void {
		new EmailChanged();

		$this->fire( 'profile_update', 4, $this->before( 4, [ 'display_name' => 'Robert' ] ), [] );

		$this->assertSame( [], $this->scheduled );
	}

	public function test_a_changed_email_address_of_an_account_without_elevated_rights_is_not_reported(): void {
		new EmailChanged();

		$this->fire( 'profile_update', 5, $this->before( 5, [ 'user_email' => 'old@scanfully.com' ] ), [] );

		$this->assertSame( [], $this->scheduled );
	}

	public function test_granting_and_revoking_super_admin_is_reported_with_the_account(): void {
		new SuperAdminGranted();
		new SuperAdminRevoked();

		$this->fire( 'granted_super_admin', 4 );
		$this->fire( 'revoked_super_admin', 4 );

		$this->assertSame( [ 'SuperAdminGranted', 'SuperAdminRevoked' ], $this->types() );
		$expected = [
			'id'    => 4,
			'name'  => 'Bob',
			'email' => 'bob@scanfully.com',
			'roles' => [ 'administrator' ],
		];
		$this->assertSame( $expected, $this->scheduled[0]['data'] );
		$this->assertSame( $expected, $this->scheduled[1]['data'] );
		$this->assertSame( 'Admin', $this->scheduled[0]['user']['name'], 'The actor is who made the change.' );
	}

	public function test_a_revoke_is_reported_even_when_the_account_has_no_role_on_the_site(): void {
		new SuperAdminRevoked();

		// Without super admin rights the account has nothing left on this site.
		$this->fire( 'revoked_super_admin', 5 );

		$this->assertSame( [ 'SuperAdminRevoked' ], $this->types() );
		$this->assertSame( 'sue@scanfully.com', $this->scheduled[0]['data']['email'] );
	}

	public function test_an_unknown_user_is_not_reported(): void {
		new SuperAdminGranted();

		$this->fire( 'granted_super_admin', 999 );

		$this->assertSame( [], $this->scheduled );
	}
}
