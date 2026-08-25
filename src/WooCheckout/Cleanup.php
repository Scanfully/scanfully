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
 */
class Cleanup {

	/**
	 * Days a probe order is kept before it is deleted. Filterable via
	 * `scanfully_woocheckout_probe_order_retention_days`.
	 */
	public const RETENTION_DAYS = 7;

	/**
	 * Orders deleted per query batch.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Max batches per run, to keep a single cron run bounded.
	 */
	private const MAX_BATCHES = 10;

	/**
	 * Delete probe orders older than the retention period.
	 * Runs on the recurring daily schedule.
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$retention_days = (int) apply_filters( 'scanfully_woocheckout_probe_order_retention_days', self::RETENTION_DAYS );
		if ( $retention_days < 1 ) {
			$retention_days = 1;
		}
		$cutoff = time() - ( $retention_days * DAY_IN_SECONDS );

		for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			$orders = wc_get_orders(
				[
					'limit'        => self::BATCH_SIZE,
					'date_created' => '<' . $cutoff,
					// Probe orders are created pending and never paid; WC may
					// auto-cancel them. Restricting to these statuses is a
					// safety net should the meta query ever misbehave.
					'status'       => [ 'pending', 'cancelled' ],
					'return'       => 'objects',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'meta_query'   => [
						[
							'key'   => AdminFilter::META_KEY,
							'value' => 'true',
						],
					],
				]
			);

			if ( ! is_array( $orders ) || 0 === count( $orders ) ) {
				break;
			}

			foreach ( $orders as $order ) {
				if ( $order instanceof \WC_Order ) {
					$order->delete( true );
				}
			}

			if ( count( $orders ) < self::BATCH_SIZE ) {
				break;
			}
		}
	}
}
