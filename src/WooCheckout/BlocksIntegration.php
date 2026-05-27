<?php
/**
 * WooCommerce Blocks (Cart/Checkout) integration for the Scanfully probe
 * gateway. Registers a minimal payment-method block so the gateway is
 * selectable in block-based checkouts in addition to the classic shortcode
 * checkout.
 *
 * @package Scanfully
 */

namespace Scanfully\WooCheckout;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers the probe gateway with WooCommerce Blocks checkout.
 */
class BlocksIntegration extends AbstractPaymentMethodType {

	/**
	 * Payment method name (matches the gateway id).
	 *
	 * @var string
	 */
	protected $name = Controller::PROBE_GATEWAY_ID;

	/**
	 * Boot hook into the blocks registry.
	 *
	 * @return void
	 */
	public static function setup(): void {
		error_log( '[scanfully] BlocksIntegration::setup() uri=' . ( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '?' ) . ' has_header=' . ( isset( $_SERVER['HTTP_X_SCANFULLY_PROBE'] ) ? '1' : '0' ) );
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				error_log( '[scanfully] registration hook uri=' . ( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '?' ) . ' has_header=' . ( isset( $_SERVER['HTTP_X_SCANFULLY_PROBE'] ) ? '1' : '0' ) . ' is_probe=' . ( Controller::is_probe_request() ? '1' : '0' ) );
				if ( method_exists( $registry, 'register' ) ) {
					$registry->register( new self() );
				}
			}
		);
	}

	/**
	 * No-op initialise (no settings to load).
	 *
	 * @return void
	 */
	public function initialize(): void {
		// Nothing to initialise.
	}

	/**
	 * Only available during a validated probe request.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		$active = Controller::is_probe_request();
		error_log( '[scanfully] BlocksIntegration::is_active() uri=' . ( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '?' ) . ' has_header=' . ( isset( $_SERVER['HTTP_X_SCANFULLY_PROBE'] ) ? '1' : '0' ) . ' active=' . ( $active ? '1' : '0' ) );
		return $active;
	}

	/**
	 * Register and return the JS handle that defines the block payment
	 * method for the probe gateway.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles(): array {
		$handle = 'scanfully-probe-blocks';
		$src    = plugins_url( 'assets/js/probe-blocks.js', SCANFULLY_PLUGIN_FILE );
		$path   = dirname( SCANFULLY_PLUGIN_FILE ) . '/assets/js/probe-blocks.js';
		$ver    = file_exists( $path ) ? (string) filemtime( $path ) : '1';

		wp_register_script(
			$handle,
			$src,
			[ 'wc-blocks-registry', 'wp-element', 'wp-html-entities' ],
			$ver,
			true
		);

		return [ $handle ];
	}

	/**
	 * Data made available to the JS payment-method block.
	 *
	 * @return array
	 */
	public function get_payment_method_data(): array {
		return [
			'title'       => __( 'Scanfully Probe (test order)', 'scanfully' ),
			'description' => __( 'Internal gateway used by Scanfully to verify checkout health.', 'scanfully' ),
			'supports'    => [ 'products' ],
		];
	}
}
