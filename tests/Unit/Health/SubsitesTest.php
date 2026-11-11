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
		Functions\when( 'get_main_site_id' )->justReturn( 1 );
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
	}

	public function test_a_site_without_its_options_table_keeps_its_place(): void {
		$this->small_network();
		$this->db->missing = [ 'wp_3_options' ];

		$report = $this->network_report();

		$this->assertNotNull( $report );
		$this->assertCount( 6, $report['subsites'] );
		$this->assertSame( 'https://network.test/blog', $report['subsites'][2]['home_url'], 'the address from the sites table, with the main site\'s scheme' );
		$this->assertSame( '', $report['subsites'][2]['name'] );
		$this->assertSame( 'Shop', $report['subsites'][1]['name'], 'the other sites are read one by one' );
		$this->assertFalse( $this->db->suppressed, 'errors are shown again afterwards' );
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
		$this->assertFalse( has_action( 'wp_initialize_site', [ Subsites::class, 'schedule_report' ] ) );
		$this->assertFalse( has_action( 'wp_update_site', [ Subsites::class, 'site_updated' ] ) );

		$this->multisite = true;
		Subsites::setup();
		$this->assertTrue( has_action( 'wp_initialize_site', [ Subsites::class, 'schedule_report' ], 100 ), 'after WordPress set the site up' );
		$this->assertTrue( has_action( 'wp_update_site', [ Subsites::class, 'site_updated' ], 10 ) );
		$this->assertTrue( has_action( 'wp_delete_site', [ Subsites::class, 'schedule_report' ], 10 ) );
		$this->assertTrue( has_action( 'update_option_blogname', [ Subsites::class, 'schedule_report' ], 10 ) );
		$this->assertTrue( has_action( 'update_option_home', [ Subsites::class, 'schedule_report' ], 10 ) );
	}
}
