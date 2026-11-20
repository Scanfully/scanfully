<?php
/**
 * Software lock integration tests: the capabilities a lock denies in a real
 * WordPress, what keeps working, the filter, Scanfully's jobs, and the lock
 * read from the API.
 *
 * Runs under a real WordPress runtime (wp-env) and skips otherwise. A single
 * site; SoftwareLockNetworkTest covers a network.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Connect\Address;
use Scanfully\Connect\Scopes;
use Scanfully\Health\Capabilities;
use Scanfully\Manage\SoftwareLock;

/**
 * The software lock on a connected single site with Scanfully Manage on.
 */
final class SoftwareLockTest extends TestCase {

	/**
	 * The capabilities each lock denies.
	 */
	private const DENIED = [
		'plugins'      => [ 'install_plugins', 'upload_plugins', 'update_plugins', 'delete_plugins' ],
		'themes'       => [ 'install_themes', 'upload_themes', 'update_themes', 'delete_themes' ],
		'translations' => [ 'install_languages', 'update_languages' ],
		'core'         => [ 'update_core' ],
	];

	/**
	 * The scopes of a connection with Scanfully Manage on.
	 */
	private const MANAGE = 'wordpress:read wordpress:manage';

	/**
	 * The options the tests write.
	 */
	private const OPTIONS = [ 'scanfully_connect_is_connected', 'scanfully_connect_site_id', 'scanfully_connect_access_token', 'scanfully_connect_expires', 'scanfully_connect_flow', 'scanfully_connect_scopes', 'scanfully_connect_address', 'scanfully_connect_address_shown', 'scanfully_connect_software_lock', 'scanfully_connect_scopes_changed', 'scanfully_manage_paused' ];

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private int $admin = 0;

	/**
	 * Another administrator.
	 *
	 * @var int
	 */
	private int $other = 0;

