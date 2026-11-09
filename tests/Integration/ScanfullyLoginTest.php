<?php
/**
 * One-click WP admin access integration tests: a sign-in link checked in a
 * real WordPress, with real users and roles, the real options table for the
 * burn list and Action Scheduler for the activity event. The token is signed
 * with a test key pair whose public key is put where the plugin caches
 * Scanfully's key set, so it goes through the same verifier as every signed
 * request.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Connect\Address;
use Scanfully\Events\Controller as Events;
use Scanfully\Health\Capabilities;
use Scanfully\Login\Burns;
use Scanfully\Login\Controller;
use Scanfully\Login\Log;
use Scanfully\Main;
use Scanfully\Sync\SignedRequest;

/**
 * The checks of a sign-in link against a real WordPress.
 */
final class ScanfullyLoginTest extends TestCase {

	private const SITE = 'b1b1b1b1-1111-4111-8111-111111111111';

	private const OPTIONS = [ 'is_connected', 'site_id', 'flow', 'scopes', 'address', 'address_shown' ];

	/**
	 * The signing key.
	 *
	 * @var mixed
	 */
	private $key;

	/**
	 * The options as they were.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved = [];

	/**
	 * The users made for the test.
	 *
	 * @var int[]
	 */
	private array $made = [];

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_insert_user' ) ) {
			$this->markTestSkipped( 'WordPress runtime not available. Run this suite under wp-env.' );
		}
		foreach ( self::OPTIONS as $name ) {
			$this->saved[ $name ] = get_option( 'scanfully_connect_' . $name, null );
		}
		update_option( 'scanfully_connect_is_connected', 'yes' );
		update_option( 'scanfully_connect_site_id', self::SITE );
		update_option( 'scanfully_connect_flow', 'oauth' );
		update_option( 'scanfully_connect_scopes', 'wordpress:read wordpress:login' );
		Address::remember();

		$this->key = openssl_pkey_new(
			[
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);
		$details   = openssl_pkey_get_details( $this->key );
		set_transient( 'scanfully_plugin_keys', [ 'key-it' => SignedRequest::rsa_pem( $details['rsa']['n'], $details['rsa']['e'] ) ], HOUR_IN_SECONDS );

		delete_option( Burns::OPTION );
		delete_option( Log::OPTION );
		delete_transient( 'scanfully_login_attempts' );
	}

	protected function tearDown(): void {
		if ( function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( $this->made as $id ) {
				wp_delete_user( $id );
			}
			foreach ( $this->saved as $name => $value ) {
				null === $value ? delete_option( 'scanfully_connect_' . $name ) : update_option( 'scanfully_connect_' . $name, $value );
			}
			delete_transient( 'scanfully_plugin_keys' );
			delete_option( Burns::OPTION );
			delete_option( Log::OPTION );
			delete_transient( 'scanfully_login_attempts' );
			as_unschedule_all_actions( Events::ACTION_SEND_EVENT, [], 'scanfully' );
		}
		parent::tearDown();
	}

	/**
	 * A user with a role.
	 *
	 * @param string $role The role.
	 *
	 * @return int
	 */
	private function make_user( string $role ): int {
		$id           = (int) wp_insert_user(
			[
				'user_login' => 'scanfully-login-' . $role . '-' . wp_rand(),
				'user_pass'  => wp_generate_password(),
				'role'       => $role,
			]
		);
		$this->made[] = $id;

		return $id;
	}

	private static function b64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * A sign-in link's token.
	 *
	 * @param int                  $wp_user The WordPress user.
	 * @param array<string, mixed> $changes Claims to change.
	 *
	 * @return string
	 */
	private function token( int $wp_user, array $changes = [] ): string {
		$head = self::b64url(
			(string) wp_json_encode(
				[
					'alg' => 'RS256',
					'typ' => 'JWT',
					'kid' => 'key-it',
				]
			)
		);
		$body = self::b64url(
			(string) wp_json_encode(
				array_merge(
					[
						'iss'     => Main::get_oauth_url(),
						'aud'     => self::SITE,
						'host'    => Address::current(),
						'sub'     => 'login',
						'jti'     => wp_generate_uuid4(),
						'iat'     => time(),
						'exp'     => time() + 60,
						'uid'     => wp_generate_uuid4(),
						'wp_user' => $wp_user,
						'name'    => 'Barry Kooij',
						'via'     => 'dashboard',
					],
					$changes
				)
			)
		);
		openssl_sign( $head . '.' . $body, $signature, $this->key, OPENSSL_ALGO_SHA256 );

		return $head . '.' . $body . '.' . self::b64url( $signature );
	}

	public function test_a_link_signs_an_administrator_in_once(): void {
		$admin = $this->make_user( 'administrator' );
		$token = $this->token( $admin );

		$first = Controller::attempt( $token, 'GET', true, time() );
		$this->assertSame( '', $first['reason'] );
		$this->assertSame( $admin, (int) $first['user']->ID );

		$again = Controller::attempt( $token, 'GET', true, time() );
		$this->assertSame( Controller::REASON_REPLAYED, $again['reason'] );
		$this->assertSame( [ Controller::REASON_REPLAYED ], array_column( Log::recent(), 'reason' ) );
	}

	public function test_the_burn_list_is_one_row_that_is_not_autoloaded(): void {
		Controller::attempt( $this->token( $this->make_user( 'administrator' ) ), 'GET', true, time() );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The test reads the raw row.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", Burns::OPTION ), ARRAY_A );
		$this->assertIsArray( $row );
		$this->assertContains( $row['autoload'], [ 'no', 'off' ] );
		$this->assertCount( 1, json_decode( $row['option_value'], true ) );
	}

	public function test_an_editor_a_missing_user_and_a_revoked_scope_are_refused(): void {
		$this->assertSame( Controller::REASON_USER, Controller::attempt( $this->token( $this->make_user( 'editor' ) ), 'GET', true, time() )['reason'] );
		$this->assertSame( Controller::REASON_USER, Controller::attempt( $this->token( 987654 ), 'GET', true, time() )['reason'] );

		update_option( 'scanfully_connect_scopes', 'wordpress:read wordpress:manage' );
		$this->assertSame( Controller::REASON_SCOPE, Controller::attempt( $this->token( $this->make_user( 'administrator' ) ), 'GET', true, time() )['reason'] );
	}

	public function test_another_sites_token_and_a_job_token_are_refused(): void {
		$admin = $this->make_user( 'administrator' );

		$this->assertSame( Controller::REASON_AUDIENCE, Controller::attempt( $this->token( $admin, [ 'aud' => 'another-site' ] ), 'GET', true, time() )['reason'] );
		$this->assertSame( Controller::REASON_PURPOSE, Controller::attempt( $this->token( $admin, [ 'sub' => 'update_plugin' ] ), 'GET', true, time() )['reason'] );
		$this->assertSame( Controller::REASON_NOT_HTTPS, Controller::attempt( $this->token( $admin ), 'GET', false, time() )['reason'] );
	}

	public function test_a_link_for_another_address_is_refused_and_not_burned(): void {
		$admin = $this->make_user( 'administrator' );
		$token = $this->token( $admin, [ 'host' => 'evil.example' ] );

		$this->assertSame( Controller::REASON_ADDRESS, Controller::attempt( $token, 'GET', true, time() )['reason'] );
		$this->assertFalse( get_option( Burns::OPTION ), 'refused before its burn' );
	}

	public function test_a_login_queues_the_activity_event(): void {
		$admin  = $this->make_user( 'administrator' );
		$result = Controller::attempt( $this->token( $admin, [ 'via' => 'mcp' ] ), 'GET', true, time() );

		do_action( Controller::ACTION_SIGNED_IN, $result['user'], $result['login'] );

		$queued = as_get_scheduled_actions(
			[
				'hook'     => Events::ACTION_SEND_EVENT,
				'group'    => 'scanfully',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			]
		);
		$events = array_values(
			array_filter(
				array_map(
					static function ( $action ) {
						return $action->get_args();
					},
					$queued
				),
				static function ( array $args ): bool {
					return 'ScanfullyLogin' === ( $args['type'] ?? '' );
				}
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( $admin, $events[0]['user']['id'] );
		$this->assertSame(
			[
				'scanfully_user' => 'Barry Kooij',
				'via'            => 'mcp',
				'wp_user_id'     => $admin,
				'wp_user_login'  => get_userdata( $admin )->user_login,
			],
			$events[0]['data']
		);
	}

	public function test_the_capabilities_list_the_administrators_and_no_login_over_http(): void {
		$admin = $this->make_user( 'administrator' );

		$capabilities = Capabilities::get();

		$this->assertContains( $admin, array_column( $capabilities['administrators'], 'id' ) );
		$this->assertSame( wp_is_using_https(), in_array( 'login', $capabilities['actions'], true ), 'login only where the site is served over HTTPS' );
	}
}
