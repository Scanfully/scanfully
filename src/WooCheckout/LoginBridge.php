<?php
/**
 * Login bridge — sets the auth cookie for the probe user on shops that
 * disable guest checkout.
 *
 * Security model: the bridge endpoint is HMAC-gated. It only authenticates
 * the request to a single locked-down `scanfully_probe` customer account
 * if (a) the X-Scanfully-Probe header verifies against the stored secret,
 * and (b) the bridge is hit via the dedicated `?scanfully_probe_login=1`
 * query string. Without a valid HMAC, the bridge is a no-op.
 *
 * @package Scanfully
 */

namespace Scanfully\WooCheckout;

/**
 * Login bridge.
 */
class LoginBridge {

	public const USER_LOGIN     = 'scanfully_probe';
	public const OPTION_USER_ID = 'scanfully_woocheckout_probe_user_id';

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function setup(): void {
		// Lazy provisioning: when guest checkout flips OFF, ensure the probe
		// user exists so the next scan can succeed.
		add_action(
			'update_option_woocommerce_enable_guest_checkout',
			[ self::class, 'maybe_provision_on_option_change' ],
			10,
			2
		);

		// Bridge endpoint. Fires very early so we can issue the auth cookie
		// and redirect before WP renders anything.
		add_action( 'init', [ self::class, 'handle_bridge_request' ], 5 );
	}

	/**
	 * Ensure the probe user exists when guest checkout has just been
	 * disabled.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 *
	 * @return void
	 */
	public static function maybe_provision_on_option_change( $old_value, $new_value ): void {
		unset( $old_value );
		if ( 'no' === (string) $new_value ) {
			self::get_or_create_probe_user();
		}
	}

	/**
	 * Bridge endpoint handler. Validates the probe HMAC, sets the auth
	 * cookie for the probe user and redirects to the product URL.
	 *
	 * @return void
	 */
	public static function handle_bridge_request(): void {
		if ( ! isset( $_GET['scanfully_probe_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! Controller::is_probe_request() ) {
			// Hard 404 so it's indistinguishable from an unknown URL.
			status_header( 404 );
			nocache_headers();
			exit;
		}

		$user_id = self::get_or_create_probe_user();
		if ( $user_id <= 0 ) {
			status_header( 500 );
			nocache_headers();
			exit;
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, false, is_ssl() );

		$next = self::resolve_redirect_target();

		nocache_headers();
		header( 'Cache-Control: no-store, max-age=0' );
		wp_safe_redirect( $next, 302 );
		exit;
	}

	/**
	 * Determine the redirect target after a successful login. Defaults to
	 * the shop home; honours `?next=` only when same-host.
	 *
	 * @return string
	 */
	private static function resolve_redirect_target(): string {
		$default = home_url( '/' );
		if ( ! isset( $_GET['next'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $default;
		}
		$candidate = (string) wp_unslash( $_GET['next'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $candidate ) {
			return $default;
		}
		$home_host      = (string) wp_parse_url( $default, PHP_URL_HOST );
		$candidate_host = (string) wp_parse_url( $candidate, PHP_URL_HOST );
		if ( '' === $candidate_host || $candidate_host === $home_host ) {
			return $candidate;
		}
		return $default;
	}

	/**
	 * Return the probe user id, creating it if missing.
	 *
	 * @return int 0 on failure.
	 */
	public static function get_or_create_probe_user(): int {
		$stored_id = (int) get_option( self::OPTION_USER_ID, 0 );
		if ( $stored_id > 0 && get_userdata( $stored_id ) ) {
			return $stored_id;
		}

		$existing = get_user_by( 'login', self::USER_LOGIN );
		if ( $existing instanceof \WP_User ) {
			update_option( self::OPTION_USER_ID, (int) $existing->ID, false );
			return (int) $existing->ID;
		}

		$password = wp_generate_password( 32, true, true );
		$email    = sprintf( 'probe+%s@scanfully.invalid', wp_generate_password( 6, false, false ) );

		$user_id = wp_insert_user(
			[
				'user_login' => self::USER_LOGIN,
				'user_pass'  => $password,
				'user_email' => $email,
				'role'       => 'customer',
				'display_name' => 'Scanfully Probe',
				'first_name'   => 'Scanfully',
				'last_name'    => 'Probe',
			]
		);

		if ( is_wp_error( $user_id ) ) {
			return 0;
		}

		update_option( self::OPTION_USER_ID, (int) $user_id, false );
		return (int) $user_id;
	}
}
