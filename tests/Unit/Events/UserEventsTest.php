<?php
/**
 * User event unit tests.
 *
 * @package Scanfully\Tests\Unit\Events
 */

namespace Scanfully\Tests\Unit\Events;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use ReflectionProperty;
use Scanfully\Events\UserCreated;
use Scanfully\Events\UserDeleted;
use Scanfully\Events\UserRoleChanged;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\UserEvent
 * @covers \Scanfully\Events\UserCreated
 * @covers \Scanfully\Events\UserDeleted
 * @covers \Scanfully\Events\UserRoleChanged
 */
final class UserEventsTest extends TestCase {

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
	 * The signed-in user ID.
	 *
	 * @var int
	 */
	private int $current_user_id = 1;

	protected function setUp(): void {
		parent::setUp();
		$this->transients      = [];
		$this->current_user_id = 1;

		$defaults = [
			'creating_login'   => '',
			'created_user_ids' => [],
			'fired'            => [],
		];
		foreach ( $defaults as $property => $value ) {
			$reset = new ReflectionProperty( UserRoleChanged::class, $property );
			$reset->setAccessible( true );
			$reset->setValue( null, $value );
		}

		Functions\when( 'get_userdata' )->alias( fn( int $id ) => $this->users[ $id ] ?? false );
		Functions\when( 'get_current_user_id' )->alias( fn() => $this->current_user_id );
		// Roles that can edit content or manage the site.
		Functions\when( 'user_can' )->alias(
			static fn( $user ) => [] !== array_intersect( (array) $user->roles, [ 'administrator', 'editor', 'author', 'contributor', 'shop_manager' ] )
		);
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'get_transient' )->alias( fn( string $key ) => array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ) {
				$this->transients[ $key ] = $value;
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
		$user->roles        = $roles;
		$user->first_name   = '';
		$user->last_name    = '';
		$user->user_login   = 'login-' . $id;
		$user->user_email   = 'user' . $id . '@scanfully.com';

		$this->users[ $id ] = $user;

		return $user;
	}

