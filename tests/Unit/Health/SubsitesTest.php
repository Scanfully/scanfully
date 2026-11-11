<?php
/**
 * Subsite report unit tests.
 *
 * @package Scanfully\Tests\Unit\Health
 */

namespace Scanfully\Tests\Unit\Health;

use Brain\Monkey\Functions;
use Scanfully\Health\Capabilities;
use Scanfully\Health\Controller;
use Scanfully\Health\Subsites;
use Scanfully\Options\Controller as OptionsController;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Health\Subsites
 * @covers \Scanfully\Health\Controller::with_subsites
 * @covers \Scanfully\Health\Controller::without_copied_capabilities
 * @covers \Scanfully\Health\Capabilities::skipped
 */
final class SubsitesTest extends TestCase {

	/**
	 * Whether WordPress runs as a network.
	 *
	 * @var bool
	 */
	private bool $multisite = true;

	/**
	 * The current site's blog id.
	 *
	 * @var int
	 */
	private int $blog = 1;

	/**
	 * The blog ids switch_to_blog() left, last first.
	 *
	 * @var int[]
	 */
	private array $switched = [];

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
	 * The other networks of the install: their main site and options.
	 *
	 * @var array<int, array{main: int, options: array<string, mixed>}>
	 */
	private array $networks = [];

	/**
	 * The network of each site that is not in network 1.
	 *
	 * @var array<int, int>
	 */
	private array $site_networks = [];

	/**
	 * Whether Action Scheduler is initialized.
	 *
	 * @var int
	 */
	private int $as_init = 1;

	/**
	 * Action Scheduler calls: the call, the blog it ran on, and its arguments.
	 *
	 * @var array<int, array{string, int, array<int, mixed>}>
	 */
	private array $as_calls = [];

	/**
	 * The database.
	 *
	 * @var FakeNetworkDb
	 */
	private FakeNetworkDb $db;

