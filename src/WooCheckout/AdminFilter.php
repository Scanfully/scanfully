<?php
/**
 * Admin filter — hide Scanfully probe orders from the WC admin orders list
 * by default to keep merchant noise low. Toggle with `?scanfully_show_probe=1`.
 *
 * @package Scanfully
 */

namespace Scanfully\WooCheckout;

/**
 * Hide probe orders from the WooCommerce admin order list.
 */
class AdminFilter {

	public const META_KEY     = '_scanfully_probe_order';
	public const TOGGLE_PARAM = 'scanfully_show_probe';

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function setup(): void {
		// HPOS order list (WC 8.0+).
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', [ self::class, 'filter_hpos_query_args' ] );

		// Legacy CPT order list.
		add_action( 'pre_get_posts', [ self::class, 'filter_legacy_orders' ] );

		// Status view counts, e.g. "Pending payment (1)". These bypass the list query.
		add_filter( 'woocommerce_shop_order_list_table_order_count', [ self::class, 'filter_hpos_status_counts' ], 10, 2 );
		add_filter( 'wp_count_posts', [ self::class, 'filter_legacy_status_counts' ], 10, 2 );
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
	 * Filter the legacy CPT order-list query to exclude probe orders.
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
		if ( 'shop_order' !== $post_type ) {
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
		if ( self::should_show_probe_orders() ) {
			return $count;
		}
		$probe_counts = self::get_probe_counts_by_status( 'hpos' );
		foreach ( (array) $status as $slug ) {
			if ( isset( $probe_counts[ $slug ] ) ) {
				$count -= $probe_counts[ $slug ];
			}
		}
		return max( 0, (int) $count );
	}

	/**
	 * Subtract probe orders from the legacy CPT status view counts (wp_count_posts).
	 *
	 * @param \stdClass $counts Counts keyed by post status.
	 * @param string    $type   Post type being counted.
	 *
	 * @return \stdClass
	 */
	public static function filter_legacy_status_counts( $counts, $type ) {
		if ( 'shop_order' !== $type || self::should_show_probe_orders() ) {
			return $counts;
		}
		foreach ( self::get_probe_counts_by_status( 'legacy' ) as $slug => $probe_count ) {
			if ( isset( $counts->$slug ) ) {
				$counts->$slug = max( 0, (int) $counts->$slug - $probe_count );
			}
		}
		return $counts;
	}

	/**
	 * Count probe orders per status, cached per request.
	 *
	 * @param string $backend Either 'hpos' or 'legacy'.
	 *
	 * @return array<string,int> Status slug => probe order count.
	 */
	private static function get_probe_counts_by_status( string $backend ): array {
		static $cache = [];
		if ( isset( $cache[ $backend ] ) ) {
			return $cache[ $backend ];
		}

		global $wpdb;

		if ( 'hpos' === $backend ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT o.status AS status, COUNT(*) AS cnt
					FROM {$wpdb->prefix}wc_orders o
					INNER JOIN {$wpdb->prefix}wc_orders_meta m ON m.order_id = o.id
					WHERE o.type = 'shop_order' AND m.meta_key = %s
					GROUP BY o.status",
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
					WHERE p.post_type = 'shop_order' AND m.meta_key = %s
					GROUP BY p.post_status",
					self::META_KEY
				)
			);
		}

		$counts = [];
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row->status ] = (int) $row->cnt;
		}

		$cache[ $backend ] = $counts;
		return $counts;
	}
}