	public function test_a_new_user_is_reported_with_id_name_and_roles_only(): void {
		$this->add_user( 7, 'Jane Editor', [ 'editor' ] );

		$event = new UserCreated();

		$this->assertTrue( $event->should_fire( [ 7 ] ) );
		$body = $event->get_post_body( [ 7 ] );
		$this->assertSame(
			[
				'id'    => 7,
				'name'  => 'Jane Editor',
				'roles' => [ 'editor' ],
			],
			$body
		);
		$encoded = (string) json_encode( $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		$this->assertStringNotContainsString( '@', $encoded );
		$this->assertStringNotContainsString( 'login-7', $encoded );
	}

	public function test_a_display_name_that_is_an_email_address_is_not_sent(): void {
		$user             = $this->add_user( 8, 'jane@scanfully.com', [ 'editor' ] );
		$user->first_name = 'Jane';
		$user->last_name  = 'Doe';
		$this->add_user( 9, 'john@scanfully.com', [ 'editor' ] );

		$this->assertSame( 'Jane Doe', ( new UserCreated() )->get_post_body( [ 8 ] )['name'] );
		$this->assertSame( '', ( new UserCreated() )->get_post_body( [ 9 ] )['name'] );
	}

	/**
	 * @dataProvider provide_sign_ups
	 *
	 * @param array<int, string> $roles Roles of the new user.
	 */
	public function test_a_visitor_signing_up_is_not_reported( array $roles ): void {
		$this->current_user_id = 0;
		$this->add_user( 10, 'New customer', $roles );

		$this->assertFalse( ( new UserCreated() )->should_fire( [ 10 ] ) );
	}

	/**
	 * @return array<string, array{array<int, string>}>
	 */
	public function provide_sign_ups(): array {
		return [
			'default role'         => [ [ 'subscriber' ] ],
			'WooCommerce customer' => [ [ 'customer' ] ],
			'no role'              => [ [] ],
		];
	}

	/**
	 * @dataProvider provide_elevated_sign_ups
	 *
	 * @param array<int, string> $roles Roles of the new user.
	 */
	public function test_a_sign_up_that_can_edit_or_manage_the_site_is_reported( array $roles ): void {
		$this->current_user_id = 0;
		$this->add_user( 11, 'Suspicious', $roles );

		$this->assertTrue( ( new UserCreated() )->should_fire( [ 11 ] ) );
	}

	/**
	 * Sign-ups with these roles are reported whatever the default role for
	 * new users is; with registration open and the default role set to
	 * Administrator, every sign-up is an administrator.
	 *
	 * @return array<string, array{array<int, string>}>
	 */
	public function provide_elevated_sign_ups(): array {
		return [
			'default role set to administrator' => [ [ 'administrator' ] ],
			'default role set to editor'        => [ [ 'editor' ] ],
			'shop manager'                      => [ [ 'shop_manager' ] ],
		];
	}

	public function test_a_user_created_by_someone_else_is_reported_whatever_the_role(): void {
		$this->add_user( 22, 'New subscriber', [ 'subscriber' ] );

		$this->assertTrue( ( new UserCreated() )->should_fire( [ 22 ] ) );
	}

	public function test_sites_can_report_sign_ups_with_a_filter(): void {
		$this->current_user_id = 0;
		$this->add_user( 12, 'New customer', [ 'customer' ] );
		Filters\expectApplied( 'scanfully_user_created_should_fire' )->once()->andReturn( true );

		$this->assertTrue( ( new UserCreated() )->should_fire( [ 12 ] ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_users_created_by_an_import_are_not_reported(): void {
		define( 'WP_IMPORTING', true );
		$this->add_user( 13, 'Imported author', [ 'author' ] );

		$this->assertFalse( ( new UserCreated() )->should_fire( [ 13 ] ) );
	}

	public function test_user_events_are_capped_per_minute_in_one_transient(): void {
		Filters\expectApplied( 'scanfully_user_events_per_minute' )->andReturn( 2 );
		$this->add_user( 14, 'A', [ 'editor' ] );

		$event = new UserDeleted();
		$this->assertTrue( $event->should_fire( [ 14 ] ) );
		$this->assertTrue( $event->should_fire( [ 14 ] ) );
		$this->assertFalse( $event->should_fire( [ 14 ] ) );
		$this->assertSame( [ 'scanfully_user_events_rate' ], array_keys( $this->transients ) );

		// A new minute starts a new count in the same transient.
		$this->transients['scanfully_user_events_rate'] = [ (int) floor( time() / MINUTE_IN_SECONDS ) - 1, 2 ];
		$this->assertTrue( $event->should_fire( [ 14 ] ) );
	}

	public function test_a_deleted_user_is_reported_with_id_name_and_roles(): void {
		$this->add_user( 15, 'Old Admin', [ 'administrator' ] );

		$event = new UserDeleted();

		$this->assertTrue( $event->should_fire( [ 15, null ] ) );
		$this->assertSame(
			[
				'id'    => 15,
				'name'  => 'Old Admin',
				'roles' => [ 'administrator' ],
			],
			$event->get_post_body( [ 15, null ] )
		);
		$this->assertFalse( $event->should_fire( [ 999, null ] ), 'An unknown user.' );
	}

	public function test_a_role_change_has_the_new_and_old_roles(): void {
		$this->add_user( 16, 'Promoted', [ 'administrator' ] );

		$event = new UserRoleChanged();
		$data  = [ 16, 'administrator', [ 'editor' ] ];

		$this->assertTrue( $event->should_fire( $data ) );
		$this->assertSame(
			[
				'id'        => 16,
				'name'      => 'Promoted',
				'roles'     => [ 'administrator' ],
				'old_roles' => [ 'editor' ],
			],
			$event->get_post_body( $data )
		);
	}

	public function test_the_same_role_change_is_reported_once_per_request(): void {
		$this->add_user( 17, 'Promoted', [ 'administrator' ] );
		$event = new UserRoleChanged();

		$this->assertTrue( $event->should_fire( [ 17, 'administrator', [ 'editor' ] ] ) );
		$this->assertFalse( $event->should_fire( [ 17, 'administrator', [ 'editor' ] ] ) );
	}

	public function test_setting_the_role_a_user_already_has_is_not_a_change(): void {
		$this->add_user( 18, 'Same', [ 'editor' ] );

		$this->assertFalse( ( new UserRoleChanged() )->should_fire( [ 18, 'editor', [ 'editor' ] ] ) );
	}

	public function test_the_first_role_of_a_new_user_is_not_a_role_change(): void {
		$this->add_user( 19, 'New', [ 'subscriber' ] );
		$event = new UserRoleChanged();

		UserRoleChanged::note_user_insert( [ 'user_login' => 'login-19' ], false );
		$this->assertFalse( $event->should_fire( [ 19, 'subscriber', [] ] ), 'While the user is created.' );

		UserRoleChanged::note_user_registered( 19 );
		$this->assertTrue( $event->should_fire( [ 19, 'subscriber', [] ] ), 'A role given later by someone signed in.' );
	}

	public function test_a_failed_insert_does_not_hide_a_later_role_change_of_another_user(): void {
		$this->add_user( 26, 'Existing', [ 'editor' ] );
		$event = new UserRoleChanged();

		// wp_insert_user() fails after this point (a database error), so
		// user_register never runs.
		UserRoleChanged::note_user_insert( [ 'user_login' => 'ghost' ], false );

		$this->assertTrue( $event->should_fire( [ 26, 'editor', [] ] ) );
	}

	public function test_the_first_role_of_a_new_user_is_skipped_only_once(): void {
		$this->add_user( 27, 'New', [ 'subscriber' ] );
		$event = new UserRoleChanged();

		UserRoleChanged::note_user_insert( [ 'user_login' => 'login-27' ], false );

		$this->assertFalse( $event->should_fire( [ 27, 'subscriber', [] ] ) );
		$this->assertTrue( $event->should_fire( [ 27, 'editor', [] ] ), 'A second role change in the same request.' );
	}

	public function test_a_role_set_right_after_a_plain_sign_up_is_not_a_role_change(): void {
		$this->current_user_id = 0;
		$this->add_user( 23, 'New customer', [ 'customer' ] );
		$event = new UserRoleChanged();

		// Another plugin's user_register handler, or multisite adding the user to the site.
		UserRoleChanged::note_user_registered( 23 );

		$this->assertFalse( $event->should_fire( [ 23, 'customer', [ 'subscriber' ] ] ) );
	}

	public function test_a_role_set_right_after_a_sign_up_that_gives_elevated_rights_is_reported(): void {
		$this->current_user_id = 0;
		$this->add_user( 24, 'Promoted on sign-up', [ 'administrator' ] );
		$event = new UserRoleChanged();

		UserRoleChanged::note_user_registered( 24 );

		$this->assertTrue( $event->should_fire( [ 24, 'administrator', [ 'subscriber' ] ] ) );
	}

	public function test_a_role_change_of_an_older_user_without_anyone_signed_in_is_reported(): void {
		$this->current_user_id = 0;
		$this->add_user( 25, 'Cron change', [ 'customer' ] );

		$this->assertTrue( ( new UserRoleChanged() )->should_fire( [ 25, 'customer', [ 'subscriber' ] ] ) );
	}

	public function test_a_role_change_while_a_user_is_updated_is_reported(): void {
		$this->add_user( 20, 'Demoted', [ 'subscriber' ] );

		$this->assertSame( [ 'x' => 1 ], UserRoleChanged::note_user_insert( [ 'x' => 1 ], true ), 'The user data must be returned unchanged.' );
		$this->assertTrue( ( new UserRoleChanged() )->should_fire( [ 20, 'subscriber', [ 'administrator' ] ] ) );
	}

	public function test_removing_every_role_is_reported_with_an_empty_role_list(): void {
		$this->add_user( 21, 'No access', [] );
		$event = new UserRoleChanged();

		$this->assertTrue( $event->should_fire( [ 21, '', [ 'editor' ] ] ) );
		$this->assertSame( [], $event->get_post_body( [ 21, '', [ 'editor' ] ] )['roles'] );
	}
}
