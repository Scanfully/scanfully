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

	public const META_KEY    = '_scanfully_probe_order';
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
	}

	/**
	 * Whether probe orders should be shown (toggled via query param).
	 *
	 * @return bool
	 */
	private static function should_show_probe_orders(): bool {
		return isset( $_GET[ self::TOGGLE_PARAM ] ) && '1' === (string) $_GET[ self::TOGGLE_PARAM ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
		$meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : [];
		$meta_query[] = [
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
}
