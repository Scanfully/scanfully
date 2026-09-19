<?php
/**
 * WooCheckout probe order integration tests.
 *
 * Runs against real WooCommerce orders and stock (wp-env with WooCommerce)
 * and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Scanfully\WooCheckout\Cleanup;
use Scanfully\WooCheckout\Controller;
use Scanfully\WooCheckout\ProbeGateway;

/**
 * Verifies probe orders are cancelled, release stock and are cleaned up safely.
 */
final class WooCheckoutOrderTest extends TestCase {

	/**
	 * Orders and products created by a test.
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $created = [
		'orders'   => [],
		'products' => [],
	];

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wc_create_order' ) ) {
			$this->markTestSkipped( 'WooCommerce not available. Run this suite under wp-env.' );
		}
		update_option( 'woocommerce_manage_stock', 'yes' );
		update_option( 'woocommerce_hold_stock_minutes', '60' );
	}

	protected function tearDown(): void {
		if ( function_exists( 'wc_get_order' ) ) {
			foreach ( $this->created['orders'] as $id ) {
				$order = wc_get_order( $id );
				if ( $order ) {
					$order->delete( true );
				}
			}
			foreach ( $this->created['products'] as $id ) {
				wp_delete_post( $id, true );
			}
		}
		$this->set_probe_request( null );
		parent::tearDown();
	}

	/**
	 * Set the memoised probe-request result.
	 *
	 * @param bool|null $is_probe Whether the current request is a probe, or null to reset.
	 */
	private function set_probe_request( ?bool $is_probe ): void {
		$property = new ReflectionProperty( Controller::class, 'probe_request_cache' );
		$property->setAccessible( true );
		$property->setValue( null, $is_probe );
	}

	/**
	 * A simple product with managed stock.
	 *
	 * @param int $stock Stock quantity.
	 *
	 * @return \WC_Product_Simple
	 */
	private function make_product( int $stock ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Scanfully probe test product' );
		$product->set_regular_price( '10' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product->save();
		$this->created['products'][] = $product->get_id();
		return $product;
	}

	/**
	 * An order created a number of days ago.
	 *
	 * @param string $status         Order status.
	 * @param bool   $tagged         Whether it carries the probe tag.
	 * @param string $payment_method Payment method ID.
	 * @param int    $days_old       Age in days.
	 *
	 * @return \WC_Order
	 */
	private function make_order( string $status, bool $tagged, string $payment_method, int $days_old ): \WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( $payment_method );
		if ( $tagged ) {
			$order->update_meta_data( '_scanfully_probe_order', 'true' );
		}
		$order->set_date_created( time() - $days_old * DAY_IN_SECONDS );
		$order->set_status( $status );
		$order->save();
		$this->created['orders'][] = $order->get_id();
		return $order;
	}

	public function test_a_probe_payment_cancels_the_order_and_releases_reserved_stock(): void {
		$product = $this->make_product( 5 );
		$order   = wc_create_order();
		$order->add_product( $product, 2 );
		$order->set_payment_method( Controller::PROBE_GATEWAY_ID );
		$order->calculate_totals();
		$order->save();
		$this->created['orders'][] = $order->get_id();

		wc_reserve_stock_for_order( $order );
		$this->assertSame( 2, (int) wc_get_held_stock_quantity( $product ) );

		$result = ( new ProbeGateway() )->process_payment( $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'cancelled', $order->get_status() );
		$this->assertSame( 'true', $order->get_meta( '_scanfully_probe_order' ) );
		$this->assertSame( 0, (int) wc_get_held_stock_quantity( $product ) );
		$this->assertSame( 5, wc_get_product( $product->get_id() )->get_stock_quantity(), 'Cancelling a probe order must not change stock.' );
	}

	public function test_orders_created_during_a_probe_are_tagged_and_others_are_not(): void {
		$probe_order = new \WC_Order();
		$this->set_probe_request( true );
		do_action( 'woocommerce_checkout_create_order', $probe_order, [] );
		$this->assertSame( 'true', $probe_order->get_meta( '_scanfully_probe_order' ) );

		$normal_order = new \WC_Order();
		$this->set_probe_request( false );
		do_action( 'woocommerce_checkout_create_order', $normal_order, [] );
		$this->assertSame( '', $normal_order->get_meta( '_scanfully_probe_order' ) );
	}

	public function test_the_order_query_itself_only_returns_probe_orders(): void {
		$probe_order = $this->make_order( 'cancelled', true, Controller::PROBE_GATEWAY_ID, 8 );
		$real_order  = $this->make_order( 'cancelled', false, 'bacs', 8 );

		// Ask WooCommerce directly, without the per-order PHP check, so a meta
		// filter the order store ignores would show up here.
		$ids = wc_get_orders(
			[
				'status'     => [ 'cancelled' ],
				'return'     => 'ids',
				'limit'      => -1,
				'meta_key'   => '_scanfully_probe_order', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'true', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$ids = wc_get_orders(
				[
					'status'     => [ 'cancelled' ],
					'return'     => 'ids',
					'limit'      => -1,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'meta_query' => [
						[
							'key'   => '_scanfully_probe_order',
							'value' => 'true',
						],
					],
				]
			);
		}

		$this->assertContains( $probe_order->get_id(), $ids );
		$this->assertNotContains( $real_order->get_id(), $ids );
	}

	public function test_cleanup_cancels_stale_pending_orders_and_deletes_only_old_cancelled_probe_orders(): void {
		$probe                = Controller::PROBE_GATEWAY_ID;
		$stale_pending        = $this->make_order( 'pending', true, $probe, 2 );
		$old_cancelled        = $this->make_order( 'cancelled', true, $probe, 8 );
		$recent_cancelled     = $this->make_order( 'cancelled', true, $probe, 2 );
		$real_cancelled       = $this->make_order( 'cancelled', false, 'bacs', 8 );
		$tagged_other_gateway = $this->make_order( 'cancelled', true, 'bacs', 8 );

		Cleanup::run();

		$this->assertSame( 'cancelled', wc_get_order( $stale_pending->get_id() )->get_status() );
		$this->assertFalse( wc_get_order( $old_cancelled->get_id() ) );
		$this->assertInstanceOf( \WC_Order::class, wc_get_order( $recent_cancelled->get_id() ) );
		$this->assertInstanceOf( \WC_Order::class, wc_get_order( $real_cancelled->get_id() ), 'A real order must never be deleted.' );
		$this->assertInstanceOf( \WC_Order::class, wc_get_order( $tagged_other_gateway->get_id() ), 'Only orders paid with the probe gateway may be deleted.' );
	}
}
