<?php
/**
 * Probe order cleanup — permanently deletes Scanfully probe orders after a
 * retention period so test data does not accumulate in the shop.
 *
 * @package Scanfully
 */

namespace Scanfully\WooCheckout;

/**
 * Delete old probe orders (orders tagged with the probe meta key).
 *
 * Probe orders are cancelled by the probe gateway as soon as the checkout
 * check ends, so the cleanup only ever deletes cancelled orders. A probe
 * order left pending (for example because the scan broke before payment) is
 * cancelled first, after a grace period, and deleted on a later run.
 */
class Cleanup {

	/**
	 * Days a probe order is kept before it is deleted. Filterable via
	 * `scanfully_woocheckout_probe_order_retention_days`.
	 */
	public const RETENTION_DAYS = 7;

	/**
	 * Seconds a probe order may stay pending before the cleanup cancels it.
	 */
	private const PENDING_GRACE_PERIOD = DAY_IN_SECONDS;

	/**
	 * Orders handled per query batch.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Max batches per run and phase, to keep a single cron run bounded.
	 */
	private const MAX_BATCHES = 10;

	/**
	 * Cancel stale pending probe orders, then delete cancelled probe orders
	 * older than the retention period. Runs on the recurring daily schedule.
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		self::cancel_stale_pending_orders();
		self::delete_old_cancelled_orders();
	}

	/**
	 * Cancel probe orders that are still pending after the grace period.
	 * This only changes their status; deleting happens on a later run.
	 *
	 * @return void
	 */
	private static function cancel_stale_pending_orders(): void {
		$cutoff = time() - self::PENDING_GRACE_PERIOD;

		for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			$orders = self::find_probe_orders( 'pending', $cutoff );

			foreach ( $orders as $order ) {
				$order->update_status( 'cancelled', __( 'Scanfully probe order. Cancelled by the probe order cleanup.', 'scanfully' ) );
			}

			if ( count( $orders ) < self::BATCH_SIZE ) {
				break;
			}
		}
	}

	/**
	 * Permanently delete cancelled probe orders older than the retention
	 * period.
	 *
	 * @return void
	 */
	private static function delete_old_cancelled_orders(): void {
		$retention_days = (int) apply_filters( 'scanfully_woocheckout_probe_order_retention_days', self::RETENTION_DAYS );
		if ( $retention_days < 1 ) {
			$retention_days = 1;
		}
		$cutoff = time() - ( $retention_days * DAY_IN_SECONDS );

		for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			$orders = self::find_probe_orders( 'cancelled', $cutoff );

			foreach ( $orders as $order ) {
				$order->delete( true );
			}

			if ( count( $orders ) < self::BATCH_SIZE ) {
				break;
			}
		}
	}

	/**
	 * Find probe orders with a status, created before a cutoff.
	 *
	 * Every result is checked again in PHP (see is_probe_order()), so a meta
	 * filter that is ignored, for example by a custom order data store, can
	 * never hand a real order to the caller.
	 *
	 * @param string $status Order status without the `wc-` prefix.
	 * @param int    $cutoff Only orders created before this Unix timestamp.
	 *
	 * @return \WC_Order[]
	 */
	private static function find_probe_orders( string $status, int $cutoff ): array {
		$args = [
			'limit'        => self::BATCH_SIZE,
			'date_created' => '<' . $cutoff,
			'status'       => [ $status ],
			'return'       => 'objects',
		];

		// The legacy (posts) order store ignores `meta_query` and would return
		// every order with the status, so each store gets the form it supports.
		if ( self::uses_hpos() ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = [
				[
					'key'   => AdminFilter::META_KEY,
					'value' => 'true',
				],
			];
		} else {
			$args['meta_key']   = AdminFilter::META_KEY; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['meta_value'] = 'true'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}

		$orders = wc_get_orders( $args );

		if ( ! is_array( $orders ) ) {
			return [];
		}

		return array_values( array_filter( $orders, [ self::class, 'is_probe_order' ] ) );
	}

	/**
	 * Whether WooCommerce stores orders in its own tables (HPOS).
	 *
	 * @return bool
	 */
	private static function uses_hpos(): bool {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Whether an order is really a probe order: tagged by the plugin and paid
	 * with the probe gateway.
	 *
	 * @param mixed $order The order.
	 *
	 * @return bool
	 */
	private static function is_probe_order( $order ): bool {
		return $order instanceof \WC_Order
			&& 'true' === (string) $order->get_meta( AdminFilter::META_KEY )
			&& Controller::PROBE_GATEWAY_ID === $order->get_payment_method();
	}
}
