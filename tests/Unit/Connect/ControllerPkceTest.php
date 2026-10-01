<?php
/**
 * Connect PKCE unit tests.
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
final class ControllerPkceTest extends TestCase {

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

	protected function setUp(): void {
		parent::setUp();
		$_GET = [];

		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
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
	}

	protected function tearDown(): void {
		$_GET = [];
		parent::tearDown();
	}

	public function test_the_verifier_is_64_random_characters_per_user_for_fifteen_minutes(): void {
		Functions\when( 'wp_generate_password' )->alias( static fn( $length ) => str_repeat( 'b', $length ) );

		$verifier = Controller::generate_code_verifier();

		$this->assertSame( 64, strlen( $verifier ) );
		$this->assertSame( $verifier, $this->transients['scanfully_connect_verifier_7'] );
		$this->assertSame( 15 * MINUTE_IN_SECONDS, $this->expirations['scanfully_connect_verifier_7'] );
		$this->assertSame( $verifier, Controller::get_code_verifier() );
	}

	public function test_the_challenge_is_the_s256_of_rfc_7636(): void {
		$this->assertSame(
			'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
			Controller::code_challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' )
		);
	}

	public function test_the_exchange_sends_the_verifier_once(): void {
		$this->transients['scanfully_connect_state_7']    = 'the-state';
		$this->transients['scanfully_connect_verifier_7'] = 'the-verifier';

		$sent = null;
		Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
			static function ( $url, $args ) use ( &$sent ) {
				$sent = json_decode( $args['body'], true );
				return [ 'response' => [ 'code' => 400 ] ];
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 400 );

		$_GET = [
			'scanfully-connect-success' => 'true',
			'state'                     => 'the-state',
			'code'                      => 'the-code',
			'site'                      => 'site-1',
		];
		try {
			Controller::catch_connect_requests();
			$this->fail( 'A refused exchange did not stop the request.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Could not complete Scanfully connection', $e->getMessage() );
		}

		$this->assertSame(
			[
				'grant_type'    => 'authorization_code',
				'code'          => 'the-code',
				'site_id'       => 'site-1',
				'code_verifier' => 'the-verifier',
			],
			$sent
		);
		$this->assertArrayNotHasKey( 'scanfully_connect_verifier_7', $this->transients );
	}

	public function test_without_a_stored_verifier_the_exchange_sends_none(): void {
		$this->transients['scanfully_connect_state_7'] = 'the-state';

		$sent = null;
		Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
			static function ( $url, $args ) use ( &$sent ) {
				$sent = json_decode( $args['body'], true );
				return [ 'response' => [ 'code' => 400 ] ];
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 400 );

		$_GET = [
			'scanfully-connect-success' => 'true',
			'state'                     => 'the-state',
			'code'                      => 'the-code',
			'site'                      => 'site-1',
		];
		try {
			Controller::catch_connect_requests();
		} catch ( RuntimeException $e ) {
			// The refused exchange stops the request.
		}

		$this->assertArrayNotHasKey( 'code_verifier', (array) $sent );
	}
}
