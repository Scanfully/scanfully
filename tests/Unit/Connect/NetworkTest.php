<?php
/**
 * Network connection unit tests.
 *
 * @package Scanfully\Tests\Unit\Connect
 */

namespace Scanfully\Tests\Unit\Connect;

use Brain\Monkey\Functions;
use RuntimeException;
use Scanfully\Connect\Controller;
use Scanfully\Connect\Multisite;
use Scanfully\Connect\Network;
use Scanfully\Options\Controller as OptionsController;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Connect\Network
 * @covers \Scanfully\Connect\Controller
 * @covers \Scanfully\Options\Controller
 */
final class NetworkTest extends TestCase {

	/**
	 * The main site's own options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * The network's options.
	 *
	 * @var array<string, mixed>
	 */
	private array $site_options = [];

	/**
	 * Transients.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	/**
	 * The requests sent to the API: URL and arguments.
	 *
	 * @var array<int, array{string, array<string, mixed>}>
	 */
	private array $posts = [];

	/**
	 * Whether this request is in Network Admin.
	 *
	 * @var bool
	 */
	private bool $network_admin = false;

	/**
	 * Whether the current site is the main site.
	 *
	 * @var bool
	 */
	private bool $main_site = true;

	/**
	 * The capabilities of the current user.
	 *
	 * @var string[]
	 */
	private array $caps = [ 'manage_options', 'manage_network_options' ];

