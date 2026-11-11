<?php
/**
 * Administrators unit tests: who a sign-in link may sign in as.
 *
 * @package Scanfully\Tests\Unit\Login
 */

namespace Scanfully\Tests\Unit\Login;

use Brain\Monkey\Functions;
use Scanfully\Login\Administrators;

/**
 * @covers \Scanfully\Login\Administrators
 */
final class AdministratorsTest extends LoginTestCase {

	public function test_a_site_lists_its_administrators_by_id(): void {
		$owner               = $this->user( 1, 'admin', [ 'administrator' ] );
		$owner->display_name = 'Site Owner';
		$second              = $this->user( 7, 'second', [ 'administrator', 'editor' ] );
		Functions\expect( 'get_users' )->once()->with(
			[
				'role'        => 'administrator',
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'number'      => 50,
				'count_total' => false,
			]
		)->andReturn( [ $owner, $second ] );

		$this->assertSame(
			[
				[
					'id'           => 1,
					'login'        => 'admin',
					'display_name' => 'Site Owner',
				],
				[
					'id'           => 7,
					'login'        => 'second',
					'display_name' => 'Second',
				],
			],
			Administrators::list()
		);
	}

	public function test_a_display_name_that_is_an_email_address_is_not_sent(): void {
		Functions\when( 'is_email' )->alias( fn( string $value ) => false !== filter_var( $value, FILTER_VALIDATE_EMAIL ) );
		$user               = $this->user( 1, 'owner', [ 'administrator' ] );
		$user->display_name = 'owner@scanfully.com';
		$user->first_name   = 'Site';
		$user->last_name    = 'Owner';
		Functions\when( 'get_users' )->justReturn( [ $user ] );

		$this->assertSame( 'Site Owner', Administrators::list()[0]['display_name'] );
	}

	public function test_a_network_lists_no_one(): void {
		$this->network = true;
		Functions\when( 'get_super_admins' )->justReturn( [ 'admin1' ] );

		$this->assertSame( [], Administrators::list(), 'a connection on a network is for monitoring only' );
	}

	public function test_the_super_admins_are_read_by_id_at_most_fifty(): void {
		$this->network = true;
		$this->users   = [];
		$logins        = [];
		for ( $id = 60; $id >= 1; $id-- ) {
			$this->users[ $id ] = $this->user( $id, 'admin' . $id, [] );
			$logins[]           = 'admin' . $id;
		}
		Functions\when( 'get_super_admins' )->justReturn( array_merge( $logins, [ 'gone' ] ) );
		Functions\when( 'get_user_by' )->alias(
			function ( string $field, string $login ) {
				foreach ( $this->users as $user ) {
					if ( $user->user_login === $login ) {
						return $user;
					}
				}
				return false;
			}
		);

		// The network connection (made by a super admin, not built yet) will
		// sign in super admins; the reading of them is kept for it.
		$method = new \ReflectionMethod( Administrators::class, 'super_admins' );
		$method->setAccessible( true );
		$list = array_map( static fn( $user ): int => (int) $user->ID, $method->invoke( null ) );

		$this->assertCount( 50, $list );
		$this->assertSame( range( 1, 50 ), $list );
	}

	public function test_an_administrator_is_one_by_role_or_on_a_network_a_super_admin(): void {
		$this->assertTrue( Administrators::is_administrator( $this->users[1] ) );
		$this->assertFalse( Administrators::is_administrator( $this->users[2] ) );

		$this->network      = true;
		$this->super_admins = [ 'editor' ];
		$this->assertFalse( Administrators::is_administrator( $this->users[1] ) );
		$this->assertTrue( Administrators::is_administrator( $this->users[2] ) );
	}
}
