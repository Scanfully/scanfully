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
 * The probe account is only used while it is still a plain customer the
 * plugin created. Every bridge login resets its email, password, sessions and
 * application passwords, and the account can't reset its password, create
 * application passwords or edit its account details, so one leaked login
 * can't be turned into a lasting foothold.
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
	 * Email domain of the probe account. `.invalid` is reserved and never
	 * delivers mail.
	 */
	private const EMAIL_DOMAIN = '@scanfully.invalid';

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

		// Keep the probe account from being taken over after a login.
		add_filter( 'wp_is_application_passwords_available_for_user', [ self::class, 'filter_allow_for_non_probe_user' ], 10, 2 );
		add_filter( 'allow_password_reset', [ self::class, 'filter_allow_for_non_probe_user' ], 10, 2 );
		add_action( 'woocommerce_save_account_details_errors', [ self::class, 'block_account_details_change' ], 10, 2 );
	}

	/**
	 * Filter callback: deny a capability-style check for the probe user and
	 * leave it unchanged for everyone else. Used for application passwords
	 * and password resets.
	 *
	 * @param bool         $allow Whether the action is allowed.
	 * @param \WP_User|int $user  The user, or a user ID.
	 *
	 * @return bool
	 */
	public static function filter_allow_for_non_probe_user( $allow, $user ): bool {
		$user_id = $user instanceof \WP_User ? (int) $user->ID : (int) $user;

		return self::is_probe_user_id( $user_id ) ? false : (bool) $allow;
	}

	/**
	 * Action callback: refuse account detail changes (email, name, password)
	 * for the probe user in WooCommerce's My Account.
	 *
	 * @param \WP_Error $errors Validation errors.
	 * @param object    $user   The user being saved.
	 *
	 * @return void
	 */
	public static function block_account_details_change( $errors, $user ): void {
		if ( $errors instanceof \WP_Error && is_object( $user ) && isset( $user->ID ) && self::is_probe_user_id( (int) $user->ID ) ) {
			$errors->add( 'scanfully_probe_locked', __( 'This account is managed by Scanfully and cannot be changed.', 'scanfully' ) );
		}
	}

	/**
	 * Whether a user ID is the stored probe account.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return bool
	 */
	private static function is_probe_user_id( int $user_id ): bool {
		return $user_id > 0 && (int) get_option( self::OPTION_USER_ID, 0 ) === $user_id;
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
		if ( $user_id <= 0 || ! self::lock_down_probe_user( $user_id ) ) {
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
		$candidate = (string) wp_unslash( $_GET['next'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated against the home host below and passed to wp_safe_redirect(); sanitize_url() would rewrite relative targets.
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
	 * An existing account is only used while it is still a plain customer
	 * with a probe login (see is_safe_probe_user()). Otherwise it is left
	 * alone, since it may belong to a real person, and a new probe account
	 * with a unique login is created instead.
	 *
	 * @return int 0 on failure.
	 */
	public static function get_or_create_probe_user(): int {
		$stored_id = (int) get_option( self::OPTION_USER_ID, 0 );
		if ( $stored_id > 0 && self::is_safe_probe_user( $stored_id ) ) {
			return $stored_id;
		}

		$login    = self::USER_LOGIN;
		$existing = get_user_by( 'login', $login );
		if ( $existing instanceof \WP_User ) {
			if ( self::is_safe_probe_user( (int) $existing->ID ) ) {
				update_option( self::OPTION_USER_ID, (int) $existing->ID, false );
				return (int) $existing->ID;
			}
			$login = self::USER_LOGIN . '_' . strtolower( wp_generate_password( 8, false, false ) );
		}

		$password = wp_generate_password( 32, true, true );

		$user_id = wp_insert_user(
			[
				'user_login' => $login,
				'user_pass'  => $password,
				'user_email' => self::generate_probe_email(),
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

	/**
	 * Whether a user can safely be used as the probe account: a probe login
	 * (`scanfully_probe` or `scanfully_probe_…`), only the customer role, and
	 * no editing or shop management capabilities.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return bool
	 */
	private static function is_safe_probe_user( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		$has_probe_login = self::USER_LOGIN === $user->user_login
			|| 0 === strpos( $user->user_login, self::USER_LOGIN . '_' );

		return $has_probe_login
			&& [ 'customer' ] === array_values( $user->roles )
			&& ! user_can( $user, 'edit_posts' )
			&& ! user_can( $user, 'manage_woocommerce' );
	}

	/**
	 * Undo anything done with the probe account since the last login: reset
	 * its email and password, end its sessions and remove application
	 * passwords.
	 *
	 * @param int $user_id The probe user ID.
	 *
	 * @return bool False when the account could not be reset.
	 */
	private static function lock_down_probe_user( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		if ( self::EMAIL_DOMAIN !== substr( $user->user_email, -strlen( self::EMAIL_DOMAIN ) ) ) {
			$updated = wp_update_user(
				[
					'ID'         => $user_id,
					'user_email' => self::generate_probe_email(),
				]
			);
			if ( is_wp_error( $updated ) ) {
				return false;
			}
		}

		wp_set_password( wp_generate_password( 32, true, true ), $user_id );
		\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		if ( class_exists( '\WP_Application_Passwords' ) ) {
			\WP_Application_Passwords::delete_all_application_passwords( $user_id );
		}

		return true;
	}

	/**
	 * A fresh, undeliverable email address for the probe account.
	 *
	 * @return string
	 */
	private static function generate_probe_email(): string {
		return sprintf( 'probe+%s%s', wp_generate_password( 6, false, false ), self::EMAIL_DOMAIN );
	}
}