	protected function setUp(): void {
		parent::setUp();
		$this->site_options = [ 'active_sitewide_plugins' => [ 'scanfully/scanfully.php' => time() ] ];
		$this->options      = [ 'home' => 'https://scanfully.com' ];

		Functions\when( '__' )->returnArg();
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_main_site' )->alias( fn(): bool => $this->main_site );
		Functions\when( 'is_network_admin' )->alias( fn(): bool => $this->network_admin );
		Functions\when( 'plugin_basename' )->justReturn( 'scanfully/scanfully.php' );
		Functions\when( 'current_user_can' )->alias( fn( string $cap ): bool => in_array( $cap, $this->caps, true ) );
		Functions\when( 'get_option' )->alias( fn( string $name, $fallback = false ) => $this->options[ $name ] ?? $fallback );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_site_option' )->alias( fn( string $name, $fallback = false ) => $this->site_options[ $name ] ?? $fallback );
		Functions\when( 'update_site_option' )->alias(
			function ( string $name, $value ) {
				$this->site_options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_site_option' )->alias(
			function ( string $name ) {
				unset( $this->site_options[ $name ] );
				return true;
			}
		);
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
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'wp_generate_password' )->alias( static fn( $length ) => str_repeat( 's', $length ) );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'untrailingslashit' )->alias( static fn( string $url ): string => rtrim( $url, '/' ) );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'home_url' )->justReturn( 'https://scanfully.com' );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://scanfully.com/wp-admin/' . $path );
		Functions\when( 'network_admin_url' )->alias( static fn( string $path = '' ): string => 'https://scanfully.com/wp-admin/network/' . $path );
		Functions\when( 'add_query_arg' )->alias(
			static function ( ...$args ): string {
				if ( is_array( $args[0] ) ) {
					return $args[1] . ( false === strpos( $args[1], '?' ) ? '?' : '&' ) . http_build_query( $args[0] );
				}
				return $args[2] . ( false === strpos( $args[2], '?' ) ? '?' : '&' ) . $args[0] . '=' . $args[1];
			}
		);
		Functions\when( 'wp_get_current_user' )->justReturn( (object) [ 'display_name' => 'Super' ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_post' )->alias(
			function ( string $url, array $args ) {
				$this->posts[] = [ $url, $args ];
				return [
					200,
					(string) json_encode(
						[
							'access_token'  => 'sfy_sat_network',
							'refresh_token' => 'sfy_srt_network',
							'expires_in'    => 2419200,
							'scope'         => 'wordpress:read',
							'site_id'       => 'network-1',
						]
					),
				];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $response ) => $response[0] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $response ) => $response[1] );
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );
		Functions\when( 'wp_die' )->alias(
			static function ( $message ) {
				throw new RuntimeException( 'die: ' . $message );
			}
		);
		foreach ( [ 'wp_redirect', 'wp_safe_redirect' ] as $redirect ) {
			Functions\when( $redirect )->alias(
				static function ( string $location ) {
					throw new RuntimeException( 'redirect: ' . $location );
				}
			);
		}
		OptionsController::detect_scope();
	}

	protected function tearDown(): void {
		$_GET = [];
		Functions\when( 'is_multisite' )->justReturn( false );
		OptionsController::detect_scope();
		parent::tearDown();
	}

	/**
	 * Store a connection in the main site's own options or the network's.
	 *
	 * @param bool   $network Whether in the network's options.
	 * @param string $site_id Its Scanfully site id.
	 */
	private function connected( bool $network, string $site_id ): void {
		$values = [
			'scanfully_connect_is_connected' => 'yes',
			'scanfully_connect_site_id'      => $site_id,
			'scanfully_connect_access_token' => 'sfy_sat_' . $site_id,
			'scanfully_connect_flow'         => 'oauth',
			'scanfully_connect_scopes'       => 'wordpress:read',
		];
		if ( $network ) {
			$this->site_options = array_merge( $this->site_options, $values );
		} else {
			$this->options = array_merge( $this->options, $values );
		}
		OptionsController::detect_scope();
	}

	/**
	 * Route the request in $_GET and return where it ended: a redirect or a die.
	 *
	 * @return string
	 */
	private function request(): string {
		try {
			Controller::catch_connect_requests();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
		$this->fail( 'The request neither redirected nor died.' );
	}

	public function test_the_network_connection_applies_on_the_main_site_of_a_connected_network_activated_plugin(): void {
		$this->assertFalse( Network::applies(), 'not connected' );
		$this->connected( true, 'network-1' );
		$this->assertTrue( Network::applies() );
		$this->assertTrue( OptionsController::network_scope() );
		$this->assertSame( Network::KIND_NETWORK, Network::kind() );
		$this->assertFalse( Multisite::monitoring_only() );

		$this->main_site = false;
		OptionsController::detect_scope();
		$this->assertFalse( Network::applies(), 'another site of the network' );
		$this->assertSame( Network::KIND_SUBSITE, Network::kind() );
		$this->assertTrue( Multisite::monitoring_only() );

		$this->main_site                               = true;
		$this->site_options['active_sitewide_plugins'] = [];
		$this->assertFalse( Network::applies(), 'a plugin that is not network-activated' );
	}

	public function test_a_long_running_process_sees_a_network_connected_after_it_started(): void {
		$this->assertFalse( OptionsController::network_scope() );
		$deleted = [];
		Functions\when( 'get_current_network_id' )->justReturn( 1 );
		Functions\when( 'wp_cache_delete' )->alias(
			static function ( string $key, string $group ) use ( &$deleted ) {
				$deleted[] = $group . '/' . $key;
				return true;
			}
		);
		// connected by another request, after this one decided its scope
		$this->site_options['scanfully_connect_is_connected'] = 'yes';

		OptionsController::refresh_scope();

		$this->assertTrue( OptionsController::network_scope() );
		$this->assertContains( 'site-options/1:scanfully_connect_is_connected', $deleted, 'not what this process cached' );
	}

	public function test_the_main_site_admin_cannot_touch_the_network_connection_outside_network_admin(): void {
		$this->connected( true, 'network-1' );
		foreach ( [ 'scanfully-disconnect', 'scanfully-reconnect', 'scanfully-change-access', 'scanfully-connect' ] as $param ) {
			$_GET = [
				$param                   => '1',
				$param . '-nonce'        => 'n',
				'scanfully-connect-nonce' => 'n',
			];
			$this->assertStringStartsWith( 'die: You do not have permission', $this->request(), $param . ' from the main site\'s own settings' );
		}
		$this->assertSame( 'yes', $this->site_options['scanfully_connect_is_connected'], 'the network connection stays' );
		$this->assertSame( [], $this->posts, 'nothing revoked' );
	}

	public function test_network_admin_needs_a_super_admin(): void {
		$this->network_admin = true;
		$this->caps          = [ 'manage_options' ];
		$_GET                = [
			'scanfully-connect'       => '1',
			'scanfully-connect-nonce' => 'n',
		];
		$this->assertStringStartsWith( 'die: You do not have permission', $this->request() );
	}

	public function test_network_admin_starts_a_network_connection_without_the_monitoring_only_confirmation(): void {
		$this->network_admin = true;
		$_GET                = [
			'scanfully-connect'       => '1',
			'scanfully-connect-nonce' => 'n',
		];
		$redirect = $this->request();
		$this->assertStringStartsWith( 'redirect: https://api.scanfully.com/oauth/authorize?', $redirect );
		parse_str( (string) parse_url( substr( $redirect, strlen( 'redirect: ' ) ), PHP_URL_QUERY ), $query );
		$this->assertSame( 'network', $query['kind'] );
		$this->assertStringStartsWith( 'https://scanfully.com/wp-admin/network/settings.php?page=scanfully', $query['redirect_uri'] );
	}

	public function test_a_site_of_a_network_needs_the_confirmation_to_change_access(): void {
		$this->main_site = false;
		$this->connected( false, 'subsite-1' );
		$_GET = [
			'scanfully-change-access'       => '1',
			'scanfully-change-access-nonce' => 'n',
		];
		$this->assertStringContainsString( Multisite::CONFIRM_NEEDED . '=1', $this->request() );

		$_GET[ Multisite::CONFIRM_FIELD ] = '1';
		$this->assertStringStartsWith( 'redirect: https://api.scanfully.com/oauth/authorize?', $this->request() );
	}

	public function test_a_site_of_a_network_reconnects_from_its_page(): void {
		$this->main_site = false;
		$this->connected( false, 'subsite-1' );
		$_GET = [
			'scanfully-reconnect'       => '1',
			'scanfully-reconnect-nonce' => 'n',
		];
		$this->assertSame( 'redirect: https://scanfully.com/wp-admin/options-general.php?page=scanfully', $this->request() );
		$this->assertArrayNotHasKey( 'scanfully_connect_is_connected', $this->options, 'the old connection is gone' );
	}

	public function test_the_network_answer_takes_over_the_main_site_connection(): void {
		$this->connected( false, 'main-1' );
		$this->network_admin = true;
		$this->transients    = [
			'scanfully_connect_state_3'    => 'state-1',
			'scanfully_connect_verifier_3' => str_repeat( 'v', 64 ),
		];
		$_GET                = [
			'scanfully-oauth' => '1',
			'state'           => 'state-1',
			'code'            => 'code-1',
		];

		$this->assertStringStartsWith( 'redirect: https://scanfully.com/wp-admin/network/settings.php?page=scanfully', $this->request() );

		$urls = array_map( static fn( array $post ): string => $post[0], $this->posts );
		$this->assertSame( 'https://api.scanfully.com/oauth/token', $urls[0] ?? '' );
		$this->assertSame( 'https://api.scanfully.com/v1/sites/main-1/connect/revoke', $urls[1] ?? '', 'once the network has its tokens, the main site\'s own connection is revoked' );
		$this->assertSame( 'Bearer sfy_sat_main-1', $this->posts[1][1]['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'scanfully_connect_is_connected', $this->options, 'the main site\'s own options are cleared' );
		$this->assertArrayNotHasKey( 'scanfully_connect_access_token', $this->options );
		$this->assertSame( 'yes', $this->site_options['scanfully_connect_is_connected'] );
		$this->assertSame( 'network-1', $this->site_options['scanfully_connect_site_id'] );
		$this->assertSame( 'sfy_sat_network', $this->site_options['scanfully_connect_access_token'] );
		$this->assertTrue( OptionsController::network_scope(), 'the network connection applies from now on' );
	}

	public function test_a_blog_answer_cannot_land_in_network_admin_without_a_super_admin(): void {
		$this->network_admin = true;
		$this->caps          = [ 'manage_options' ];
		$_GET                = [
			'scanfully-oauth' => '1',
			'state'           => 'state-1',
			'code'            => 'code-1',
		];
		$this->assertStringStartsWith( 'die: You do not have permission', $this->request() );
		$this->assertSame( [], $this->posts );
	}
}
