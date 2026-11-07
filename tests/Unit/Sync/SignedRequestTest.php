<?php
/**
 * Signed request unit tests.
 *
 * @package Scanfully\Tests\Unit\Sync
 */

namespace Scanfully\Tests\Unit\Sync;

use Brain\Monkey\Functions;
use Scanfully\Sync\SignedRequest;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Sync\SignedRequest
 */
final class SignedRequestTest extends TestCase {

	private const ISSUER = 'https://api.scanfully.com';
	private const SITE   = 'site-1';

	/**
	 * The signing key: a resource on PHP 7, an object on PHP 8.
	 *
	 * @var mixed
	 */
	private static $key;

	/**
	 * The published key set.
	 *
	 * @var string
	 */
	private static string $jwks;

	/**
	 * In-memory transients.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	/**
	 * How often the key set was fetched.
	 *
	 * @var int
	 */
	private int $fetches = 0;

	/**
	 * The status the keys endpoint answers.
	 *
	 * @var int
	 */
	private int $keys_status = 200;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$key  = openssl_pkey_new(
			[
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);
		$details    = openssl_pkey_get_details( self::$key );
		self::$jwks = (string) json_encode(
			[
				'keys' => [
					[
						'kty' => 'RSA',
						'use' => 'sig',
						'alg' => 'RS256',
						'kid' => 'key-1',
						'n'   => self::b64url( $details['rsa']['n'] ),
						'e'   => self::b64url( $details['rsa']['e'] ),
					],
				],
			]
		);
	}

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_transient' )->alias( fn( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->alias(
			function ( string $url ) {
				$this->assertSame( self::ISSUER . SignedRequest::KEYS_PATH, $url );
				++$this->fetches;
				return [ 'body' => self::$jwks ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn() => $this->keys_status );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $resp ) => $resp['body'] );
	}

	private static function b64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * A request signed as the API signs it, with claims changed.
	 *
	 * @param array<string, mixed> $claims Claims to change.
	 * @param string               $kid    The key id.
	 */
	private function signed( array $claims = [], string $kid = 'key-1' ): string {
		$now     = time();
		$header  = self::b64url(
			(string) json_encode(
				[
					'alg' => 'RS256',
					'typ' => 'JWT',
					'kid' => $kid,
				]
			)
		);
		$payload = self::b64url(
			(string) json_encode(
				array_merge(
					[
						'iss'   => self::ISSUER,
						'sub'   => 'sync',
						'aud'   => [ self::SITE ],
						'iat'   => $now,
						'nbf'   => $now - 60,
						'exp'   => $now + 300,
						'jti'   => bin2hex( random_bytes( 8 ) ),
						'scope' => 'wordpress:read',
					],
					$claims
				)
			)
		);
		openssl_sign( $header . '.' . $payload, $signature, self::$key, OPENSSL_ALGO_SHA256 );

		return $header . '.' . $payload . '.' . self::b64url( $signature );
	}

	private function verify( string $token ): bool {
		return SignedRequest::verify( $token, self::SITE, 'sync', 'wordpress:read' );
	}

	public function test_a_request_scanfully_signed_for_the_site_passes_once(): void {
		$token = $this->signed();
		$this->assertTrue( SignedRequest::looks_signed( $token ) );
		$this->assertTrue( $this->verify( $token ) );
		$this->assertFalse( $this->verify( $token ), 'a replayed request' );
		$this->assertTrue( $this->verify( $this->signed() ) );
		$this->assertSame( 1, $this->fetches, 'the key set is cached' );
	}

	public function test_a_request_for_another_site_action_or_scope_fails(): void {
		$this->assertFalse( $this->verify( $this->signed( [ 'aud' => [ 'site-2' ] ] ) ) );
		$this->assertFalse( $this->verify( $this->signed( [ 'sub' => 'login' ] ) ) );
		$this->assertFalse( $this->verify( $this->signed( [ 'scope' => 'wordpress:update' ] ) ) );
		$this->assertFalse( $this->verify( $this->signed( [ 'iss' => 'https://evil.scanfully.dev' ] ) ) );
		$this->assertTrue( $this->verify( $this->signed( [ 'aud' => self::SITE ] ) ), 'a single audience' );
	}

	public function test_times_allow_some_clock_skew_only(): void {
		$this->assertTrue( $this->verify( $this->signed( [ 'exp' => time() - 60 ] ) ) );
		$this->assertFalse( $this->verify( $this->signed( [ 'exp' => time() - SignedRequest::LEEWAY - 60 ] ) ) );
		$this->assertFalse( $this->verify( $this->signed( [ 'nbf' => time() + SignedRequest::LEEWAY + 60 ] ) ) );
		$this->assertFalse( $this->verify( $this->signed( [ 'jti' => '' ] ) ) );
	}

	public function test_a_forged_or_tampered_request_fails(): void {
		$token = $this->signed();
		[ $header, $payload, $signature ] = explode( '.', $token );
		$other = self::b64url(
			(string) json_encode(
				[
					'iss' => self::ISSUER,
					'sub' => 'sync',
					'aud' => self::SITE,
					'exp' => time() + 60,
					'jti' => 'x',
					'scope' => 'wordpress:read',
				]
			)
		);
		$this->assertFalse( $this->verify( $header . '.' . $other . '.' . $signature ), 'another payload' );
		$none = self::b64url(
			(string) json_encode(
				[
					'alg' => 'none',
					'kid' => 'key-1',
				]
			)
		);
		$this->assertFalse( $this->verify( $none . '.' . $payload . '.' ), 'alg none' );
		$this->assertFalse( $this->verify( 'not.a.token' ) );
	}

	public function test_an_unknown_key_is_fetched_again_a_few_minutes_apart(): void {
		$this->assertTrue( $this->verify( $this->signed() ) );
		$this->assertFalse( $this->verify( $this->signed( [], 'key-2' ) ) );
		$this->assertFalse( $this->verify( $this->signed( [], 'key-3' ) ) );
		$this->assertSame( 1, $this->fetches, 'one fetch, then the refetch waits' );
	}

	public function test_a_key_set_that_cannot_be_fetched_is_tried_once_per_few_minutes(): void {
		$this->keys_status = 503;
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertFalse( $this->verify( $this->signed( [], 'forged-' . $i ) ), 'a forged request with an unknown key id' );
		}
		$this->assertFalse( $this->verify( $this->signed() ), 'a real request while the key set is unreachable' );
		$this->assertSame( 1, $this->fetches, 'one failed fetch, then the refetch waits' );

		// The wait ends and the key set answers again.
		unset( $this->transients['scanfully_plugin_keys_refetched'] );
		$this->keys_status = 200;
		$this->assertTrue( $this->verify( $this->signed() ) );
		$this->assertSame( 2, $this->fetches );
	}

	public function test_the_key_set_becomes_pem_keys(): void {
		$keys = SignedRequest::parse_keys( self::$jwks );
		$this->assertArrayHasKey( 'key-1', $keys );
		$this->assertNotFalse( openssl_pkey_get_public( $keys['key-1'] ) );
		$this->assertNull( SignedRequest::parse_keys( 'not json' ) );
	}
}
