<?php
/**
 * The base of the login unit tests: a connected site, Scanfully's key set
 * signed with a test key pair, and tokens signed as the API signs a sign-in
 * link.
 *
 * @package Scanfully\Tests\Unit\Login
 */

namespace Scanfully\Tests\Unit\Login;

use Brain\Monkey\Functions;
use Mockery;
use Scanfully\Tests\Unit\Manage\FakeWpdb;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * A connected site with wordpress:login granted, at https://scanfully.com.
 */
abstract class LoginTestCase extends TestCase {

	protected const ISSUER = 'https://api.scanfully.com';
	protected const SITE   = 'b1b1b1b1-1111-4111-8111-111111111111';
	protected const NOW    = 1800000000;

	/**
	 * The signing key: a resource on PHP 7, an object on PHP 8.
	 *
	 * @var mixed
	 */
	private static $key;

	/**
	 * Another key, which Scanfully never published.
	 *
	 * @var mixed
	 */
	protected static $other_key;

	/**
	 * The published key set.
	 *
	 * @var string
	 */
	private static string $jwks;

	/**
	 * The site's options.
	 *
	 * @var array<string, mixed>
	 */
	public array $options = [];

	/**
	 * In-memory transients.
	 *
	 * @var array<string, mixed>
	 */
	public array $transients = [];

	/**
	 * How often the key set was fetched.
	 *
	 * @var int
	 */
	public int $fetches = 0;

	/**
	 * The status the keys endpoint answers.
	 *
	 * @var int
	 */
	public int $keys_status = 200;

	/**
	 * The users, by id.
	 *
	 * @var array<int, \WP_User>
	 */
	public array $users = [];

	/**
	 * Whether the site is a network.
	 *
	 * @var bool
	 */
	public bool $network = false;

	/**
	 * The super admins of the network, by login.
	 *
	 * @var string[]
	 */
	public array $super_admins = [];

	/**
	 * The options table, for the burn list.
	 *
	 * @var FakeWpdb
	 */
	protected FakeWpdb $db;

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
		$this->db        = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->db;
		$this->options   = [
			'scanfully_connect_is_connected' => 'yes',
			'scanfully_connect_site_id'      => self::SITE,
			'scanfully_connect_flow'         => 'oauth',
			'scanfully_connect_scopes'       => 'wordpress:read wordpress:login',
			'scanfully_connect_address'      => 'scanfully.com',
			'home'                           => 'https://scanfully.com',
		];
		$this->users     = [
			1 => $this->user( 1, 'admin', [ 'administrator' ] ),
			2 => $this->user( 2, 'editor', [ 'editor' ] ),
		];

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_option' )->alias( fn( string $name, $fallback = false ) => $this->options[ $name ] ?? $fallback );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->alias(
			function ( string $url ) {
				$this->assertSame( self::ISSUER . '/.well-known/scanfully-plugin-keys.json', $url );
				++$this->fetches;
				return [ 'body' => self::$jwks ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn() => $this->keys_status );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $resp ) => $resp['body'] );
		Functions\when( 'get_userdata' )->alias( fn( $id ) => $this->users[ $id ] ?? false );
		Functions\when( 'is_multisite' )->alias( fn() => $this->network );
		Functions\when( 'is_super_admin' )->alias(
			function ( $id ) {
				$user = $this->users[ $id ] ?? null;
				return null !== $user && in_array( $user->user_login, $this->super_admins, true );
			}
		);
		Functions\when( 'wp_is_using_https' )->justReturn( true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * A user.
	 *
	 * @param int      $id    The id.
	 * @param string   $login The login.
	 * @param string[] $roles The roles.
	 *
	 * @return \WP_User
	 */
	protected function user( int $id, string $login, array $roles ): \WP_User {
		$user               = Mockery::mock( 'WP_User' );
		$user->ID           = $id;
		$user->user_login   = $login;
		$user->display_name = ucfirst( $login );
		$user->first_name   = '';
		$user->last_name    = '';
		$user->roles        = $roles;

		return $user;
	}

	protected static function b64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * The claims of a sign-in link as the API mints it.
	 *
	 * @return array<string, mixed>
	 */
	protected function login_claims(): array {
		return [
			'iss'     => self::ISSUER,
			'aud'     => self::SITE,
			'host'    => 'scanfully.com',
			'sub'     => 'login',
			'jti'     => 'c3c3c3c3-3333-4333-8333-' . bin2hex( random_bytes( 6 ) ),
			'iat'     => self::NOW,
			'exp'     => self::NOW + 60,
			'uid'     => 'd4d4d4d4-4444-4444-8444-444444444444',
			'wp_user' => 1,
			'name'    => 'Barry Kooij',
			'via'     => 'dashboard',
		];
	}

	/**
	 * A token signed as the API signs one.
	 *
	 * @param array<string, mixed> $changes Claims to change; null removes one.
	 * @param array<string, mixed> $header  Header fields to change.
	 * @param mixed                $key     The key to sign with; Scanfully's when null.
	 *
	 * @return string
	 */
	protected function signed( array $changes = [], array $header = [], $key = null ): string {
		$claims = array_filter(
			array_merge( $this->login_claims(), $changes ),
			static function ( $value ): bool {
				return null !== $value;
			}
		);
		$head   = self::b64url(
			(string) json_encode(
				array_merge(
					[
						'alg' => 'RS256',
						'typ' => 'JWT',
						'kid' => 'key-1',
					],
					$header
				)
			)
		);
		$body   = self::b64url( (string) json_encode( $claims ) );
		openssl_sign( $head . '.' . $body, $signature, $key ?? self::$key, OPENSSL_ALGO_SHA256 );

		return $head . '.' . $body . '.' . self::b64url( $signature );
	}
}
