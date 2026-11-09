<?php
/**
 * Scanfully login event unit tests.
 *
 * @package Scanfully\Tests\Unit\Events
 */

namespace Scanfully\Tests\Unit\Events;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Scanfully\Events\ScanfullyLogin;
use Scanfully\Login\Controller as Login;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\ScanfullyLogin
 */
final class ScanfullyLoginTest extends TestCase {

	/**
	 * The jobs scheduled.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $scheduled = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->justReturn( 'yes' );
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $value ) => trim( strip_tags( (string) $value ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress is not loaded in unit tests.
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args ) {
				$this->scheduled[] = $args;
				return 1;
			}
		);
	}

	/**
	 * The administrator signed in.
	 *
	 * @return \WP_User
	 */
	private function user() {
		$user               = Mockery::mock( 'WP_User' );
		$user->ID           = 1;
		$user->display_name = 'Site Owner';
		$user->first_name   = '';
		$user->last_name    = '';
		$user->roles        = [ 'administrator' ];
		$user->user_login   = 'admin';
		$user->user_email   = 'owner@scanfully.com';

		return $user;
	}

	public function test_it_listens_to_the_login(): void {
		Actions\expectAdded( Login::ACTION_SIGNED_IN )->once()->with( Mockery::type( 'array' ), 10, 2 );

		new ScanfullyLogin();
	}

	public function test_a_login_names_the_scanfully_user_and_the_wordpress_user(): void {
		Functions\expect( 'wp_get_current_user' )->never();

		( new ScanfullyLogin() )->listener_callback(
			$this->user(),
			[
				'name' => 'Barry Kooij',
				'via'  => 'dashboard',
			]
		);

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( 'ScanfullyLogin', $this->scheduled[0]['type'] );
		$this->assertSame(
			[
				'id'   => 1,
				'name' => 'Site Owner',
			],
			$this->scheduled[0]['user'],
			'the actor is the WordPress user signed in'
		);
		$this->assertSame(
			[
				'scanfully_user' => 'Barry Kooij',
				'via'            => 'dashboard',
				'wp_user_id'     => 1,
				'wp_user_login'  => 'admin',
			],
			$this->scheduled[0]['data']
		);
	}

	public function test_an_api_token_name_is_sent_as_plain_text(): void {
		( new ScanfullyLogin() )->listener_callback(
			$this->user(),
			[
				'name' => '<b>Deploy bot</b>',
				'via'  => 'api_token',
			]
		);

		$this->assertSame( 'Deploy bot', $this->scheduled[0]['data']['scanfully_user'] );
		$this->assertSame( 'api_token', $this->scheduled[0]['data']['via'] );
	}

	public function test_nothing_is_sent_without_a_user(): void {
		( new ScanfullyLogin() )->listener_callback(
			null,
			[
				'name' => 'Barry Kooij',
				'via'  => 'dashboard',
			]
		);

		$this->assertSame( [], $this->scheduled );
	}
}
