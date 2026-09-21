<?php
/**
 * Connect state unit tests.
 *
 * @package Scanfully\Tests\Unit\Connect
 */

namespace Scanfully\Tests\Unit\Connect;

use Brain\Monkey\Functions;
use RuntimeException;
use Scanfully\Connect\Controller;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Connect\Controller
 */
final class ControllerStateTest extends TestCase {

	/**
	 * In-memory transient store.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	/**
	 * Transient lifetimes, keyed by transient name.
	 *
	 * @var array<string, int>
	 */
	private array $expirations = [];

	/**
	 * The current user ID.
	 *
	 * @var int
	 */
	private int $user_id = 7;

	protected function setUp(): void {
		parent::setUp();
		$_GET = [];

		Functions\when( 'get_current_user_id' )->alias( fn() => $this->user_id );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_generate_password' )->alias( static fn( $length ) => str_repeat( 'a', $length ) );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $expiration ) {
				$this->transients[ $key ]  = $value;
				$this->expirations[ $key ] = $expiration;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function ( $message ) {
				throw new RuntimeException( (string) $message );
			}
		);

		// The token exchange must never run when the state check fails.
		Functions\expect( 'wp_remote_post' )->never();
	}

	protected function tearDown(): void {
		$_GET = [];
		parent::tearDown();
	}

	/**
	 * Simulate the return request from the Scanfully dashboard.
	 *
	 * @param array<string, string> $params Query parameters.
	 *
	 * @return string The wp_die() message.
	 */
	private function return_from_dashboard( array $params ): string {
		$_GET = array_merge( [ 'scanfully-connect-success' => 'true' ], $params );

		try {
			Controller::catch_connect_requests();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}

		$this->fail( 'The connect return request was not stopped.' );
	}

	public function test_generate_state_stores_a_long_state_per_user_for_fifteen_minutes(): void {
		$state = Controller::generate_state();

		$this->assertSame( 32, strlen( $state ) );
		$this->assertSame( $state, $this->transients['scanfully_connect_state_7'] );
		$this->assertSame( 15 * MINUTE_IN_SECONDS, $this->expirations['scanfully_connect_state_7'] );
	}

	public function test_get_state_is_empty_when_nothing_is_stored(): void {
		$this->assertSame( '', Controller::get_state() );
	}

	public function test_an_empty_state_is_rejected_when_nothing_is_stored(): void {
		$message = $this->return_from_dashboard(
			[
				'state' => '',
				'code'  => 'attacker-code',
				'site'  => 'attacker-site',
			]
		);

		$this->assertSame( 'Invalid Scanfully connect state', $message );
	}

	public function test_a_missing_state_is_rejected(): void {
		Controller::generate_state();

		$message = $this->return_from_dashboard(
			[
				'code' => 'code',
				'site' => 'site',
			]
		);

		$this->assertSame( 'Invalid Scanfully connect state', $message );
	}

	public function test_a_wrong_state_is_rejected_and_the_stored_state_is_used_up(): void {
		Controller::generate_state();

		$message = $this->return_from_dashboard(
			[
				'state' => 'wrong',
				'code'  => 'code',
				'site'  => 'site',
			]
		);

		$this->assertSame( 'Invalid Scanfully connect state', $message );
		$this->assertSame( '', Controller::get_state() );
	}

	public function test_a_state_started_by_another_user_is_rejected(): void {
		$state         = Controller::generate_state();
		$this->user_id = 8;

		$message = $this->return_from_dashboard(
			[
				'state' => $state,
				'code'  => 'code',
				'site'  => 'site',
			]
		);

		$this->assertSame( 'Invalid Scanfully connect state', $message );
	}

	public function test_a_valid_state_can_only_be_used_once(): void {
		$state  = Controller::generate_state();
		$params = [
			'state' => $state,
			'code'  => 'code',
			'site'  => 'not a valid site id',
		];

		// The state check passes; the invalid site stops the request before the token exchange.
		$this->assertSame( 'Invalid Scanfully connect parameters', $this->return_from_dashboard( $params ) );
		$this->assertSame( 'Invalid Scanfully connect state', $this->return_from_dashboard( $params ) );
	}

	public function test_site_ids_with_path_characters_are_rejected(): void {
		$message = $this->return_from_dashboard(
			[
				'state' => Controller::generate_state(),
				'code'  => 'code',
				'site'  => '../../users',
			]
		);

		$this->assertSame( 'Invalid Scanfully connect parameters', $message );
	}

	public function test_delete_state_also_removes_the_legacy_shared_state(): void {
		$this->transients['scanfully_connect_state'] = 'legacy';
		Controller::generate_state();

		Controller::delete_state();

		$this->assertSame( [], $this->transients );
	}
}
