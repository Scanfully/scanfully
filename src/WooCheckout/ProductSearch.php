<?php
/**
 * Product search — HMAC-secured endpoint the API calls to list probe-eligible
 * products for the dashboard product select. Follows the ProbePing pattern:
 * template_redirect at priority 0, 404 on anything but a valid probe request.
 *
 * @package Scanfully
 */

namespace Scanfully\WooCheckout;

/**
 * Product search responder.
 */
class ProductSearch {

	/**
	 * Maximum number of products returned per search.
	 */
	public const MAX_RESULTS = 20;

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function setup(): void {
		// Priority 0: answer before themes/other plugins can interfere.
		add_action( 'template_redirect', [ self::class, 'maybe_render' ], 0 );
	}

	/**
	 * Answer the product search when requested with a valid probe header.
	 *
	 * Response pong = HMAC-SHA256( scan_id . ':' . nonce . ':products',
	 * probe_secret ). The ':products' suffix keeps these signatures distinct
	 * from ping signatures. Invalid or missing headers get a 404 so the
	 * endpoint is not advertised.
	 *
	 * @return void
	 */
	public static function maybe_render(): void {
		if ( ! isset( $_GET['scanfully_probe_products'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		nocache_headers();
		header( 'Cache-Control: no-store, max-age=0' );
		header( 'X-Robots-Tag: noindex' );

		if ( ! Controller::is_probe_request() ) {
			status_header( 404 );
			exit;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated via the HMAC probe header.
		$scan_id = isset( $_GET['scanfully_scan_id'] ) ? sanitize_text_field( wp_unslash( $_GET['scanfully_scan_id'] ) ) : '';
		$nonce   = isset( $_GET['scanfully_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['scanfully_nonce'] ) ) : '';
		$term    = isset( $_GET['scanfully_search'] ) ? sanitize_text_field( wp_unslash( $_GET['scanfully_search'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$secret = (string) get_option( Controller::OPTION_PROBE_SECRET, '' );
		if ( '' === $scan_id || '' === $nonce || '' === $secret ) {
			status_header( 404 );
			exit;
		}

		$term = substr( $term, 0, 100 );

		wp_send_json(
			[
				'pong'     => hash_hmac( 'sha256', $scan_id . ':' . $nonce . ':products', $secret ),
				'products' => Controller::search_products( $term, self::MAX_RESULTS ),
			]
		);
	}
}