	/**
	 * What the fake API answers to the read of the connection: a response,
	 * or a WP_Error.
	 *
	 * @var array<string, mixed>|\WP_Error|null
	 */
	private $api;

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'rest_do_request' ) ) {
			$this->markTestSkipped( 'WordPress runtime not available. Run this suite under wp-env.' );
		}
		if ( is_multisite() ) {
			$this->markTestSkipped( 'A single site; SoftwareLockNetworkTest covers a network.' );
		}

		update_option( 'scanfully_connect_is_connected', 'yes' );
		update_option( 'scanfully_connect_site_id', 'c0ffee00-0000-4000-8000-00000000a0c1' );
		update_option( 'scanfully_connect_access_token', 'sfy_sat_integration', false );
		update_option( 'scanfully_connect_expires', '2099-01-01 00:00:00' );
		update_option( 'scanfully_connect_flow', 'oauth' );
		update_option( 'scanfully_connect_scopes', self::MANAGE );
		Address::remember();

		$this->admin = $this->administrator( 'scanfully-lock-admin' );
		$this->other = $this->administrator( 'scanfully-lock-agency' );
		wp_set_current_user( $this->admin );
	}

	protected function tearDown(): void {
		if ( function_exists( 'delete_option' ) && ! is_multisite() ) {
			remove_all_filters( 'scanfully_software_lock_applies' );
			remove_filter( 'pre_http_request', [ $this, 'fake_api' ] );
			wp_set_current_user( 0 );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( [ $this->admin, $this->other ] as $id ) {
				if ( $id > 0 ) {
					wp_delete_user( $id );
				}
			}
			foreach ( self::OPTIONS as $name ) {
				delete_option( $name );
			}
			delete_site_transient( 'update_plugins' );
			// The reports, activity events and syncs the tests caused (users
			// made, a plugin switched on and off) stay out of later tests.
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( '', [], 'scanfully' );
			}
		}
		parent::tearDown();
	}

	/**
	 * Make an administrator.
	 *
	 * @param string $login The login.
	 *
	 * @return int The user's ID.
	 */
	private function administrator( string $login ): int {
		$id = wp_insert_user(
			[
				'user_login' => $login . '-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => $login . '-' . wp_generate_password( 6, false ) . '@example.org',
				'role'       => 'administrator',
			]
		);
		$this->assertIsInt( $id );

		return $id;
	}

	/**
	 * Lock kinds, as the plugin stores the API's answer.
	 *
	 * @param string $kinds The locked kinds, space separated.
	 *
	 * @return void
	 */
	private function lock( string $kinds ): void {
		update_option( 'scanfully_connect_software_lock', $kinds );
	}

	/**
	 * Answer the plugin's requests: the read of the connection as the test
	 * says, anything else (the queue runner's loopback) with a 200.
	 *
	 * @param mixed  $preempt Whether to preempt the request.
	 * @param array  $args    The request's arguments.
	 * @param string $url     The URL.
	 *
	 * @return mixed
	 */
	public function fake_api( $preempt, $args, $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- The filter's arguments.
		if ( false !== strpos( (string) $url, '/plugin-connection' ) ) {
			return $this->api;
		}

		return [
			'headers'  => [],
			'body'     => '',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
		];
	}

	/**
	 * The answer of the read of the connection.
	 *
	 * @param array<string, mixed> $body The body.
	 *
	 * @return array<string, mixed>
	 */
	private static function answer( array $body ): array {
		return [
			'headers'  => [],
			'body'     => (string) wp_json_encode( $body ),
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
		];
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function kinds(): array {
		return [
			'plugins'      => [ 'plugins' ],
			'themes'       => [ 'themes' ],
			'translations' => [ 'translations' ],
			'core'         => [ 'core' ],
		];
	}

	/**
	 * @dataProvider kinds
	 *
	 * @param string $kind The kind.
	 */
	public function test_an_administrator_is_denied_what_the_lock_covers_and_nothing_more( string $kind ): void {
		foreach ( self::DENIED as $caps ) {
			foreach ( $caps as $cap ) {
				$this->assertTrue( current_user_can( $cap ), $cap . ' before the lock' ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- The capabilities the lock covers.
			}
		}

		$this->lock( $kind );

		foreach ( self::DENIED as $covers => $caps ) {
			foreach ( $caps as $cap ) {
				$this->assertSame( $covers !== $kind, current_user_can( $cap ), $cap . ' under the ' . $kind . ' lock' ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- The capabilities the lock covers.
			}
		}
	}

	public function test_activating_switching_themes_and_site_health_keep_working_under_every_lock(): void {
		$this->lock( 'plugins themes translations core' );

		$this->assertTrue( current_user_can( 'activate_plugins' ) );
		$this->assertTrue( current_user_can( 'activate_plugin', 'hello.php' ) );
		$this->assertTrue( current_user_can( 'deactivate_plugin', 'hello.php' ) );
		$this->assertTrue( current_user_can( 'switch_themes' ) );
		$this->assertTrue( current_user_can( 'edit_theme_options' ) );
		$this->assertTrue( current_user_can( 'view_site_health_checks' ), 'Site Health stays visible' );
		$this->assertTrue( current_user_can( 'update_php' ) );
		$this->assertTrue( current_user_can( 'update_https' ) );
		$this->assertTrue( wp_is_file_mod_allowed( 'scanfully_manage' ), 'WordPress may still change files, for Scanfully\'s jobs' );
	}

	public function test_a_plugin_is_still_activated_and_deactivated_through_the_rest_api(): void {
		$this->lock( 'plugins' );
		$this->assertFalse( is_plugin_active( 'hello.php' ) );

		$activate = new \WP_REST_Request( 'PUT', '/wp/v2/plugins/hello' );
		$activate->set_param( 'status', 'active' );
		$this->assertSame( 200, rest_do_request( $activate )->get_status() );
		$this->assertTrue( is_plugin_active( 'hello.php' ) );

		$deactivate = new \WP_REST_Request( 'PUT', '/wp/v2/plugins/hello' );
		$deactivate->set_param( 'status', 'inactive' );
		$this->assertSame( 200, rest_do_request( $deactivate )->get_status() );
		$this->assertFalse( is_plugin_active( 'hello.php' ) );
	}

	public function test_the_rest_api_refuses_to_install_or_delete_a_plugin(): void {
		$this->lock( 'plugins' );

		$install = new \WP_REST_Request( 'POST', '/wp/v2/plugins' );
		$install->set_param( 'slug', 'hello-dolly' );
		$response = rest_do_request( $install );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_cannot_install_plugin', $response->as_error()->get_error_code() );

		$delete = new \WP_REST_Request( 'DELETE', '/wp/v2/plugins/scanfully-no-such/plugin' );
		$this->assertSame( 403, rest_do_request( $delete )->get_status() );
	}

	public function test_update_counts_disappear_and_the_update_data_is_left_as_it_is(): void {
		set_site_transient(
			'update_plugins',
			(object) [
				'last_checked' => time(),
				'checked'      => [ 'hello.php' => '1.7.2' ],
				'response'     => [
					'hello.php' => (object) [
						'slug'        => 'hello-dolly',
						'plugin'      => 'hello.php',
						'new_version' => '99.0',
						'package'     => '',
					],
				],
			]
		);
		$this->assertSame( 1, wp_get_update_data()['counts']['plugins'] );

		$this->lock( 'plugins' );

		$this->assertSame( 0, wp_get_update_data()['counts']['plugins'], 'no badge' );
		$this->assertArrayHasKey( 'hello.php', get_site_transient( 'update_plugins' )->response, 'the update data stays for Scanfully\'s inventory' );
	}

	public function test_an_exempted_user_passes_and_the_others_stay_locked(): void {
		$this->lock( 'plugins core' );
		add_filter(
			'scanfully_software_lock_applies',
			function ( $applies, $user_id ) {
				return $user_id === $this->other ? false : $applies;
			},
			10,
			2
		);

		$this->assertFalse( user_can( $this->admin, 'update_plugins' ) );
		$this->assertFalse( user_can( $this->admin, 'update_core' ) );
		$this->assertTrue( user_can( $this->other, 'update_plugins' ) );
		$this->assertTrue( user_can( $this->other, 'install_plugins' ) );
		$this->assertTrue( user_can( $this->other, 'update_core' ) );
	}

	public function test_a_scanfully_job_runs_with_the_lock_lifted(): void {
		$this->lock( 'plugins themes translations core' );

		$inside = SoftwareLock::while_job_runs(
			static function (): array {
				return [ current_user_can( 'update_plugins' ), current_user_can( 'install_themes' ), current_user_can( 'update_languages' ), current_user_can( 'update_core' ) ];
			}
		);

		$this->assertSame( [ true, true, true, true ], $inside );
		$this->assertFalse( current_user_can( 'update_plugins' ), 'locked again after the job' );
	}

	public function test_the_lock_lifts_without_scanfully_manage_while_paused_and_on_a_copy(): void {
		$this->lock( 'plugins' );
		$this->assertFalse( current_user_can( 'update_plugins' ) );

		update_option( 'scanfully_connect_scopes', 'wordpress:read' );
		$this->assertTrue( current_user_can( 'update_plugins' ), 'Scanfully Manage is off' );

		update_option( 'scanfully_connect_scopes', self::MANAGE );
		update_option( 'scanfully_manage_paused', self::MANAGE, false );
		$this->assertTrue( current_user_can( 'update_plugins' ), 'Scanfully Manage is paused' );

		delete_option( 'scanfully_manage_paused' );
		$this->assertFalse( current_user_can( 'update_plugins' ) );

		update_option( 'scanfully_connect_address', hash( 'sha256', 'elsewhere.example' ) );
		$this->assertTrue( current_user_can( 'update_plugins' ), 'a copy of the site at another address' );
	}

	public function test_a_read_of_the_connection_takes_the_lock_and_a_failed_read_keeps_it(): void {
		add_filter( 'pre_http_request', [ $this, 'fake_api' ], 10, 3 );
		$this->api = self::answer(
			[
				'site_id'       => 'c0ffee00-0000-4000-8000-00000000a0c1',
				'scopes'        => [ 'wordpress:read', 'wordpress:manage' ],
				'software_lock' => [
					'plugins'      => false,
					'themes'       => true,
					'translations' => false,
					'core'         => true,
				],
			]
		);

		Scopes::refresh();

		$this->assertSame( 'themes core', get_option( 'scanfully_connect_software_lock' ) );
		$this->assertFalse( current_user_can( 'update_themes' ) );
		$this->assertFalse( current_user_can( 'update_core' ) );
		$this->assertTrue( current_user_can( 'update_plugins' ) );
		$this->assertTrue( as_has_scheduled_action( 'scanfully_sync_site_health', [], 'scanfully' ), 'the site reports the lock it took' );

		$this->api = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		Scopes::refresh();
		$this->assertSame( 'themes core', get_option( 'scanfully_connect_software_lock' ), 'an API that cannot be reached changes nothing' );

		$this->api = self::answer(
			[
				'site_id' => 'c0ffee00-0000-4000-8000-00000000a0c1',
				'scopes'  => [ 'wordpress:read', 'wordpress:manage' ],
			]
		);
		Scopes::refresh();
		$this->assertSame( '', get_option( 'scanfully_connect_software_lock' ), 'an answer without a lock lifts it' );
		$this->assertTrue( current_user_can( 'update_themes' ) );
	}

	public function test_the_site_reports_the_lock_and_whether_it_is_enforced(): void {
		$this->lock( 'plugins translations' );

		$this->assertSame(
			[
				'active'       => true,
				'plugins'      => true,
				'themes'       => false,
				'translations' => true,
				'core'         => false,
			],
			Capabilities::get()['software_lock']
		);
	}
}
