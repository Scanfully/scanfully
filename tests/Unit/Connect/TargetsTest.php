<?php
/**
 * Targets unit tests: where a site's data goes.
 *
 * @package Scanfully\Tests\Unit\Connect
 */

namespace Scanfully\Tests\Unit\Connect;

use Scanfully\Connect\Network;
use Scanfully\Connect\Target;
use Scanfully\Connect\Targets;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Connect\Target
 * @covers \Scanfully\Connect\Targets
 * @covers \Scanfully\Connect\Children::marker_here
 * @covers \Scanfully\Connect\Children::current
 * @covers \Scanfully\Connect\Network::access_token
 * @covers \Scanfully\Connect\Network::moved
 * @covers \Scanfully\Connect\Network::main_home
 */
final class TargetsTest extends TestCase {

	use NetworkFixture;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_network();
	}

	protected function tearDown(): void {
		$this->tear_down_network();
		parent::tearDown();
	}

	/**
	 * The keys of targets.
	 *
	 * @param Target[] $targets The targets.
	 *
	 * @return string[]
	 */
	private static function keys( array $targets ): array {
		return array_map( static fn( Target $target ): string => $target->key(), $targets );
	}

	public function test_a_single_site_has_its_own_connection_only(): void {
		$this->multisite = false;
		$this->connect_own( 1, 'single' );
		$this->on_blog( 1 );

		$this->assertSame( [ 'own' ], self::keys( Targets::here() ) );
		$this->assertSame( [ 'own' ], self::keys( Targets::now() ) );
		$this->assertSame( 'single', Targets::now()[0]->site_id() );
		$this->assertSame( [], Targets::now()[0]->headers() );
	}

	public function test_a_site_without_a_connection_has_no_target(): void {
		$this->on_blog( 2 );

		$this->assertSame( [], Targets::here() );
		$this->assertFalse( Targets::reachable() );
		$this->assertNull( Targets::find( '' ) );
	}

	public function test_the_main_site_has_the_networks_connection_only(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->on_blog( 1 );

		$targets = Targets::now();
		$this->assertSame( [ 'own' ], self::keys( $targets ) );
		$this->assertSame( 'net-site', $targets[0]->site_id() );
		$this->assertSame( 'net-token', $targets[0]->access_token() );
	}

	public function test_a_monitored_subsite_has_its_child_with_the_networks_token_and_its_blog_id(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->on_blog( 2 );

		$this->assertSame( [ 'network:child-2' ], self::keys( Targets::here() ) );
		$child = Targets::now()[0];
		$this->assertTrue( $child->is_child() );
		$this->assertSame( 'child-2', $child->site_id() );
		$this->assertSame( 2, $child->blog_id() );
		$this->assertSame( 'net-token', $child->access_token() );
		$this->assertSame( [ 'X-Scanfully-Blog-Id' => '2' ], $child->headers() );
		$this->assertSame( 'scanfully_woocheckout_probe_secret_network', $child->option( 'scanfully_woocheckout_probe_secret' ) );
	}

	public function test_a_subsite_with_its_own_connection_too_has_both_targets(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->connect_own( 2, 'other-account-site' );
		$this->on_blog( 2 );

		$targets = Targets::now();
		$this->assertSame( [ 'own', 'network:child-2' ], self::keys( $targets ) );
		$this->assertSame( 'own-token-2', $targets[0]->access_token() );
		$this->assertSame( 'net-token', $targets[1]->access_token() );
		$this->assertSame( 'own', Targets::find( '' )->key() );
		$this->assertSame( [ 'network:child-2' ], self::keys( Targets::only( [ 'network:child-2' ] ) ) );
		$this->assertSame( [ 'own' ], self::keys( Targets::only( [ '' ] ) ) );
		$this->assertCount( 2, Targets::only( null ) );
	}

	public function test_an_attached_site_with_the_same_site_id_is_the_child_only(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->connect_own( 2, 'child-2' );
		$this->on_blog( 2 );

		$this->assertSame( [ 'network:child-2' ], self::keys( Targets::now() ) );
	}

	public function test_an_unmonitored_subsite_has_no_child(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->on_blog( 3 );

		$this->assertSame( [], Targets::here() );
		$this->assertSame( [], Targets::now() );
	}

	public function test_a_cloned_subsites_copied_marker_is_ignored(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		// Blog 3 got blog 2's options copied: the marker names blog 2.
		$this->db->options[3]['scanfully_network_child'] = $this->db->options[2]['scanfully_network_child'];
		$this->on_blog( 3 );
		$this->assertSame( [], Targets::here() );

		// A marker for this blog id at another address (its tables copied elsewhere).
		$this->mark( 3, 'child-3', 'https://elsewhere.test/news' );
		$this->assertSame( [], Targets::here() );
	}

	public function test_a_home_option_changed_by_a_filter_keeps_the_child(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->on_blog( 2 );
		// Domain mapping answers another home through pre_option_home.
		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				if ( 'home' === $name ) {
					return 'https://shop.example.com';
				}
				$raw = $this->db->options[ $this->blog ][ $name ] ?? null;

				return null === $raw ? $fallback : maybe_unserialize( $raw );
			}
		);

		$this->assertSame( [ 'network:child-2' ], self::keys( Targets::now() ) );
	}

	public function test_the_main_site_never_takes_a_marker(): void {
		$this->mark( 1, 'child-1' );
		$this->site_options['scanfully_connect_children'] = [ 1 => 'child-1' ];
		$this->on_blog( 1 );

		$this->assertSame( [ 'own' ], self::keys( Targets::now() ) );
	}

	public function test_a_job_finds_no_child_once_the_network_stops_monitoring_it(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->on_blog( 2 );
		$this->site_options['scanfully_connect_children'] = [];

		// The hot path still sees the marker until the main site removes it,
		// a job that sends does not.
		$this->assertSame( [ 'network:child-2' ], self::keys( Targets::here() ) );
		$this->assertSame( [], Targets::now() );
		$this->assertNull( Targets::find( 'network:child-2' ) );
	}

	public function test_a_job_queued_for_another_child_site_finds_nothing(): void {
		$this->monitor( [ 2 => 'child-2b' ] );
		$this->on_blog( 2 );

		$this->assertNull( Targets::find( 'network:child-2' ) );
		$this->assertNotNull( Targets::find( 'network:child-2b' ) );
	}

	public function test_no_child_when_the_network_is_disconnected_or_the_plugin_not_network_activated(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->on_blog( 2 );
		$this->site_options['scanfully_connect_is_connected'] = 'no';
		$this->assertSame( [], Targets::now() );

		$this->site_options['scanfully_connect_is_connected'] = 'yes';
		$this->site_options['active_sitewide_plugins']        = [];
		$this->assertSame( [], Targets::now() );
	}

	public function test_a_copy_of_the_network_sends_nothing_to_the_children(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->db->options[1]['home'] = 'https://staging.network.test';
		$this->on_blog( 2 );

		$this->assertTrue( Network::moved() );
		$this->assertSame( [], Targets::now() );
	}

	public function test_the_network_address_check_uses_the_main_sites_home_from_a_subsite(): void {
		$this->on_blog( 3 );
		$this->assertFalse( Network::moved() );
		$this->assertSame( 'https://network.test', Network::main_home() );

		// An address stored before 2.0 is the normalized address itself.
		$this->site_options['scanfully_connect_address'] = 'network.test';
		$this->assertFalse( Network::moved() );
		unset( $this->site_options['scanfully_connect_address'] );
		$this->assertFalse( Network::moved() );
	}

	public function test_a_retry_reads_the_networks_token_again(): void {
		$this->on_blog( 2 );
		$this->site_options['scanfully_connect_access_token'] = 'refreshed';

		$this->assertSame( 'refreshed', Network::access_token( true ) );
		$this->assertSame( 'net-site', Network::site_id() );
	}
}
