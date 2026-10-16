<?php
/**
 * Admin filter — hide Scanfully probe orders, and the subscriptions they create,
 * from the WC admin orders and subscriptions lists by default to keep merchant
 * noise low. Toggle with `?scanfully_show_probe=1`.
 *
 * @package Scanfully
 */

namespace Scanfully\WooCheckout;

/**
 * Hide probe orders and probe subscriptions from the WooCommerce admin lists.
 */
class AdminFilter {

	public const META_KEY     = '_scanfully_probe_order';
	public const TOGGLE_PARAM = 'scanfully_show_probe';

	/**
	 * Order types that can be probes: test orders and the subscriptions they create.
	 */
	private const ORDER_TYPES = [ 'shop_order', 'shop_subscription' ];

	/**
	 * Probe counts per status, keyed by "{backend}:{order type}", cached per request.
	 *
	 * @var array<string,array<string,int>>
	 */
	private static array $probe_counts_cache = [];

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function setup(): void {
		// HPOS order list (WC 8.0+). This generic filter runs for every order type's
		// list table, so it covers the WooCommerce Subscriptions list too.
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', [ self::class, 'filter_hpos_query_args' ] );

		// Legacy CPT order and subscription lists.
		add_action( 'pre_get_posts', [ self::class, 'filter_legacy_orders' ] );

		// Status view counts, e.g. "Pending payment (1)". These bypass the list query.
		add_filter( 'woocommerce_shop_order_list_table_order_count', [ self::class, 'filter_hpos_status_counts' ], 10, 2 );
		add_filter( 'woocommerce_shop_subscription_list_table_order_count', [ self::class, 'filter_hpos_subscription_status_counts' ], 10, 2 );
		add_filter( 'wp_count_posts', [ self::class, 'filter_legacy_status_counts' ], 10, 2 );

		// WooCommerce Subscriptions replaces the HPOS subscription views at priority 10
		// with counts from its own unfiltered query, so correct them afterwards.
		add_filter( 'views_woocommerce_page_wc-orders--shop_subscription', [ self::class, 'filter_hpos_subscription_views' ], 20 );
	}