	protected function setUp(): void {
		parent::setUp();
		$this->options      = [ 'home' => 'https://network.test' ];
		$this->site_options = [
			'active_sitewide_plugins'        => [ 'scanfully/scanfully.php' => time() ],
			'scanfully_connect_is_connected' => 'yes',
		];

		Functions\when( 'is_multisite' )->alias( fn(): bool => $this->multisite );
		Functions\when( 'is_network_admin' )->justReturn( false );
		Functions\when( 'get_main_site_id' )->alias( fn( $network = null ): int => null === $network || 1 === $network ? 1 : ( $this->networks[ $network ]['main'] ?? 0 ) );
		Functions\when( 'get_network_option' )->alias(
			fn( int $network, string $name, $fallback = false ) => 1 === $network ? ( $this->site_options[ $name ] ?? $fallback ) : ( $this->networks[ $network ]['options'][ $name ] ?? $fallback )
		);
		Functions\when( 'get_site' )->alias(
			fn() => (object) [
				'blog_id' => (string) $this->blog,
				'site_id' => (string) ( $this->site_networks[ $this->blog ] ?? 1 ),
			]
		);
		Functions\when( 'get_current_network_id' )->justReturn( 1 );
		Functions\when( 'get_current_blog_id' )->alias( fn(): int => $this->blog );
		Functions\when( 'is_main_site' )->alias( fn(): bool => 1 === $this->blog );
		Functions\when( 'switch_to_blog' )->alias(
			function ( int $id ): bool {
				$this->switched[] = $this->blog;
				$this->blog       = $id;
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function (): bool {
				$this->blog = (int) array_pop( $this->switched );
				return true;
			}
		);
		Functions\when( 'plugin_basename' )->justReturn( 'scanfully/scanfully.php' );
		Functions\when( 'get_option' )->alias( fn( string $name, $fallback = false ) => $this->options[ $name ] ?? $fallback );
		Functions\when( 'get_site_option' )->alias( fn( string $name, $fallback = false ) => $this->site_options[ $name ] ?? $fallback );
		Functions\when( 'home_url' )->justReturn( 'https://network.test' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'untrailingslashit' )->alias( static fn( string $url ): string => rtrim( $url, '/' ) );
		Functions\when( 'did_action' )->alias( fn( string $hook ): int => 'action_scheduler_init' === $hook ? $this->as_init : 0 );
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function ( ...$args ): void {
				$this->as_calls[] = [ 'unschedule', $this->blog, $args ];
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( ...$args ): int {
				$this->as_calls[] = [ 'schedule', $this->blog, $args ];
				return 1;
			}
		);

		$this->db        = new FakeNetworkDb();
		$GLOBALS['wpdb'] = $this->db;

		OptionsController::end_forced_scope();
		OptionsController::detect_scope();
	}

	protected function tearDown(): void {
		OptionsController::end_forced_scope();
		// Leave no detected network scope to the tests after this one.
		$this->multisite = false;
		OptionsController::detect_scope();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * The blog each Action Scheduler call ran on.
	 *
	 * @return int[]
	 */
	private function scheduled_on(): array {
		return array_column( $this->as_calls, 1 );
	}

	/**
	 * The report on the network connection.
	 *
	 * @return array<string, mixed>|null
	 */
	private function network_report(): ?array {
		return OptionsController::with_scope( true, static fn() => Subsites::report() );
	}

	/**
	 * A small network: the main site, a subdirectory site, a mapped domain,
	 * and sites that are not public, archived, spam and deleted.
	 */
	private function small_network(): void {
		$this->db->add_site( 1, 'network.test', '/', [], [ 'blogname' => 'Network &amp; Co' ] );
		$this->db->add_site( 2, 'network.test', '/shop/', [], [ 'blogname' => 'Shop' ] );
		$this->db->add_site( 3, 'network.test', '/blog/', [ 'public' => 0 ], [ 'home' => 'https://blog.example.com' ] );
		$this->db->add_site( 4, 'network.test', '/old/', [ 'archived' => 1 ] );
		$this->db->add_site(
			5,
			'network.test',
			'/spam/',
			[
				'spam'   => 1,
				'public' => -1,
			]
		);
		$this->db->add_site( 6, 'network.test', '/gone/', [ 'deleted' => 1 ] );
	}

	public function test_the_network_connection_reports_every_site_of_the_network(): void {
		$this->small_network();
		$this->db->add_site( 7, 'other-network.test', '/', [ 'site_id' => 2 ] );

		$this->assertSame(
			[
				'subsites'           => [
					[
						'blog_id'  => 1,
						'home_url' => 'https://network.test',
						'name'     => 'Network & Co',
						'main'     => true,
						'public'   => true,
						'archived' => false,
						'spam'     => false,
						'deleted'  => false,
					],
					[
						'blog_id'  => 2,
						'home_url' => 'https://network.test/shop',
						'name'     => 'Shop',
						'main'     => false,
						'public'   => true,
						'archived' => false,
						'spam'     => false,
						'deleted'  => false,
					],
					[
						'blog_id'  => 3,
						'home_url' => 'https://blog.example.com',
						'name'     => 'Site 3',
						'main'     => false,
						'public'   => false,
						'archived' => false,
						'spam'     => false,
						'deleted'  => false,
					],
					[
						'blog_id'  => 4,
						'home_url' => 'https://network.test/old',
						'name'     => 'Site 4',
						'main'     => false,
						'public'   => true,
						'archived' => true,
						'spam'     => false,
						'deleted'  => false,
					],
					[
						'blog_id'  => 5,
						'home_url' => 'https://network.test/spam',
						'name'     => 'Site 5',
						'main'     => false,
						'public'   => false,
						'archived' => false,
						'spam'     => true,
						'deleted'  => false,
					],
					[
						'blog_id'  => 6,
						'home_url' => 'https://network.test/gone',
						'name'     => 'Site 6',
						'main'     => false,
						'public'   => true,
						'archived' => false,
						'spam'     => false,
						'deleted'  => true,
					],
				],
				'subsites_truncated' => false,
			],
			$this->network_report(),
			'a site of another network of the install is left out'
		);
		$this->assertCount( 1, $this->db->blog_queries(), 'one page of the sites table' );
		$this->assertCount( 2, $this->db->queries, 'and one query of the option tables' );
	}

	public function test_a_single_site_or_a_site_connected_on_its_own_reports_no_sites(): void {
		$this->small_network();
		$this->assertNotNull( Subsites::report(), 'the main site of the connected network, as detected' );
		$this->db->queries = [];

		// A subsite of the connected network, connected on its own.
		$this->blog = 3;
		OptionsController::detect_scope();
		$this->assertNull( Subsites::report(), 'a site connected on its own' );
		$this->assertSame( [ 'data' => [] ], Controller::with_subsites( [ 'data' => [] ] ) );
		$this->assertNull( OptionsController::with_scope( false, static fn() => Subsites::report() ) );

		// The main site while the network is not connected.
		$this->blog = 1;
		unset( $this->site_options['scanfully_connect_is_connected'] );
		OptionsController::detect_scope();
		$this->assertNull( Subsites::report(), 'the main site on its own connection' );

		$this->multisite = false;
		$this->assertNull( $this->network_report(), 'a single site, whatever the scope' );
		$this->assertSame( [], $this->db->queries, 'nothing read' );
	}

	public function test_the_site_data_of_the_network_connection_carries_the_sites(): void {
		$this->small_network();

		$data = OptionsController::with_scope( true, static fn() => Controller::with_subsites( [ 'data' => [ 'wp_multisite' => true ] ] ) );

		$this->assertSame( [ 'data', 'subsites', 'subsites_truncated' ], array_keys( $data ) );
		$this->assertCount( 6, $data['subsites'] );
		$this->assertFalse( $data['subsites_truncated'] );
	}

	public function test_a_reader_that_skips_the_capabilities_gets_no_sites(): void {
		$this->small_network();

		$data = OptionsController::with_scope(
			true,
			static fn() => Capabilities::skip( static fn() => Controller::with_subsites( [ 'data' => [] ] ) )
		);

		$this->assertSame( [ 'data' => [] ], $data, 'the inventory after a Manage job' );
		$this->assertSame( [], $this->db->queries );
		$this->assertFalse( Capabilities::skipped() );
	}

	public function test_a_copy_of_the_network_leaves_the_sites_out(): void {
		$this->site_options['scanfully_connect_address'] = hash( 'sha256', 'network.test' );
		$data = [
			'data'               => [],
			'capabilities'       => [ 'connection_kind' => 'network' ],
			'subsites'           => [ [ 'blog_id' => 1 ] ],
			'subsites_truncated' => false,
		];

		$this->assertSame( $data, OptionsController::with_scope( true, static fn() => Controller::without_copied_capabilities( $data ) ), 'the network itself' );

		$this->options['home'] = 'https://staging.network.test';
		$this->assertSame(
			[
				'data'                 => [],
				'capabilities_skipped' => 'site_moved',
			],
			OptionsController::with_scope( true, static fn() => Controller::without_copied_capabilities( $data ) ),
			'without the key the API keeps the list it has'
		);

		$this->small_network();
		$this->assertSame( [ 'data' => [] ], OptionsController::with_scope( true, static fn() => Controller::with_subsites( [ 'data' => [] ] ) ) );
		$this->assertSame( [], $this->db->queries, 'a copy reads no sites' );
	}

	public function test_a_site_without_its_options_table_keeps_its_place(): void {
		$this->small_network();
		$this->db->missing = [ 'wp_3_options' ];

		$report = $this->network_report();

		$this->assertNotNull( $report );
		$this->assertCount( 6, $report['subsites'] );
		$this->assertSame( 'https://network.test/blog', $report['subsites'][2]['home_url'], 'the address from the sites table, with the main site\'s scheme' );
		$this->assertSame( '', $report['subsites'][2]['name'] );
		$this->assertSame( 'Shop', $report['subsites'][1]['name'], 'the other sites are still read' );
		$this->assertSame( 'Site 6', $report['subsites'][5]['name'] );
		$this->assertCount( 5, $this->db->option_queries(), 'all six, then halves: 1 to 3 (1 and 2, then 3 alone) and 4 to 6' );
		$this->assertFalse( $this->db->suppressed, 'errors are shown again afterwards' );
	}

	public function test_a_network_whose_option_tables_never_join_is_read_one_site_a_query(): void {
		for ( $id = 1; $id <= 250; $id++ ) {
			$this->db->add_site( $id, 'network.test', '/s' . $id . '/' );
		}
		$this->db->union_fails = true;

		$report = $this->network_report();

		$this->assertCount( 250, $report['subsites'] ?? [] );
		$this->assertSame( 'Site 250', $report['subsites'][249]['name'] );
		$this->assertSame( 'https://network.test/s250', $report['subsites'][249]['home_url'] );
		$this->assertCount(
			7 + 250,
			$this->db->option_queries(),
			'the first hundred halved down to two sites (100, 50, 25, 13, 7, 4, 2), then one query a site'
		);
	}

	public function test_a_network_whose_option_reads_all_fail_leaves_the_key_out(): void {
		$this->small_network();
		$this->db->options_fail = true;

		$this->assertNull( $this->network_report(), 'no names and every address rebuilt would replace a good list' );
		$this->assertCount( 11, $this->db->option_queries(), 'six sites halved down to each one' );
	}

	public function test_a_hundred_sites_that_all_fail_stop_the_report(): void {
		for ( $id = 1; $id <= 300; $id++ ) {
			$this->db->add_site( $id, 'network.test', '/s' . $id . '/' );
		}
		$this->db->options_fail = true;

		$this->assertNull( $this->network_report() );
		$this->assertCount( 199, $this->db->option_queries(), 'the first hundred only' );
	}

	public function test_home_addresses_are_normalised_and_checked(): void {
		$this->db->add_site( 1, 'network.test', '/', [], [ 'home' => 'https://network.test/' ] );
		$this->db->add_site( 2, 'network.test', '/shop/', [], [ 'home' => ' https://shop.example.com/ ' ] );
		$this->db->add_site( 3, 'network.test', '/ftp/', [], [ 'home' => 'ftp://network.test/ftp' ] );
		$this->db->add_site( 4, 'network.test', '/long/', [], [ 'home' => 'https://network.test/' . str_repeat( 'a', Subsites::URL_MAX ) ] );
		$this->db->add_site( 5, 'network.test', '/nohost/', [], [ 'home' => 'https:///nohost' ] );
		$this->db->add_site( 6, 'network.test', '/upper/', [], [ 'home' => 'HTTP://Network.test/upper' ] );

		$homes = array_column( $this->network_report()['subsites'] ?? [], 'home_url' );

		$this->assertSame(
			[
				'https://network.test',
				'https://shop.example.com',
				'https://network.test/ftp',
				'https://network.test/long',
				'https://network.test/nohost',
				'HTTP://Network.test/upper',
			],
			$homes,
			'without a trailing slash; an address the API would refuse comes from the sites table'
		);
	}

	public function test_a_site_without_a_home_option_gets_its_address_from_the_sites_table(): void {
		$this->options['home'] = 'http://network.test';
		$this->db->add_site( 1, 'network.test' );
		$this->db->add_site( 2, 'shop.network.test', '/', [], [ 'home' => null ] );

		$report = $this->network_report();

		$this->assertSame( 'http://shop.network.test', $report['subsites'][1]['home_url'] ?? null );
	}

	public function test_a_failed_read_of_the_sites_leaves_the_key_out(): void {
		$this->small_network();
		$this->db->blogs_fail = true;

		$this->assertNull( $this->network_report(), 'an empty list would tell the API every site is gone' );
		$this->assertSame( [ 'data' => [] ], OptionsController::with_scope( true, static fn() => Controller::with_subsites( [ 'data' => [] ] ) ) );
	}

	public function test_an_empty_sites_table_leaves_the_key_out(): void {
		$this->assertNull( $this->network_report(), 'a network always has its main site' );
	}

	public function test_at_most_ten_thousand_sites_are_reported(): void {
		for ( $id = 1; $id <= Subsites::LIMIT; $id++ ) {
			$this->db->add_site( $id, 'network.test', '/s' . $id . '/' );
		}

		$report = $this->network_report();
		$this->assertCount( Subsites::LIMIT, $report['subsites'] ?? [] );
		$this->assertFalse( $report['subsites_truncated'], 'exactly the limit' );
		$this->assertSame( Subsites::LIMIT, $report['subsites'][ Subsites::LIMIT - 1 ]['blog_id'] );
		$this->assertCount( 11, $this->db->blog_queries(), 'ten pages and a check for one more' );
		$this->assertCount( 111, $this->db->queries, 'and a query per hundred option tables' );

		$this->db->queries = [];
		$this->db->add_site( Subsites::LIMIT + 5, 'network.test', '/last/' );
		$report = $this->network_report();
		$this->assertCount( Subsites::LIMIT, $report['subsites'] ?? [] );
		$this->assertTrue( $report['subsites_truncated'], 'one more' );
		$this->assertSame( Subsites::LIMIT, $report['subsites'][ Subsites::LIMIT - 1 ]['blog_id'], 'the first ones by blog id' );
	}

	public function test_names_are_text_without_control_characters_and_at_most_200_characters(): void {
		$this->assertSame( 'Tom & Jerry\'s "Shop" <b>', Subsites::name( 'Tom &amp; Jerry&#039;s &quot;Shop&quot; &lt;b&gt;' ) );
		$this->assertSame( 'One Two Three', Subsites::name( " One\nTwo\t\x7FThree\r\n" ) );
		$this->assertSame( 'Caf' . "\u{e9}" . ' ' . "\u{65e5}\u{672c}", Subsites::name( 'Caf&eacute; ' . "\u{65e5}\u{672c}" ) );
		$this->assertSame( str_repeat( "\u{e9}", 200 ), Subsites::name( str_repeat( "\u{e9}", 250 ) ) );
		$this->assertSame( 1, preg_match( '//u', Subsites::name( "Bad \xC3\x28 byte" ) ), 'valid UTF-8' );
		$this->assertSame( '', Subsites::name( '' ) );
	}

	public function test_a_change_on_a_subsite_schedules_one_report_on_the_main_site(): void {
		$this->blog = 3;
		$before     = time();

		Subsites::schedule_report();

		$this->assertCount( 2, $this->as_calls );
		$this->assertSame( [ 'unschedule', 1, [ 'scanfully_sync_site_health', [ 'trigger' => 'debounced' ], 'scanfully' ] ], $this->as_calls[0], 'the pending one is moved on' );
		[ $call, $blog, $args ] = $this->as_calls[1];
		$this->assertSame( [ 'schedule', 1 ], [ $call, $blog ], 'on the main site' );
		$this->assertSame( [ 'scanfully_sync_site_health', [ 'trigger' => 'debounced' ], 'scanfully' ], array_slice( $args, 1 ), 'the debounced site data sync' );
		$this->assertGreaterThanOrEqual( $before + 60, $args[0], 'a minute from now' );
		$this->assertLessThanOrEqual( time() + 60, $args[0] );
		$this->assertSame( 3, $this->blog, 'back on the subsite' );
		$this->assertSame( [], $this->switched );
	}

	public function test_a_change_in_another_network_schedules_on_that_network_s_main_site(): void {
		$this->networks[2]      = [
			'main'    => 7,
			'options' => [
				'active_sitewide_plugins'        => [ 'scanfully/scanfully.php' => time() ],
				'scanfully_connect_is_connected' => 'yes',
			],
		];
		$this->site_networks[8] = 2;
		$this->blog             = 8;

		Subsites::option_changed();
		$this->assertCount( 2, $this->as_calls );
		$this->assertSame( 7, $this->as_calls[1][1], 'the main site of the site\'s own network' );
		$this->assertSame( 8, $this->blog );

		$this->as_calls = [];
		Subsites::site_changed( (object) [ 'site_id' => '2' ] );
		$this->assertSame( [ 7, 7 ], $this->scheduled_on(), 'a site created or deleted there' );

		$this->as_calls = [];
		unset( $this->networks[2]['options']['scanfully_connect_is_connected'] );
		Subsites::site_changed( (object) [ 'site_id' => '2' ] );
		$this->assertSame( [], $this->as_calls, 'that network is not connected, whatever this one is' );
	}

	public function test_a_site_moved_to_another_network_reports_for_both(): void {
		$this->networks[2] = [
			'main'    => 7,
			'options' => [
				'active_sitewide_plugins'        => [ 'scanfully/scanfully.php' => time() ],
				'scanfully_connect_is_connected' => 'yes',
			],
		];
		$old               = (object) [
			'domain'  => 'network.test',
			'path'    => '/blog/',
			'site_id' => '1',
		];
		$new               = clone $old;
		$new->site_id      = '2';

		Subsites::site_updated( $new, $old );

		$this->assertSame( [ 7, 7, 1, 1 ], $this->scheduled_on(), 'the network it joined and the one it left' );
	}

	public function test_a_change_on_the_main_site_needs_no_switch(): void {
		Functions\expect( 'switch_to_blog' )->never();

		Subsites::schedule_report();

		$this->assertCount( 2, $this->as_calls );
		$this->assertSame( 1, $this->as_calls[1][1] );
	}

	public function test_nothing_is_scheduled_without_the_network_connection(): void {
		$this->blog = 3;

		unset( $this->site_options['scanfully_connect_is_connected'] );
		Subsites::schedule_report();
		$this->assertSame( [], $this->as_calls, 'the network is not connected' );

		$this->site_options['scanfully_connect_is_connected'] = 'yes';
		$this->site_options['active_sitewide_plugins']        = [];
		Subsites::schedule_report();
		$this->assertSame( [], $this->as_calls, 'the plugin is not network-activated' );

		$this->site_options['active_sitewide_plugins'] = [ 'scanfully/scanfully.php' => time() ];
		$this->as_init                                 = 0;
		Subsites::schedule_report();
		$this->assertSame( [], $this->as_calls, 'Action Scheduler is not ready' );

		$this->multisite = false;
		$this->as_init   = 1;
		Subsites::schedule_report();
		$this->assertSame( [], $this->as_calls, 'a single site' );
		$this->assertSame( 3, $this->blog );
	}

	public function test_only_a_change_the_report_shows_schedules_one(): void {
		$old = (object) [
			'blog_id'      => '3',
			'domain'       => 'network.test',
			'path'         => '/blog/',
			'public'       => '1',
			'archived'     => '0',
			'spam'         => '0',
			'deleted'      => '0',
			'site_id'      => '1',
			'last_updated' => '2026-11-11 10:00:00',
		];

		$touched               = clone $old;
		$touched->last_updated = '2026-11-11 10:05:00';
		Subsites::site_updated( $touched, $old );
		$this->assertSame( [], $this->as_calls, 'a post published on the site' );

		Subsites::site_updated( null, $old );
		$this->assertSame( [], $this->as_calls );

		$changes = [
			'domain'   => 'blog.example.com',
			'path'     => '/news/',
			'public'   => '0',
			'archived' => '1',
			'spam'     => '1',
			'deleted'  => '1',
			// Moved to network 2, which is not connected: network 1 reports.
			'site_id'  => '2',
		];
		foreach ( $changes as $field => $value ) {
			$this->as_calls  = [];
			$changed         = clone $old;
			$changed->$field = $value;
			Subsites::site_updated( $changed, $old );
			$this->assertCount( 2, $this->as_calls, $field );
		}
	}

	public function test_the_sites_are_watched_on_a_network_only(): void {
		$this->multisite = false;
		Subsites::setup();
		$this->assertFalse( has_action( 'wp_initialize_site', [ Subsites::class, 'site_changed' ] ) );
		$this->assertFalse( has_action( 'wp_update_site', [ Subsites::class, 'site_updated' ] ) );

		$this->multisite = true;
		Subsites::setup();
		$this->assertTrue( has_action( 'wp_initialize_site', [ Subsites::class, 'site_changed' ], 100 ), 'after WordPress set the site up' );
		$this->assertTrue( has_action( 'wp_update_site', [ Subsites::class, 'site_updated' ], 10 ) );
		$this->assertTrue( has_action( 'wp_delete_site', [ Subsites::class, 'site_changed' ], 10 ) );
		$this->assertTrue( has_action( 'update_option_blogname', [ Subsites::class, 'option_changed' ], 10 ) );
		$this->assertTrue( has_action( 'update_option_home', [ Subsites::class, 'option_changed' ], 10 ) );
	}
}
