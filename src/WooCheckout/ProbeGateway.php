<?php
/**
 * Scanfully Probe payment gateway.
 *
 * @package Scanfully
 */

namespace Scanfully\WooCheckout;

/**
 * A test payment gateway that is hidden from normal customers and only
 * exposed to validated Scanfully probe requests. Acts as a stand-in for a
 * real PSP: tags the order with the probe scan id and redirects to the
 * plugin-owned stub PSP URL so the orchestrator's "left /checkout/"
 * termination semantics apply unchanged.
 */
class ProbeGateway extends \WC_Payment_Gateway {

	/**
	 * Constructor. Sets identifiers, registers an order-meta tag and the
	 * (lightweight) emails/stock filters scoped to probe orders.
	 */
	public function __construct() {
		$this->id                 = Controller::PROBE_GATEWAY_ID;
		$this->method_title       = __( 'Scanfully Probe (test order)', 'scanfully' );
		$this->method_description = __( 'Internal gateway used by Scanfully to verify checkout health. Hidden from customers; only enabled for valid probe requests.', 'scanfully' );
		$this->title              = $this->method_title;
		$this->description        = '';
		$this->has_fields         = false;
		// subscriptions / multiple_subscriptions: WooCommerce Subscriptions
		// drops any gateway that does not declare them when the cart has a
		// subscription and manual renewals are disabled. The probe never
		// charges a real PSP; it only needs to stay visible on checkout.
		$this->supports = [ 'products', 'subscriptions', 'multiple_subscriptions' ];

		// Always enabled at the gateway settings level; availability is
		// gated dynamically by ProbeGateway::filter_available_gateways().
		$this->enabled = 'yes';
	}

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_filter( 'woocommerce_payment_gateways', [ self::class, 'register_gateway' ] );
		add_filter( 'woocommerce_available_payment_gateways', [ self::class, 'filter_available_gateways' ], 99 );

		// Probe-only filters: suppress emails + prevent stock decrement.
		add_filter( 'woocommerce_email_recipient_new_order', [ self::class, 'suppress_email_for_probe' ], 10, 2 );
		add_filter( 'woocommerce_email_recipient_customer_processing_order', [ self::class, 'suppress_email_for_probe' ], 10, 2 );
		add_filter( 'woocommerce_email_recipient_customer_on_hold_order', [ self::class, 'suppress_email_for_probe' ], 10, 2 );
		add_filter( 'woocommerce_can_reduce_order_stock', [ self::class, 'skip_stock_reduction_for_probe' ], 10, 2 );

		// Tag probe orders as soon as they are created (classic and block
		// checkout), so they can be found even if payment never runs.
		add_action( 'woocommerce_checkout_create_order', [ self::class, 'tag_new_probe_order' ] );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', [ self::class, 'tag_new_probe_order' ] );
	}

	/**
	 * Tag an order created during a validated probe request.
	 *
	 * @param \WC_Order $order The order being created.
	 *
	 * @return void
	 */
	public static function tag_new_probe_order( $order ): void {
		if ( ! $order instanceof \WC_Order || ! Controller::is_probe_request() ) {
			return;
		}
		self::tag_probe_order( $order );
		if ( $order->get_id() > 0 ) {
			$order->save_meta_data();
		}
	}

	/**
	 * Mark an order as a probe order and record the scan it belongs to.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return void
	 */
	private static function tag_probe_order( \WC_Order $order ): void {
		$order->update_meta_data( AdminFilter::META_KEY, 'true' );
		$scan_id = Controller::current_scan_id();
		if ( '' !== $scan_id ) {
			$order->update_meta_data( '_scanfully_scan_id', $scan_id );
		}
	}

	/**
	 * Register the gateway with WooCommerce. During a validated probe
	 * request we replace the entire gateway list with just our probe
	 * gateway, so foreign gateways (Stripe, PayPal, etc.) never load their
	 * scripts/iframes — they would otherwise leave the block checkout's
	 * Express Checkout and Payment Options blocks stuck in their skeleton
	 * loading state and prevent our radio from appearing.
	 *
	 * @param array $gateways Existing gateway classes.
	 *
	 * @return array
	 */
	public static function register_gateway( array $gateways ): array {
		if ( Controller::is_probe_request() ) {
			return [ self::class ];
		}
		$gateways[] = self::class;
		return $gateways;
	}

	/**
	 * Hide the probe gateway from any non-probe context. On a probe request,
	 * put it back as the only available gateway: WooCommerce Subscriptions
	 * (and similar plugins) may have emptied the list after we registered.
	 *
	 * @param array $gateways Gateways indexed by id.
	 *
	 * @return array
	 */
	public static function filter_available_gateways( array $gateways ): array {
		if ( Controller::is_probe_request() ) {
			return self::probe_only_gateways( $gateways );
		}
		if ( isset( $gateways[ Controller::PROBE_GATEWAY_ID ] ) ) {
			unset( $gateways[ Controller::PROBE_GATEWAY_ID ] );
		}
		return $gateways;
	}

	/**
	 * Restrict the available list to the probe gateway instance.
	 *
	 * @param array $gateways Gateways indexed by id.
	 *
	 * @return array
	 */
	private static function probe_only_gateways( array $gateways ): array {
		$id = Controller::PROBE_GATEWAY_ID;
		if ( isset( $gateways[ $id ] ) ) {
			return [ $id => $gateways[ $id ] ];
		}
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$all = WC()->payment_gateways()->payment_gateways();
			if ( isset( $all[ $id ] ) ) {
				return [ $id => $all[ $id ] ];
			}
		}
		return $gateways;
	}

	/**
	 * Process the probe payment: tag the order, cancel it, redirect to the
	 * plugin-owned stub PSP URL.
	 *
	 * The scan ends at the stub PSP, so the order is cancelled right away.
	 * Cancelling releases the stock WooCommerce reserved at checkout, and
	 * pending -> cancelled sends no email.
	 *
	 * @param int $order_id Order id.
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return [
				'result'   => 'failure',
				'messages' => __( 'Probe order missing.', 'scanfully' ),
			];
		}

		self::tag_probe_order( $order );
		$order->set_status( 'cancelled', __( 'Scanfully probe order. Cancelled automatically at the end of the checkout check.', 'scanfully' ) );
		$order->save();

		$redirect = add_query_arg(
			[
				'scanfully_probe_psp' => '1',
				'order'               => $order_id,
			],
			home_url( '/' )
		);

		return [
			'result'   => 'success',
			'redirect' => $redirect,
		];
	}

	/**
	 * Suppress WC email recipients for probe orders.
	 *
	 * @param string               $recipient The current recipient list.
	 * @param \WC_Order|false|null $order     The order being processed.
	 *
	 * @return string
	 */
	public static function suppress_email_for_probe( $recipient, $order ) {
		if ( $order instanceof \WC_Order && 'true' === (string) $order->get_meta( '_scanfully_probe_order' ) ) {
			return '';
		}
		return $recipient;
	}

	/**
	 * Prevent probe orders from depleting stock.
	 *
	 * @param bool      $can_reduce Whether stock can be reduced.
	 * @param \WC_Order $order      Order being acted on.
	 *
	 * @return bool
	 */
	public static function skip_stock_reduction_for_probe( $can_reduce, $order ) {
		if ( $order instanceof \WC_Order && 'true' === (string) $order->get_meta( '_scanfully_probe_order' ) ) {
			return false;
		}
		return $can_reduce;
	}
}