	/**
	 * Whether probe orders should be shown (toggled via query param).
	 *
	 * @return bool
	 */
	private static function should_show_probe_orders(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET[ self::TOGGLE_PARAM ] ) && '1' === sanitize_text_field( wp_unslash( $_GET[ self::TOGGLE_PARAM ] ) );
	}

	/**
	 * Filter the HPOS order-list query args to exclude probe orders.
	 *
	 * @param array $args Query args.
	 *
	 * @return array
	 */
	public static function filter_hpos_query_args( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		if ( self::should_show_probe_orders() ) {
			return $args;
		}
		$meta_query         = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : [];
		$meta_query[]       = [
			'key'     => self::META_KEY,
			'compare' => 'NOT EXISTS',
		];
		$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return $args;
	}

	/**
	 * Filter the legacy CPT order- and subscription-list query to exclude probes.
	 *
	 * @param \WP_Query $query The query.
	 *
	 * @return void
	 */
	public static function filter_legacy_orders( $query ): void {
		if ( ! is_admin() || ! ( $query instanceof \WP_Query ) ) {
			return;
		}
		if ( ! $query->is_main_query() ) {
			return;
		}
		$post_type = $query->get( 'post_type' );
		if ( ! in_array( $post_type, self::ORDER_TYPES, true ) ) {
			return;
		}
		if ( self::should_show_probe_orders() ) {
			return;
		}
		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = [];
		}
		$meta_query[] = [
			'key'     => self::META_KEY,
			'compare' => 'NOT EXISTS',
		];
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Subtract probe orders from the HPOS status view counts ("Pending payment (1)" etc.).
	 *
	 * @param int             $count  Order count for the status(es).
	 * @param string|string[] $status Status slug(s) being counted.
	 *
	 * @return int
	 */
	public static function filter_hpos_status_counts( $count, $status ) {
		return self::subtract_hpos_probe_counts( $count, $status, 'shop_order' );
	}

	/**
	 * Subtract probe subscriptions from the HPOS subscription status counts.
	 *
	 * @param int             $count  Subscription count for the status(es).
	 * @param string|string[] $status Status slug(s) being counted.
	 *
	 * @return int
	 */
	public static function filter_hpos_subscription_status_counts( $count, $status ) {
		return self::subtract_hpos_probe_counts( $count, $status, 'shop_subscription' );
	}

	/**
	 * Subtract probes of one order type from an HPOS status count.
	 *
	 * @param int             $count  Count for the status(es).
	 * @param string|string[] $status Status slug(s) being counted.
	 * @param string          $type   Order type.
	 *
	 * @return int
	 */
	private static function subtract_hpos_probe_counts( $count, $status, string $type ) {
		if ( self::should_show_probe_orders() ) {
			return $count;
		}
		$probe_counts = self::get_probe_counts_by_status( 'hpos', $type );
		foreach ( (array) $status as $slug ) {
			if ( isset( $probe_counts[ $slug ] ) ) {
				$count -= $probe_counts[ $slug ];
			}
		}
		return max( 0, (int) $count );
	}

	/**
	 * Subtract probe subscriptions from the HPOS subscription status views.
	 *
	 * WooCommerce Subscriptions builds these links itself, each as
	 * `Label <span class="count">(N)</span>`, and leaves out statuses without
	 * subscriptions; a status view left with only probes is removed the same way.
	 *
	 * @param array<string,string> $views View links keyed by status slug.
	 *
	 * @return array<string,string>
	 */
	public static function filter_hpos_subscription_views( $views ) {
		if ( ! is_array( $views ) || self::should_show_probe_orders() ) {
			return $views;
		}
		$probe_counts = self::get_probe_counts_by_status( 'hpos', 'shop_subscription' );
		if ( empty( $probe_counts ) ) {
			return $views;
		}

		foreach ( $views as $slug => $link ) {
			if ( 'all' === $slug ) {
				// "All" sums the statuses that show in the all list (not trash).
				$probe_count = 0;
				foreach ( $probe_counts as $status => $status_count ) {
					$status_object = get_post_status_object( $status );
					if ( $status_object && $status_object->show_in_admin_all_list ) {
						$probe_count += $status_count;
					}
				}
			} else {
				$probe_count = $probe_counts[ $slug ] ?? 0;
			}
			if ( 0 === $probe_count || ! is_string( $link ) ) {
				continue;
			}

			$remaining      = null;
			$views[ $slug ] = (string) preg_replace_callback(
				'/<span class="count">\(([\d.,\s]+)\)<\/span>/',
				static function ( array $matches ) use ( $probe_count, &$remaining ): string {
					$remaining = max( 0, (int) preg_replace( '/\D/', '', $matches[1] ) - $probe_count );
					return '<span class="count">(' . $remaining . ')</span>';
				},
				$link,
				1
			);
			if ( 0 === $remaining && 'all' !== $slug ) {
				unset( $views[ $slug ] );
			}
		}
		return $views;
	}

	/**
	 * Subtract probes from the legacy CPT order and subscription status counts (wp_count_posts).
	 *
	 * @param \stdClass $counts Counts keyed by post status.
	 * @param string    $type   Post type being counted.
	 *
	 * @return \stdClass
	 */
	public static function filter_legacy_status_counts( $counts, $type ) {
		if ( ! in_array( $type, self::ORDER_TYPES, true ) || self::should_show_probe_orders() ) {
			return $counts;
		}
		foreach ( self::get_probe_counts_by_status( 'legacy', $type ) as $slug => $probe_count ) {
			if ( isset( $counts->$slug ) ) {
				$counts->$slug = max( 0, (int) $counts->$slug - $probe_count );
			}
		}
		return $counts;
	}

	/**
	 * Count probes of one order type per status, cached per request.
	 *
	 * @param string $backend Either 'hpos' or 'legacy'.
	 * @param string $type    Order type, 'shop_order' or 'shop_subscription'.
	 *
	 * @return array<string,int> Status slug => probe count.
	 */
	private static function get_probe_counts_by_status( string $backend, string $type ): array {
		$cache_key = $backend . ':' . $type;
		if ( isset( self::$probe_counts_cache[ $cache_key ] ) ) {
			return self::$probe_counts_cache[ $cache_key ];
		}

		global $wpdb;

		if ( 'hpos' === $backend ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT o.status AS status, COUNT(*) AS cnt
					FROM {$wpdb->prefix}wc_orders o
					INNER JOIN {$wpdb->prefix}wc_orders_meta m ON m.order_id = o.id
					WHERE o.type = %s AND m.meta_key = %s
					GROUP BY o.status",
					$type,
					self::META_KEY
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.post_status AS status, COUNT(*) AS cnt
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
					WHERE p.post_type = %s AND m.meta_key = %s
					GROUP BY p.post_status",
					$type,
					self::META_KEY
				)
			);
		}

		$counts = [];
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row->status ] = (int) $row->cnt;
		}

		self::$probe_counts_cache[ $cache_key ] = $counts;
		return $counts;
	}
}
