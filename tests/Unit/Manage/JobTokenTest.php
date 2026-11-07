<?php
/**
 * Manage job token unit tests: nothing runs without Scanfully's signature
 * for this site, job, attempt and parameters.
 *
 * @package Scanfully\Tests\Unit\Manage
 */

namespace Scanfully\Tests\Unit\Manage;

use Brain\Monkey\Functions;
use Scanfully\Manage\JobToken;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Manage\JobToken
 * @covers \Scanfully\Sync\SignedRequest
 */
final class JobTokenTest extends TestCase {

	private const ISSUER = 'https://api.scanfully.com';
	private const SITE   = 'c0ffee00-0000-4000-8000-000000000001';
	private const JOB    = 'a1a1a1a1-1111-4111-8111-111111111111';

	/**
	 * The signing key.
	 *
	 * @var mixed
	 */
	private static $key;

	/**
	 * Another key, not published.
	 *
	 * @var mixed
	 */
	private static $other_key;

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
	 * The status the keys endpoint answers.
	 *
	 * @var int
	 */
	private int $keys_status = 200;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$settings        = [
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		];
		self::$key       = openssl_pkey_new( $settings );
		self::$other_key = openssl_pkey_new( $settings );
		$details         = openssl_pkey_get_details( self::$key );
		self::$jwks      = (string) json_encode(
			[
				'keys' => [
					[
						'kty' => 'RSA',
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
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'get_transient' )->alias( fn( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn() => $this->keys_status );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn() => self::$jwks );
		Functions\when( 'wp_remote_get' )->justReturn( [] );
	}

	private static function b64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * A job token, with claims changed.
	 *
	 * @param array<string, mixed> $claims Claims to change.
	 * @param mixed                $key    The key to sign with.
	 * @param string               $alg    The algorithm named in the header.
	 */
	private static function token( array $claims = [], $key = null, string $alg = 'RS256' ): string {
		$header  = self::b64url(
			(string) json_encode(
				[
					'alg' => $alg,
					'kid' => 'key-1',
				]
			)
		);
		$payload = self::b64url(
			(string) json_encode(
				array_filter(
					array_merge(
						[
							'iss'         => self::ISSUER,
							'sub'         => 'update_plugin',
							'aud'         => [ self::SITE ],
							'jti'         => self::JOB,
							'attempt'     => 2,
							'params_hash' => hash( 'sha256', '{"slug":"akismet","target_version":"5.3.1"}' ),
							'exp'         => time() + 900,
						],
						$claims
					),
					static fn( $value ) => null !== $value
				)
			)
		);
		openssl_sign( $header . '.' . $payload, $signature, $key ?? self::$key, OPENSSL_ALGO_SHA256 );

		return $header . '.' . $payload . '.' . self::b64url( $signature );
	}

	/**
	 * The job the token is for, as the pull keeps it.
	 *
	 * @param array<string, mixed> $change Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function job( array $change = [] ): array {
		return array_merge(
			[
				'id'        => self::JOB,
				'action'    => 'update_plugin',
				'params'    => [
					'target_version' => '5.3.1',
					'slug'           => 'akismet',
				],
				'attempt'   => 2,
				'job_token' => self::token(),
			],
			$change
		);
	}

	public function test_a_token_scanfully_signed_for_the_job_is_valid(): void {
		$this->assertSame( JobToken::VALID, JobToken::check( self::job(), self::SITE ) );
		$this->assertSame( JobToken::VALID, JobToken::check( self::job(), self::SITE ), 'not burned: a retried job is checked again' );
		$this->assertSame( JobToken::VALID, JobToken::check( self::job( [ 'job_token' => self::token( [ 'aud' => self::SITE ] ) ] ), self::SITE ), 'the audience as a string' );
	}

	/**
	 * @dataProvider provide_tokens_that_do_not_check_out
	 *
	 * @param array<string, mixed> $job   The job's fields to change.
	 * @param string               $label What is wrong.
	 */
	public function test_a_job_whose_token_does_not_check_out_is_invalid( array $job, string $label ): void {
		$this->assertSame( JobToken::INVALID, JobToken::check( self::job( $job ), self::SITE ), $label );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function provide_tokens_that_do_not_check_out(): array {
		self::setUpBeforeClass();

		return [
			'no token'               => [ [ 'job_token' => '' ], 'a job without a token, as an older API sends it' ],
			'not a JWT'              => [ [ 'job_token' => 'sfy_sat_token' ], 'the site token' ],
			'another key'            => [ [ 'job_token' => self::token( [], self::$other_key ) ], 'signed with a key Scanfully does not publish' ],
			'HS256'                  => [ [ 'job_token' => self::token( [], null, 'HS256' ) ], 'another algorithm' ],
			'another issuer'         => [ [ 'job_token' => self::token( [ 'iss' => 'https://api.scanfully.dev' ] ) ], 'another issuer' ],
			'another site'           => [ [ 'job_token' => self::token( [ 'aud' => [ 'another-site' ] ] ) ], 'another site' ],
			'more sites'             => [ [ 'job_token' => self::token( [ 'aud' => [ self::SITE, 'another-site' ] ] ) ], 'an audience of more sites' ],
			'another action'         => [ [ 'action' => 'update_theme' ], 'the job names another action than the token' ],
			'another job'            => [ [ 'id' => 'b2b2b2b2-2222-4222-8222-222222222222' ], 'the token of another job' ],
			'another attempt'        => [ [ 'attempt' => 1 ], 'the token of an earlier attempt' ],
			'attempt as text'        => [ [ 'job_token' => self::token( [ 'attempt' => '2' ] ) ], 'an attempt that is not a number' ],
			'other params'           => [
				[
					'params' => [
						'slug'           => 'akismet',
						'target_version' => '6.0.0',
					],
				],
				'parameters changed after signing',
			],
			'no params hash'         => [ [ 'job_token' => self::token( [ 'params_hash' => null ] ) ], 'no params_hash' ],
			'no exp'                 => [ [ 'job_token' => self::token( [ 'exp' => null ] ) ], 'no exp' ],
			'not yet valid'          => [ [ 'job_token' => self::token( [ 'nbf' => time() + 3600 ] ) ], 'nbf in the future' ],
			'tampered payload'       => [ [ 'job_token' => self::tampered() ], 'a payload changed after signing' ],
		];
	}

	/**
	 * A token whose payload names another job after it was signed.
	 */
	private static function tampered(): string {
		[ $header, , $signature ] = explode( '.', self::token() );
		$payload                  = self::b64url(
			(string) json_encode(
				[
					'iss'         => self::ISSUER,
					'sub'         => 'update_plugin',
					'aud'         => [ self::SITE ],
					'jti'         => self::JOB,
					'attempt'     => 2,
					'params_hash' => hash( 'sha256', '{"slug":"evil"}' ),
					'exp'         => time() + 900,
				]
			)
		);

		return $header . '.' . $payload . '.' . $signature;
	}

	public function test_a_token_past_its_lease_is_expired_not_invalid(): void {
		$job = self::job( [ 'job_token' => self::token( [ 'exp' => time() - 10 * MINUTE_IN_SECONDS ] ) ] );
		$this->assertSame( JobToken::EXPIRED, JobToken::check( $job, self::SITE ) );

		$job = self::job( [ 'job_token' => self::token( [ 'exp' => time() - 60 ] ) ] );
		$this->assertSame( JobToken::VALID, JobToken::check( $job, self::SITE ), 'within the clock leeway' );
	}

	public function test_the_params_json_kept_from_the_pull_is_what_is_hashed(): void {
		$job                = self::job();
		$job['params_json'] = '{"slug":"akismet","target_version":"5.3.1"}';
		$this->assertSame( JobToken::VALID, JobToken::check( $job, self::SITE ) );

		$job['params_json'] = '{"slug":"evil","target_version":"5.3.1"}';
		$this->assertSame( JobToken::INVALID, JobToken::check( $job, self::SITE ), 'the bytes the action reads changed after signing' );
	}

	public function test_the_canonical_json_sorts_keys_and_escapes_nothing_it_need_not(): void {
		$this->assertSame( '{}', JobToken::canonical_json( [], true ), 'empty parameters are an object' );
		$this->assertSame( '{}', JobToken::canonical_json( new \stdClass(), true ) );
		$this->assertSame(
			'{"a":1,"b":{"c":[],"d":{}},"slug":"ä/ö","z":[3,1,2]}',
			JobToken::canonical_json(
				json_decode( '{"z":[3,1,2],"slug":"ä/ö","b":{"d":{},"c":[]},"a":1}', false ),
				true
			),
			'keys sorted at every level, lists kept in order, UTF-8 and slashes as they are, {} and [] apart in the raw answer'
		);
		$this->assertSame(
			'{"slug":"akismet","target_version":"5.3.1"}',
			JobToken::canonical_json(
				[
					'target_version' => '5.3.1',
					'slug'           => 'akismet',
				],
				true
			)
		);
		$this->assertSame( '{"B":1,"a":2,"b":3}', JobToken::canonical_json( json_decode( '{"b":3,"a":2,"B":1}', true ), true ), 'byte order' );
	}

	public function test_the_params_hash_is_lowercase_hex_sha256_of_the_canonical_json(): void {
		$this->assertSame(
			'ef681fafaf8bc1196795aa07c2e6aefad2c843f1a72620d1e09b2f5b7f3ecd36',
			JobToken::params_hash(
				[
					'target_version' => '5.3.1',
					'slug'           => 'akismet',
				]
			),
			'printf %s \'{"slug":"akismet","target_version":"5.3.1"}\' | shasum -a 256'
		);
		$this->assertSame( '44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a', JobToken::params_hash( [] ), 'sha256 of {}' );
	}

	public function test_a_key_set_that_cannot_be_fetched_leaves_the_job_unverified_not_invalid(): void {
		$this->keys_status = 503;
		$this->assertSame( JobToken::UNVERIFIED, JobToken::check( self::job(), self::SITE ), 'the keys endpoint answers 503' );
		$this->assertSame( JobToken::UNVERIFIED, JobToken::check( self::job(), self::SITE ), 'within the refetch wait, still unverified' );

		// The next fetch works: a token Scanfully signed checks out.
		unset( $this->transients['scanfully_plugin_keys_refetched'] );
		$this->keys_status = 200;
		$this->assertSame( JobToken::VALID, JobToken::check( self::job(), self::SITE ) );
		$this->assertArrayNotHasKey( 'scanfully_plugin_keys_fetch_failed', $this->transients );
	}

	public function test_a_key_id_the_fetched_set_does_not_have_is_invalid(): void {
		$header = self::b64url(
			(string) json_encode(
				[
					'alg' => 'RS256',
					'kid' => 'key-unknown',
				]
			)
		);
		[ , $payload, $signature ] = explode( '.', self::token() );
		$job                       = self::job( [ 'job_token' => $header . '.' . $payload . '.' . $signature ] );

		$this->assertSame( JobToken::INVALID, JobToken::check( $job, self::SITE ), 'the key set was fetched and has no such key' );
		$this->assertSame( JobToken::INVALID, JobToken::check( $job, self::SITE ), 'also within the refetch wait' );
	}
}
