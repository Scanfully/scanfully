<?php
/**
 * Software lock integration tests on a multisite network: the network
 * connection's lock holds for super admins in Network Admin and on every
 * site of the network.
 *
 * Runs under a multisite WordPress runtime (`npm run
 * test:integration:multisite`) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Connect\Address;
use Scanfully\Connect\Network;
use Scanfully\Health\Capabilities;
use Scanfully\Options\Controller as OptionsController;

/**
 * The software lock on a network connected as a whole with Scanfully Manage
 * on.
 *
 * @group multisite
 */
final class SoftwareLockNetworkTest extends TestCase {

	/**
	 * The network options the tests write.
	 */
	private const NETWORK_OPTIONS = [ 'scanfully_connect_is_connected', 'scanfully_connect_site_id', 'scanfully_connect_access_token', 'scanfully_connect_expires', 'scanfully_connect_flow', 'scanfully_connect_scopes', 'scanfully_connect_address', 'scanfully_connect_address_shown', 'scanfully_connect_software_lock' ];

	/**
	 * The network-activated plugins before the test.
	 *
	 * @var mixed
	 */
	private $sitewide;

	/**
	 * A super admin.
	 *
	 * @var int
	 */
	private int $super = 0;

	/**
	 * A subsite.
	 *
	 * @var int
	 */
	private int $blog = 0;

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network: run npm run test:integration:multisite.' );
		}

		// The plugin network-activated, and the network connected as a whole
		// with Scanfully Manage on.
		$this->sitewide = get_site_option( 'active_sitewide_plugins', [] );
		update_site_option( 'active_sitewide_plugins', array_merge( (array) $this->sitewide, [ plugin_basename( SCANFULLY_PLUGIN_FILE ) => time() ] ) );
		update_site_option( 'scanfully_connect_is_connected', 'yes' );
		update_site_option( 'scanfully_connect_site_id', 'c0ffee00-0000-4000-8000-00000000a0c2' );
		update_site_option( 'scanfully_connect_access_token', 'sfy_sat_integration' );
		update_site_option( 'scanfully_connect_expires', '2099-01-01 00:00:00' );
		update_site_option( 'scanfully_connect_flow', 'oauth' );
		update_site_option( 'scanfully_connect_scopes', 'wordpress:read wordpress:manage' );
		Network::forget();
		OptionsController::detect_scope();
		$this->assertTrue( OptionsController::network_scope(), 'the main site works under the network connection' );
		Address::remember();

		$network    = get_network();
		$this->blog = (int) wp_insert_site(
			[
				'domain' => $network->domain,
				'path'   => $network->path . 'scanfully-lock-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Software lock',
			]
		);
		$this->assertGreaterThan( 0, $this->blog );

		$this->super = (int) wp_insert_user(
			[
				'user_login' => 'scanfully-lock-super-' . strtolower( wp_generate_password( 6, false ) ),
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'scanfully-lock-super-' . strtolower( wp_generate_password( 6, false ) ) . '@example.org',
			]
		);
		grant_super_admin( $this->super );
		wp_set_current_user( $this->super );
	}

	protected function tearDown(): void {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			wp_set_current_user( 0 );
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			require_once ABSPATH . 'wp-admin/includes/user.php';
			if ( $this->super > 0 ) {
				revoke_super_admin( $this->super );
				wpmu_delete_user( $this->super );
			}
			if ( $this->blog > 0 ) {
				wp_delete_site( $this->blog );
			}
			foreach ( self::NETWORK_OPTIONS as $name ) {
				delete_site_option( $name );
			}
			update_site_option( 'active_sitewide_plugins', $this->sitewide );
			Network::forget();
			OptionsController::detect_scope();
			// The activity events and syncs the tests caused (a site and a
			// user made) stay out of later tests.
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( '', [], 'scanfully' );
			}
		}
		parent::tearDown();
	}

	public function test_a_super_admin_may_update_without_a_lock(): void {
		$this->assertTrue( current_user_can( 'update_plugins' ) );

		switch_to_blog( $this->blog );
		$this->assertTrue( current_user_can( 'update_plugins' ) );
		$this->assertTrue( current_user_can( 'install_plugins' ) );
	}

	public function test_a_super_admin_is_denied_in_network_admin_and_on_every_site_when_the_network_holds_the_lock(): void {
		update_site_option( 'scanfully_connect_software_lock', 'plugins core' );

		$this->assertFalse( current_user_can( 'update_plugins' ) );
		$this->assertFalse( current_user_can( 'install_plugins' ) );
		$this->assertFalse( current_user_can( 'update_core' ) );
		$this->assertTrue( current_user_can( 'update_themes' ), 'themes are not locked' );
		$this->assertTrue( current_user_can( 'activate_plugins' ) );
		$this->assertTrue( current_user_can( 'manage_network_plugins' ) );

		switch_to_blog( $this->blog );
		$this->assertFalse( OptionsController::network_scope(), 'a subsite works under its own connection' );
		$this->assertFalse( current_user_can( 'update_plugins' ) );
		$this->assertFalse( current_user_can( 'delete_plugins' ) );
		$this->assertFalse( current_user_can( 'update_core' ) );
		$this->assertTrue( current_user_can( 'install_themes' ) );
		$this->assertTrue( current_user_can( 'activate_plugins' ) );

		$install = new \WP_REST_Request( 'POST', '/wp/v2/plugins' );
		$install->set_param( 'slug', 'hello-dolly' );
		$this->assertSame( 403, rest_do_request( $install )->get_status(), 'the subsite\'s REST API refuses too' );
	}

	public function test_a_subsite_s_own_options_never_lock_it(): void {
		switch_to_blog( $this->blog );
		update_option( 'scanfully_connect_software_lock', 'plugins' );

		$this->assertTrue( current_user_can( 'update_plugins' ) );
	}

	public function test_the_network_lock_lifts_when_the_network_turns_scanfully_manage_off(): void {
		update_site_option( 'scanfully_connect_software_lock', 'themes' );
		update_site_option( 'scanfully_connect_scopes', 'wordpress:read' );

		$this->assertTrue( current_user_can( 'update_themes' ) );
		switch_to_blog( $this->blog );
		$this->assertTrue( current_user_can( 'update_themes' ) );
	}

	public function test_the_network_connection_reports_its_lock(): void {
		update_site_option( 'scanfully_connect_software_lock', 'translations' );

		$this->assertSame(
			[
				'active'       => true,
				'plugins'      => false,
				'themes'       => false,
				'translations' => true,
				'core'         => false,
			],
			Capabilities::get()['software_lock']
		);
	}
}
