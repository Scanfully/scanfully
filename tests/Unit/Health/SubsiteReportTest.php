<?php
/**
 * Subsite report unit tests.
 *
 * @package Scanfully\Tests\Unit\Health
 */

namespace Scanfully\Tests\Unit\Health;

use Brain\Monkey\Functions;
use Scanfully\Connect\Children;
use Scanfully\Health\SubsiteReport;
use Scanfully\Tests\Unit\Connect\NetworkFixture;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Health\SubsiteReport
 * @covers \Scanfully\API\Request::to
 * @covers \Scanfully\API\Request::get_auth_headers
 */
final class SubsiteReportTest extends TestCase {

	use NetworkFixture;

	/**
	 * The requests sent: URL, headers and decoded body.
	 *
	 * @var array<int, array{url: string, headers: array<string, string>, body: mixed}>
	 */
	private array $sent = [];

	/**
	 * The API's answer by child site id; 200 when not set.
	 *
	 * @var array<string, int>
	 */
	private array $answers = [];

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_network();
		SubsiteReport::reset();
		$this->sent    = [];
		$this->answers = [];
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_post' )->alias(
			function ( string $url, array $args ): array {
				$this->sent[] = [
					'url'     => $url,
					'headers' => $args['headers'],
					'body'    => json_decode( $args['body'], true ),
				];
				preg_match( '#/sites/([^/]+)/#', $url, $match );

				return [ 'status' => $this->answers[ $match[1] ] ?? 200 ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $response ): int => $response['status'] );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'set_transient' )->justReturn( true );
		// The network's token is fresh and on the OAuth server.
		$this->site_options['scanfully_connect_flow']    = 'oauth';
		$this->site_options['scanfully_connect_expires'] = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );

		$this->db->options[2]['blogname']            = 'Shop &amp; Co';
		$this->db->options[2]['WPLANG']              = 'nl_NL';
		$this->db->options[2]['timezone_string']     = 'Europe/Amsterdam';
		$this->db->options[2]['gmt_offset']          = '1';
		$this->db->options[2]['permalink_structure'] = '/%postname%/';
		$this->db->options[2]['active_plugins']      = serialize( [ 'woocommerce/woocommerce.php', 'shop-extra/shop-extra.php' ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- As WordPress stores it.
		$this->db->options[2]['stylesheet']          = 'storefront-child';
		$this->db->options[2]['template']            = 'storefront';
		$this->db->sites[3]['public']                = '0';
	}

	protected function tearDown(): void {
		$this->tear_down_network();
		parent::tearDown();
	}

	public function test_a_monitored_subsite_reports_to_its_child_with_the_networks_token(): void {
		$this->monitor( [ 2 => 'child-2' ] );

		SubsiteReport::run( [ 2, 3 ] );

		$this->assertCount( 1, $this->sent );
		$request = $this->sent[0];
		$this->assertSame( 'https://api.scanfully.com/v1/sites/child-2/health/data', $request['url'] );
		$this->assertSame( 'Bearer net-token', $request['headers']['Authorization'] );
		$this->assertSame( '2', $request['headers']['X-Scanfully-Blog-Id'] );
		$this->assertSame( [ 'subsite_report', 'reported_at' ], array_keys( $request['body'] ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $request['body']['reported_at'] );
		$this->assertSame(
			[
				'blog_id'                => 2,
				'home_url'               => 'https://network.test/shop',
				'name'                   => 'Shop & Co',
				'public'                 => true,
				'locale'                 => 'nl_NL',
				'timezone_string'        => 'Europe/Amsterdam',
				'gmt_offset'             => 1,
				'permalink_structure'    => '/%postname%/',
				'active_plugins'         => [ 'woocommerce/woocommerce.php', 'shop-extra/shop-extra.php' ],
				'network_active_plugins' => [ 'scanfully/scanfully.php' ],
				'stylesheet'             => 'storefront-child',
				'template'               => 'storefront',
				'woocommerce_active'     => true,
			],
			$request['body']['subsite_report']
		);
		// Read without switching; the one switch is the WooCommerce nudge.
		$this->assertSame( [ 2 ], $this->switches );
	}

	public function test_the_report_of_a_plain_subsite(): void {
		$this->monitor( [ 3 => 'child-3' ] );
		$this->site_options['WPLANG'] = 'de_DE';

		SubsiteReport::run( [ 3 ] );

		$report = $this->sent[0]['body']['subsite_report'];
		$this->assertFalse( $report['public'] );
		$this->assertSame( 'de_DE', $report['locale'] );
		$this->assertSame( [], $report['active_plugins'] );
		$this->assertSame( '', $report['stylesheet'] );
		$this->assertFalse( $report['woocommerce_active'] );
		$this->assertSame( 0, (int) $report['gmt_offset'] );
	}

	public function test_nothing_is_sent_for_an_unmonitored_subsite(): void {
		SubsiteReport::run( [ 2, 3 ] );

		$this->assertSame( [], $this->sent );
	}

	public function test_nothing_is_sent_from_a_subsite_or_without_the_network_connection(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->on_blog( 2 );
		SubsiteReport::run( [ 2 ] );
		$this->assertSame( [], $this->sent );

		$this->site_options['scanfully_connect_is_connected'] = 'no';
		$this->on_blog( 1 );
		SubsiteReport::run( [ 2 ] );
		$this->assertSame( [], $this->sent );
	}

	public function test_nothing_is_sent_from_a_copy_of_the_network(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->db->options[1]['home'] = 'https://staging.network.test';
		$this->on_blog( 1 );

		SubsiteReport::run( [ 2 ] );

		$this->assertSame( [], $this->sent );
	}

	public function test_the_three_hourly_run_reports_every_monitored_subsite_in_jobs(): void {
		$mapping = [];
		for ( $blog = 10; $blog < 40; $blog++ ) {
			$mapping[ $blog ] = 'child-' . $blog;
		}
		$this->site_options[ Children::MAPPING_OPTION ] = $mapping;

		SubsiteReport::run();

		$jobs = $this->as_calls_of( SubsiteReport::ACTION );
		$this->assertCount( 2, $jobs );
		$this->assertSame( [ 'blogs' => range( 10, 34 ) ], $jobs[0][3] );
		$this->assertSame( [ 'blogs' => range( 35, 39 ) ], $jobs[1][3] );
		$this->assertSame( [], $this->sent );
	}

	public function test_a_gone_child_site_is_forgotten(): void {
		$this->monitor(
			[
				2 => 'child-2',
				3 => 'child-3',
			]
		);
		$this->answers['child-2'] = 404;

		SubsiteReport::run( [ 2, 3 ] );

		$this->assertCount( 2, $this->sent );
		$this->assertSame( [ 3 => 'child-3' ], $this->site_options[ Children::MAPPING_OPTION ] );
	}

	public function test_a_refused_token_is_refreshed_first_and_the_rest_goes_later(): void {
		$this->monitor(
			[
				2 => 'child-2',
				3 => 'child-3',
			]
		);
		$this->answers['child-2'] = 401;

		SubsiteReport::run( [ 2, 3 ] );

		$this->assertCount( 1, $this->sent );
		$later = $this->as_calls_of( SubsiteReport::ACTION );
		$this->assertSame( [ 'blogs' => [ 2, 3 ] ], $later[0][3] );
		// The network's token expires now: the main site refreshes it next.
		$this->assertLessThanOrEqual( time(), strtotime( $this->site_options['scanfully_connect_expires'] . ' UTC' ) );
	}

	public function test_a_subsite_with_woocommerce_and_no_probe_secret_is_nudged_once(): void {
		$this->monitor( [ 2 => 'child-2' ] );

		SubsiteReport::run( [ 2 ] );

		$nudges = $this->as_calls_of( 'scanfully_sync_woocheckout_config' );
		$this->assertCount( 1, $nudges );
		$this->assertSame( 2, $nudges[0][1] );
		$this->assertSame( 1, $this->blog );

		$this->as_calls = [];
		$this->db->options[2]['scanfully_woocheckout_probe_secret_network'] = str_repeat( 'a', 64 );
		SubsiteReport::run( [ 2 ] );
		$this->assertSame( [], $this->as_calls_of( 'scanfully_sync_woocheckout_config' ) );
	}

	public function test_a_report_fixes_a_missing_marker(): void {
		$this->site_options[ Children::MAPPING_OPTION ] = [ 2 => 'child-2' ];

		SubsiteReport::run( [ 2 ] );

		$this->assertArrayHasKey( Children::MARKER_OPTION, $this->db->options[2] );
	}

	public function test_a_change_on_a_monitored_subsite_is_reported_from_the_main_site(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->on_blog( 2 );

		SubsiteReport::changed();
		SubsiteReport::changed();

		$this->assertSame( [ 2 ], $this->site_options[ SubsiteReport::CHANGED_OPTION ] );
		$scheduled = $this->as_calls_of( SubsiteReport::ACTION_CHANGED );
		$this->assertCount( 1, $scheduled );
		$this->assertSame( 1, $scheduled[0][1] );
		$this->assertSame( 2, $this->blog );

		$this->on_blog( 1 );
		SubsiteReport::run_changed();
		$this->assertArrayNotHasKey( SubsiteReport::CHANGED_OPTION, $this->site_options );
		$this->assertSame( [ 'blogs' => [ 2 ] ], $this->as_calls_of( SubsiteReport::ACTION )[0][3] );
	}

	public function test_a_change_on_an_unmonitored_subsite_or_the_main_site_is_ignored(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->on_blog( 3 );
		SubsiteReport::changed();
		$this->on_blog( 1 );
		SubsiteReport::changed();

		$this->assertArrayNotHasKey( SubsiteReport::CHANGED_OPTION, $this->site_options );
		$this->assertSame( [], $this->as_calls );
	}

	public function test_an_address_change_still_counts_as_a_change(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->db->options[2]['home'] = 'https://shop.example.com';
		$this->on_blog( 2 );

		SubsiteReport::changed();

		$this->assertSame( [ 2 ], $this->site_options[ SubsiteReport::CHANGED_OPTION ] );
	}

	public function test_a_change_of_the_networks_plugins_reports_every_child(): void {
		$this->monitor(
			[
				2 => 'child-2',
				3 => 'child-3',
			]
		);

		SubsiteReport::network_plugins_changed();
		SubsiteReport::run_changed();

		$this->assertSame( [ 'blogs' => [ 2, 3 ] ], $this->as_calls_of( SubsiteReport::ACTION )[0][3] );
	}

	public function test_the_recurring_report_is_scheduled_on_the_main_site_only(): void {
		SubsiteReport::maybe_schedule();
		$this->assertCount( 1, $this->as_calls_of( SubsiteReport::ACTION ) );

		$this->as_calls = [];
		$this->on_blog( 2 );
		SubsiteReport::maybe_schedule();
		$this->assertSame( [], $this->as_calls );
	}
}
