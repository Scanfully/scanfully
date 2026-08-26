<?php
/**
 * Probe ping — pre-flight challenge-response endpoint the API calls before
 * launching a checkout scan. Proves the plugin is active on this shop with
 * the probe secret the API holds, so scans can never target a shop that is
 * not connected to the account.
 *
 * @package Scanfully
 */

namespace Scanfully\WooCheckout;

/**
 * Probe ping responder.
 */
class ProbePing {

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
	 * Answer the ping when requested with a valid probe header.
	 *
	 * Response pong = HMAC-SHA256( scan_id . ':' . nonce, probe_secret ).
	 * The ':' separator keeps ping signatures distinct from probe header
	 * signatures. Invalid or missing headers get a 404 so the endpoint is
	 * not advertised.
	 *
	 * @return void
	 */
	public static function maybe_render(): void {
		if ( ! isset( $_GET['scanfully_probe_ping'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$secret = (string) get_option( Controller::OPTION_PROBE_SECRET, '' );
		if ( '' === $scan_id || '' === $nonce || '' === $secret ) {
			status_header( 404 );
			exit;
		}

		wp_send_json(
			[
				'pong'           => hash_hmac( 'sha256', $scan_id . ':' . $nonce, $secret ),
				'plugin_version' => defined( 'SCANFULLY_VERSION' ) ? SCANFULLY_VERSION : '',
				'wc_version'     => Controller::wc_version(),
			]
		);
	}
}
