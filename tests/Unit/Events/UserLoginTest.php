<?php
/**
 * User login event unit tests.
 *
 * @package Scanfully\Tests\Unit\Events
 */

namespace Scanfully\Tests\Unit\Events;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Scanfully\Events\FailedLogins\Store;
use Scanfully\Events\UserLogin;
use Scanfully\Tests\Unit\Events\FailedLogins\FakeCounterTable;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\UserLogin
 * @covers \Scanfully\Events\UserEvent
 * @covers \Scanfully\Events\Event::listener_callback
 */
final class UserLoginTest extends TestCase {

	/**
	 * The $_SERVER values a test changes.
	 */
	private const SERVER_KEYS = [ 'REMOTE_ADDR', 'HTTP_USER_AGENT', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ];

	/**
	 * $_SERVER before the test.
	 *
	 * @var array<string, mixed>
	 */
	private array $server = [];

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
	 * The failed login counters.
	 *
	 * @var FakeCounterTable
	 */
	private FakeCounterTable $counters;

	protected function setUp(): void {
		parent::setUp();
		$this->counters = FakeCounterTable::install();
		$this->server   = $_SERVER;
		foreach ( self::SERVER_KEYS as $key ) {
			unset( $_SERVER[ $key ] );
		}

		Functions\when( 'user_can' )->alias( static fn( $user ) => [] !== array_intersect( (array) $user->roles, [ 'administrator', 'editor' ] ) );
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $value ) => trim( strip_tags( (string) $value ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress is not loaded in unit tests.
		Functions\when( 'get_transient' )->alias( fn( string $key ) => array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_option' )->justReturn( 'yes' );
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
	 * A user double.
	 *
	 * @param array<int, string> $roles Roles.
	 * @param string             $name  Display name.
	 *
	 * @return \WP_User
	 */
	private function user( array $roles, string $name = 'Bob' ) {
		$user               = Mockery::mock( 'WP_User' );
		$user->ID           = 4;
		$user->display_name = $name;
		$user->first_name   = '';
		$user->last_name    = '';
		$user->roles        = $roles;
		$user->user_login   = 'bob';
		$user->user_email   = 'bob@scanfully.com';

		return $user;
	}

	public function test_a_login_after_wrong_passwords_on_the_account_says_how_many(): void {
		$hour = Store::hour();
		$this->counters->rows[ 'scanfully_lf_' . $hour . '_u_4' ]         = 9;
		$this->counters->rows[ 'scanfully_lf_' . ( $hour - 1 ) . '_u_4' ] = 5;
		$this->counters->rows[ 'scanfully_lf_' . ( $hour - 2 ) . '_u_4' ] = 100;
		$this->counters->rows[ 'scanfully_lf_' . $hour . '_u_5' ]         = 3;

		( new UserLogin() )->listener_callback( 'bob', $this->user( [ 'administrator' ] ) );

		$this->assertSame( 14, $this->scheduled[0]['data']['failed_before'] );
	}

	public function test_a_login_without_wrong_passwords_has_no_count(): void {
		( new UserLogin() )->listener_callback( 'bob', $this->user( [ 'administrator' ] ) );

		$this->assertArrayNotHasKey( 'failed_before', $this->scheduled[0]['data'] );
	}

	public function test_a_login_with_elevated_rights_is_reported_with_address_and_browser(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 203.0.113.9';
		$_SERVER['HTTP_USER_AGENT']      = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/129.0';

		Functions\expect( 'wp_get_current_user' )->never();
		( new UserLogin() )->listener_callback( 'bob', $this->user( [ 'administrator' ] ) );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( 'UserLogin', $this->scheduled[0]['type'] );
		$this->assertSame(
			[
				'id'   => 4,
				'name' => 'Bob',
			],
			$this->scheduled[0]['user'],
			'The actor is the user logging in.'
		);
		$this->assertSame(
			[
				'id'            => 4,
				'name'          => 'Bob',
				'email'         => 'bob@scanfully.com',
				'roles'         => [ 'administrator' ],
				'ip'            => '203.0.113.9',
				'forwarded_for' => '198.51.100.7',
				'user_agent'    => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/129.0',
			],
			$this->scheduled[0]['data']
		);
	}

	public function test_a_login_without_elevated_rights_is_not_reported(): void {
		( new UserLogin() )->listener_callback( 'bob', $this->user( [ 'subscriber' ] ) );
		( new UserLogin() )->listener_callback( 'bob', $this->user( [ 'customer' ] ) );

		$this->assertSame( [], $this->scheduled );
	}

	public function test_a_login_without_a_user_is_not_reported(): void {
		$this->assertFalse( ( new UserLogin() )->should_fire( [ 'bob', null ] ) );
		$this->assertSame( [], ( new UserLogin() )->get_post_body( [ 'bob', null ] ) );
	}

	public function test_the_actor_name_is_scrubbed_but_the_subject_email_is_sent(): void {
		( new UserLogin() )->listener_callback( 'bob', $this->user( [ 'administrator' ], 'bob@scanfully.com' ) );

		$this->assertSame( '', $this->scheduled[0]['user']['name'] );
		$this->assertSame( '', $this->scheduled[0]['data']['name'] );
		$this->assertSame( 'bob@scanfully.com', $this->scheduled[0]['data']['email'] );
	}

	public function test_logins_share_the_user_event_cap(): void {
		Filters\expectApplied( 'scanfully_user_events_per_minute' )->andReturn( 1 );
		$event = new UserLogin();

		$this->assertTrue( $event->should_fire( [ 'bob', $this->user( [ 'administrator' ] ) ] ) );
		$this->assertFalse( $event->should_fire( [ 'bob', $this->user( [ 'administrator' ] ) ] ) );
	}

	/**
	 * @dataProvider provide_forwarded_headers
	 *
	 * @param array<string, string> $headers  $_SERVER header values.
	 * @param string                $expected The forwarded_for sent.
	 */
	public function test_the_forwarded_address( array $headers, string $expected ): void {
		foreach ( $headers as $key => $value ) {
			$_SERVER[ $key ] = $value;
		}

		$body = ( new UserLogin() )->get_post_body( [ 'bob', $this->user( [ 'administrator' ] ) ] );

		$this->assertSame( $expected, $body['forwarded_for'] );
	}

	/**
	 * @return array<string, array{array<string, string>, string}>
	 */
	public function provide_forwarded_headers(): array {
		return [
			'no proxy headers'                  => [ [], '' ],
			'Cloudflare first'                  => [
				[
					'HTTP_CF_CONNECTING_IP' => '198.51.100.7',
					'HTTP_X_REAL_IP'        => '198.51.100.8',
					'HTTP_X_FORWARDED_FOR'  => '198.51.100.9',
				],
				'198.51.100.7',
			],
			'X-Real-IP before X-Forwarded-For'  => [
				[
					'HTTP_X_REAL_IP'       => '198.51.100.8',
					'HTTP_X_FORWARDED_FOR' => '198.51.100.9',
				],
				'198.51.100.8',
			],
			'first of a forwarded list'         => [ [ 'HTTP_X_FORWARDED_FOR' => ' 2001:db8::1 , 198.51.100.9, 10.0.0.1' ], '2001:db8::1' ],
			'an invalid Cloudflare value'       => [
				[
					'HTTP_CF_CONNECTING_IP' => 'unknown',
					'HTTP_X_FORWARDED_FOR'  => '198.51.100.9',
				],
				'198.51.100.9',
			],
			'a spoofed list starting with junk' => [ [ 'HTTP_X_FORWARDED_FOR' => '<script>, 198.51.100.9' ], '' ],
			'an address with a port'            => [ [ 'HTTP_X_REAL_IP' => '198.51.100.8:4431' ], '' ],
			'a script instead of an address'    => [ [ 'HTTP_X_REAL_IP' => '"><script>alert(1)</script>' ], '' ],
		];
	}

	public function test_an_invalid_remote_address_is_sent_empty(): void {
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';

		$this->assertSame( '', ( new UserLogin() )->get_post_body( [ 'bob', $this->user( [ 'administrator' ] ) ] )['ip'] );
	}

	public function test_the_user_agent_is_cut_to_500_characters(): void {
		$_SERVER['HTTP_USER_AGENT'] = str_repeat( 'a', 800 );

		$this->assertSame( 500, strlen( ( new UserLogin() )->get_post_body( [ 'bob', $this->user( [ 'administrator' ] ) ] )['user_agent'] ) );
	}
}
