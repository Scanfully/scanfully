<?php
/**
 * Stub PSP — plugin-owned page the probe gateway redirects to after
 * "processing" a payment. The orchestrator sees the URL leave /checkout/
 * and treats this as the PSP redirect, mirroring the real-PSP termination
 * semantics in the checkout scenario.
 *
 * @package Scanfully
 */

namespace Scanfully\WooCheckout;

/**
 * Stub PSP responder.
 */
class StubPSP {

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_action( 'template_redirect', [ self::class, 'maybe_render' ] );
	}

	/**
	 * Render the stub page when requested.
	 *
	 * @return void
	 */
	public static function maybe_render(): void {
		if ( ! isset( $_GET['scanfully_probe_psp'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		nocache_headers();
		header( 'Cache-Control: no-store, max-age=0' );
		header( 'X-Robots-Tag: noindex' );

		$order = isset( $_GET['order'] ) ? (int) $_GET['order'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Minimal HTML so the orchestrator's scenario can match on the URL,
		// not the content; we keep a stable marker for human debugging.
		echo "<!doctype html><html><head><meta charset='utf-8'><title>Scanfully Probe PSP</title></head><body>";
		echo "<h1>Scanfully Probe PSP</h1>";
		echo '<p data-scanfully-probe-psp="1" data-order="' . esc_attr( (string) $order ) . '">Probe order ' . esc_html( (string) $order ) . " accepted.</p>";
		echo '</body></html>';
		exit;
	}
}
