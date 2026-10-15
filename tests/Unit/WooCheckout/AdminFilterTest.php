<?php
/**
 * Admin filter unit tests: probe orders and probe subscriptions stay out of
 * the WooCommerce admin lists and their status counts.
 *
 * @package Scanfully\Tests\Unit\WooCheckout
 */

namespace Scanfully\Tests\Unit\WooCheckout;

use Brain\Monkey\Functions;
use Mockery;
use ReflectionProperty;
use Scanfully\WooCheckout\AdminFilter;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\WooCheckout\AdminFilter
 */
final class AdminFilterTest extends TestCase {

	/**
	 * Order types the probe count queries ran for, in order.
	 *
	 * @var string[]
	 */
	private array $counted_types = [];

	protected function setUp(): void {
		parent::setUp();
		$this->reset_cache();
		unset( $_GET[ AdminFilter::TOGGLE_PARAM ] );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'get_post_status_object' )->alias(
			static fn( $status ) => (object) [ 'show_in_admin_all_list' => 'trash' !== $status ]
		);
	}

	protected function tearDown(): void {
		// Mockery expectations (once/shouldNotReceive) are the assertions in the
		// legacy list query tests; count them so PHPUnit sees them.
		$this->addToAssertionCount( Mockery::getContainer()->mockery_getExpectationCount() );

		$this->reset_cache();
		unset( $_GET[ AdminFilter::TOGGLE_PARAM ], $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Clear the per-request probe count cache.
	 */
	private function reset_cache(): void {
		$property = new ReflectionProperty( AdminFilter::class, 'probe_counts_cache' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * Install a wpdb double whose probe count query returns the given counts.
	 *
	 * @param array<string,int> $counts Status slug => probe count.
	 */
	private function probe_counts( array $counts ): void {
		$rows = [];
		foreach ( $counts as $status => $count ) {
			$rows[] = (object) [
				'status' => $status,
				'cnt'    => (string) $count,
			];
		}

		$wpdb           = Mockery::mock( 'wpdb' );
		$wpdb->prefix   = 'wp_';
		$wpdb->posts    = 'wp_posts';
		$wpdb->postmeta = 'wp_postmeta';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( string $sql, string $type, string $meta_key ): string {
				$this->counted_types[] = $type;
				$this->assertSame( AdminFilter::META_KEY, $meta_key );
				return $sql;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturn( $rows );
		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * A main admin WP_Query double for a post type.
	 *
	 * @param string $post_type Post type being listed.
	 *
	 * @return \Mockery\MockInterface&\WP_Query
	 */
	private function list_query( string $post_type ) {
		$query = Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'post_type' )->andReturn( $post_type );
		$query->shouldReceive( 'get' )->with( 'meta_query' )->andReturn( '' );
		return $query;
	}

	/**
	 * A subscription status view link as WooCommerce Subscriptions renders it.
	 *
	 * @param string $label Status label.
	 * @param int    $count Count shown.
	 *
	 * @return string
	 */
	private function view( string $label, int $count ): string {
		return '<a href="https://shop.test/wp-admin/admin.php?page=wc-orders--shop_subscription">' . $label . ' <span class="count">(' . $count . ')</span></a>';
	}

	public function test_setup_hooks_the_subscription_list_and_counts(): void {
		AdminFilter::setup();

		$this->assertNotFalse( has_filter( 'woocommerce_order_list_table_prepare_items_query_args', [ AdminFilter::class, 'filter_hpos_query_args' ] ) );
		$this->assertNotFalse( has_filter( 'woocommerce_shop_subscription_list_table_order_count', [ AdminFilter::class, 'filter_hpos_subscription_status_counts' ] ) );
		$this->assertSame( 20, has_filter( 'views_woocommerce_page_wc-orders--shop_subscription', [ AdminFilter::class, 'filter_hpos_subscription_views' ] ) );
		$this->assertNotFalse( has_action( 'pre_get_posts', [ AdminFilter::class, 'filter_legacy_orders' ] ) );
		$this->assertNotFalse( has_filter( 'wp_count_posts', [ AdminFilter::class, 'filter_legacy_status_counts' ] ) );
	}

	public function test_the_hpos_list_query_excludes_probes(): void {
		$args = AdminFilter::filter_hpos_query_args( [ 'type' => 'shop_subscription' ] );

		$this->assertSame(
			[
				[
					'key'     => AdminFilter::META_KEY,
					'compare' => 'NOT EXISTS',
				],
			],
			$args['meta_query']
		);
	}

	public function test_the_toggle_keeps_probes_in_the_hpos_list_query(): void {
		$_GET[ AdminFilter::TOGGLE_PARAM ] = '1';

		$this->assertSame( [ 'type' => 'shop_subscription' ], AdminFilter::filter_hpos_query_args( [ 'type' => 'shop_subscription' ] ) );
	}

	/**
	 * @dataProvider data_order_types
	 *
	 * @param string $post_type Post type being listed.
	 */
	public function test_the_legacy_list_query_excludes_probes( string $post_type ): void {
		$query = $this->list_query( $post_type );
		$query->shouldReceive( 'set' )->once()->with(
			'meta_query',
			[
				[
					'key'     => AdminFilter::META_KEY,
					'compare' => 'NOT EXISTS',
				],
			]
		);

		AdminFilter::filter_legacy_orders( $query );
	}

	/**
	 * Order types whose admin lists hide probes.
	 *
	 * @return array<string,array{string}>
	 */
	public function data_order_types(): array {
		return [
			'orders'        => [ 'shop_order' ],
			'subscriptions' => [ 'shop_subscription' ],
		];
	}

	public function test_the_legacy_list_query_leaves_other_post_types_alone(): void {
		$query = $this->list_query( 'product' );
		$query->shouldNotReceive( 'set' );

		AdminFilter::filter_legacy_orders( $query );
	}

	public function test_the_toggle_keeps_probes_in_the_legacy_subscription_list(): void {
		$_GET[ AdminFilter::TOGGLE_PARAM ] = '1';
		$query                             = $this->list_query( 'shop_subscription' );
		$query->shouldNotReceive( 'set' );

		AdminFilter::filter_legacy_orders( $query );
	}

	public function test_legacy_subscription_counts_leave_out_probes(): void {
		$this->probe_counts( [ 'wc-cancelled' => 2 ] );
		$counts = (object) [
			'wc-active'    => 4,
			'wc-cancelled' => 3,
		];

		$counts = AdminFilter::filter_legacy_status_counts( $counts, 'shop_subscription' );

		$this->assertSame( 4, $counts->{'wc-active'} );
		$this->assertSame( 1, $counts->{'wc-cancelled'} );
		$this->assertSame( [ 'shop_subscription' ], $this->counted_types );
	}

	public function test_legacy_counts_of_other_post_types_are_untouched(): void {
		$counts = (object) [ 'publish' => 5 ];

		$this->assertSame( 5, AdminFilter::filter_legacy_status_counts( $counts, 'product' )->publish );
	}

	public function test_the_toggle_keeps_probes_in_legacy_subscription_counts(): void {
		$_GET[ AdminFilter::TOGGLE_PARAM ] = '1';
		$counts                            = (object) [ 'wc-cancelled' => 3 ];

		$this->assertSame( 3, AdminFilter::filter_legacy_status_counts( $counts, 'shop_subscription' )->{'wc-cancelled'} );
	}

	public function test_hpos_subscription_counts_leave_out_probes(): void {
		$this->probe_counts( [ 'wc-cancelled' => 2 ] );

		$this->assertSame( 1, AdminFilter::filter_hpos_subscription_status_counts( 3, [ 'wc-cancelled' ] ) );
		$this->assertSame( 4, AdminFilter::filter_hpos_subscription_status_counts( 4, 'wc-active' ) );
		$this->assertSame( [ 'shop_subscription' ], $this->counted_types );
	}

	public function test_probe_counts_are_kept_apart_per_order_type(): void {
		$this->probe_counts( [ 'wc-cancelled' => 1 ] );

		AdminFilter::filter_hpos_status_counts( 5, 'wc-cancelled' );
		AdminFilter::filter_hpos_subscription_status_counts( 5, 'wc-cancelled' );
		AdminFilter::filter_hpos_subscription_status_counts( 5, 'wc-cancelled' );

		$this->assertSame( [ 'shop_order', 'shop_subscription' ], $this->counted_types );
	}

	public function test_hpos_subscription_views_leave_out_probes(): void {
		$this->probe_counts(
			[
				'wc-cancelled' => 2,
				'wc-on-hold'   => 1,
				'trash'        => 1,
			]
		);
		$views = [
			'all'          => $this->view( 'All', 6 ),
			'wc-active'    => $this->view( 'Active', 2 ),
			'wc-cancelled' => $this->view( 'Cancelled', 3 ),
			'wc-on-hold'   => $this->view( 'On hold', 1 ),
			'trash'        => $this->view( 'Trash', 2 ),
		];

		$views = AdminFilter::filter_hpos_subscription_views( $views );

		$this->assertSame(
			[
				'all'          => $this->view( 'All', 3 ),
				'wc-active'    => $this->view( 'Active', 2 ),
				'wc-cancelled' => $this->view( 'Cancelled', 1 ),
				'trash'        => $this->view( 'Trash', 1 ),
			],
			$views
		);
	}

	public function test_hpos_subscription_views_keep_all_when_only_probes_exist(): void {
		$this->probe_counts( [ 'wc-cancelled' => 2 ] );
		$views = [
			'all'          => $this->view( 'All', 2 ),
			'wc-cancelled' => $this->view( 'Cancelled', 2 ),
		];

		$this->assertSame( [ 'all' => $this->view( 'All', 0 ) ], AdminFilter::filter_hpos_subscription_views( $views ) );
	}

	public function test_the_toggle_keeps_probes_in_hpos_subscription_views(): void {
		$_GET[ AdminFilter::TOGGLE_PARAM ] = '1';
		$views                             = [ 'wc-cancelled' => $this->view( 'Cancelled', 2 ) ];

		$this->assertSame( $views, AdminFilter::filter_hpos_subscription_views( $views ) );
	}
}
