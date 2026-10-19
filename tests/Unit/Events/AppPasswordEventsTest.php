<?php
/**
 * Application password event unit tests.
 *
 * @package Scanfully\Tests\Unit\Events
 */

namespace Scanfully\Tests\Unit\Events;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Scanfully\Events\AppPasswordCreated;
use Scanfully\Events\AppPasswordFirstUsed;
use Scanfully\Events\AppPasswordRevoked;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\AppPasswordEvent
 * @covers \Scanfully\Events\AppPasswordCreated
 * @covers \Scanfully\Events\AppPasswordRevoked
 * @covers \Scanfully\Events\AppPasswordFirstUsed
 * @covers \Scanfully\Events\UserEvent
 */
final class AppPasswordEventsTest extends TestCase {

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
	 * $_SERVER before the test.
	 *
	 * @var array<string, mixed>
	 */
	private array $server = [];

	protected function setUp(): void {
		parent::setUp();
		$this->server = $_SERVER;
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $value ) => trim( (string) $value ) );

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
	}

	protected function tearDown(): void {
		$_SERVER = $this->server;
		parent::tearDown();
	}

	/**
	 * Add a user.
	 *
	 * @param int                $id    User ID.
	 * @param string             $name  Display name.
	 * @param array<int, string> $roles Roles.
	 *
	 * @return void
	 */
	private function add_user( int $id, string $name, array $roles ): void {
		$user               = Mockery::mock( 'WP_User' );
		$user->ID           = $id;
		$user->display_name = $name;
		$user->first_name   = '';
		$user->last_name    = '';
		$user->roles        = $roles;
		$user->user_login   = strtolower( $name );
		$user->user_email   = strtolower( $name ) . '@scanfully.com';

		$this->users[ $id ] = $user;
	}

	/**
	 * An application password record as WordPress stores it.
	 *
	 * @param array<string, mixed> $fields Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private function item( array $fields = [] ): array {
		return array_merge(
			[
				'uuid'      => '6f8f1c2e-7a5b-4c3d-9e1f-2a3b4c5d6e7f',
				'app_id'    => '',
				'name'      => 'Zapier',
				'password'  => '$generic$hashed-password',
				'created'   => 1760000000,
				'last_used' => null,
				'last_ip'   => null,
			],
			$fields
		);
	}

	/**
	 * Run a hook's callbacks the way WordPress would, with the arguments the
	 * listener accepts.
	 *
	 * @param string $hook The hook.
	 * @param mixed  ...$args The hook arguments.
	 *
	 * @return void
	 */
	private function run_listener( string $hook, ...$args ): void {
		$listener = null;
		$accepted = 1;
		Actions\expectAdded( $hook )->whenHappen(
			static function ( $callback, $priority, $accepted_args ) use ( &$listener, &$accepted ) {
				$listener = $callback;
				$accepted = $accepted_args;
			}
		);

		$this->event_for( $hook );
		$this->assertIsCallable( $listener );
		$listener( ...array_slice( $args, 0, $accepted ) );
	}

	/**
	 * Create the event that listens to a hook.
	 *
	 * @param string $hook The hook.
	 *
	 * @return object
	 */
	private function event_for( string $hook ): object {
		$events = [
			'wp_create_application_password'        => AppPasswordCreated::class,
			'wp_delete_application_password'        => AppPasswordRevoked::class,
			'application_password_did_authenticate' => AppPasswordFirstUsed::class,
		];

		return new $events[ $hook ]();
	}

	public function test_a_new_application_password_of_an_elevated_account_is_reported(): void {
		$this->run_listener( 'wp_create_application_password', 4, $this->item(), 'abcd efgh ijkl mnop qrst uvwx', [ 'name' => 'Zapier' ] );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( 'AppPasswordCreated', $this->scheduled[0]['type'] );
		$this->assertSame( 'Admin', $this->scheduled[0]['user']['name'], 'The actor is whoever created it.' );
		$this->assertSame(
			[
				'id'       => 4,
				'name'     => 'Bob',
				'email'    => 'bob@scanfully.com',
				'roles'    => [ 'administrator' ],
				'app_name' => 'Zapier',
				'app_id'   => '6f8f1c2e-7a5b-4c3d-9e1f-2a3b4c5d6e7f',
			],
			$this->scheduled[0]['data']
		);

		$encoded = (string) json_encode( $this->scheduled ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		$this->assertStringNotContainsString( 'abcd efgh', $encoded, 'Never the password.' );
		$this->assertStringNotContainsString( 'hashed-password', $encoded, 'Never the hash.' );
	}

	public function test_a_new_application_password_of_another_account_is_not_reported(): void {
		$this->run_listener( 'wp_create_application_password', 5, $this->item(), 'abcd efgh ijkl mnop qrst uvwx', [] );
		$this->run_listener( 'wp_create_application_password', 999, $this->item(), 'abcd efgh ijkl mnop qrst uvwx', [] );

		$this->assertSame( [], $this->scheduled );
	}

	public function test_a_revoked_application_password_is_reported_with_when_it_was_last_used(): void {
		$this->run_listener( 'wp_delete_application_password', 4, $this->item( [ 'last_used' => 1759492800 ] ) );

		$this->assertSame( 'AppPasswordRevoked', $this->scheduled[0]['type'] );
		$this->assertSame(
			[
				'id'        => 4,
				'name'      => 'Bob',
				'email'     => 'bob@scanfully.com',
				'roles'     => [ 'administrator' ],
				'app_name'  => 'Zapier',
				'app_id'    => '6f8f1c2e-7a5b-4c3d-9e1f-2a3b4c5d6e7f',
				'last_used' => '2025-10-03T12:00:00Z',
			],
			$this->scheduled[0]['data']
		);
		$this->assertStringNotContainsString( 'hashed-password', (string) json_encode( $this->scheduled ), 'Never the hash.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
	}

	public function test_a_never_used_application_password_has_an_empty_last_used(): void {
		$this->run_listener( 'wp_delete_application_password', 4, $this->item() );

		$this->assertSame( '', $this->scheduled[0]['data']['last_used'] );
	}

	public function test_a_revoked_application_password_of_another_account_is_not_reported(): void {
		$this->run_listener( 'wp_delete_application_password', 5, $this->item() );

		$this->assertSame( [], $this->scheduled );
	}

	public function test_the_first_use_of_an_application_password_is_reported_with_where_it_came_from(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
		$_SERVER['HTTP_USER_AGENT']      = 'Zapier';
		Functions\expect( 'wp_get_current_user' )->never();

		$this->run_listener( 'application_password_did_authenticate', $this->users[4], $this->item() );

		$this->assertSame( 'AppPasswordFirstUsed', $this->scheduled[0]['type'] );
		$this->assertSame(
			[
				'id'   => 4,
				'name' => 'Bob',
			],
			$this->scheduled[0]['user'],
			'The actor is the authenticated user.'
		);
		$this->assertSame(
			[
				'id'            => 4,
				'name'          => 'Bob',
				'email'         => 'bob@scanfully.com',
				'roles'         => [ 'administrator' ],
				'app_name'      => 'Zapier',
				'app_id'        => '6f8f1c2e-7a5b-4c3d-9e1f-2a3b4c5d6e7f',
				'ip'            => '203.0.113.9',
				'forwarded_for' => '198.51.100.7',
				'user_agent'    => 'Zapier',
			],
			$this->scheduled[0]['data']
		);
	}

	public function test_a_later_use_returns_before_any_other_work(): void {
		Functions\expect( 'get_option' )->never();
		Functions\expect( 'user_can' )->never();

		$this->run_listener( 'application_password_did_authenticate', $this->users[4], $this->item( [ 'last_used' => 1759492800 ] ) );

		$this->assertSame( [], $this->scheduled );
	}

	public function test_the_first_use_by_another_account_is_not_reported(): void {
		$this->run_listener( 'application_password_did_authenticate', $this->users[5], $this->item() );

		$this->assertSame( [], $this->scheduled );
		$this->assertFalse( ( new AppPasswordFirstUsed() )->should_fire( [ null, $this->item() ] ) );
	}
}
