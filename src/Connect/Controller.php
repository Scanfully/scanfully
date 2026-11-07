<?php
/**
 * The connect controller class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Connect;

use Scanfully\Main;
use Scanfully\Options\Controller as OptionsController;
use Scanfully\Options\Options;

/**
 * Handles the flow for connecting the site to, and disconnecting it from, Scanfully.
 */
class Controller {

	public const DATE_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Set up the connect controller
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_action( 'admin_init', [ self::class, 'catch_connect_requests' ] );
	}

	/**
	 * Check if the user has access to the connect process.
	 *
	 * @return bool
	 */
	private static function user_has_access(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Route connect, disconnect and reconnect requests to their handlers.
	 *
	 * @return void
	 */
	public static function catch_connect_requests(): void {

		// handle start connect request.
		if ( isset( $_GET['scanfully-connect'] ) ) {
			self::handle_connect_start();
		}

		// handle start disconnect request.
		if ( isset( $_GET['scanfully-disconnect'] ) ) {
			self::handle_disconnect_start();
		}

		// handle reconnect request (disconnect + start new connect).
		if ( isset( $_GET['scanfully-reconnect'] ) ) {
			self::handle_reconnect();
		}

		// a new connection request that keeps the current one until approved.
		if ( isset( $_GET['scanfully-change-access'] ) ) {
			self::handle_change_access();
		}

		// the OAuth server's answer to a connection request.
		if ( isset( $_GET[ OAuth::CALLBACK_PARAM ] ) ) {
			self::handle_oauth_answer();
		}

		// the answer of the legacy connect page, to a request started before
		// this version.
		if ( isset( $_GET['scanfully-connect-success'] ) ) {
			self::handle_request_connect_success();
		}

		// handle connect error return request.
		if ( isset( $_GET['scanfully-connect-error'] ) ) {
			self::handle_request_connect_error();
		}

		if ( isset( $_GET['scanfully-connect-done'] ) ) {
			// add success message.
			self::print_notice( __( 'Successfully connected to Scanfully', 'scanfully' ), 'success' );
		}
	}

	/**
	 * Catch the request to start the connect process.
	 *
	 * @return void
	 */
	private static function handle_connect_start(): void {
		// check nonce.
		if ( ! isset( $_GET['scanfully-connect-nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['scanfully-connect-nonce'] ) ), 'scanfully-connect-redirect' ) ) {
			wp_die( 'Invalid Scanfully connect nonce' );
		}

		// check permissions.
		if ( ! self::user_has_access() ) {
			wp_die( 'You do not have permission to do this.' );
		}

		// the admin approves on Scanfully's OAuth server what Scanfully may do
		// on this site, and comes back with a code.
		wp_redirect( OAuth::authorize_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Scanfully's own OAuth server, on another host.
		exit;
	}

	/**
	 * Catch the request to start the connect process.
	 *
	 * @return void
	 */
	private static function handle_disconnect_start(): void {
		// check nonce.
		if ( ! isset( $_GET['scanfully-disconnect-nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['scanfully-disconnect-nonce'] ) ), 'scanfully-disconnect-redirect' ) ) {
			wp_die( 'Invalid Scanfully disconnect nonce' );
		}

		// check permissions.
		if ( ! self::user_has_access() ) {
			wp_die( 'You do not have permission to do this.' );
		}

		// make the API forget the tokens before the site does.
		self::revoke_tokens();

		// remove all options / settings.
		OptionsController::clear();

		// redirect to base connect page.
		wp_redirect( Page::get_page_url() );
		exit;
	}

	/**
	 * Handle a request to change what Scanfully may do: a new connection
	 * request, while the current connection keeps working. Scanfully ends it
	 * when the admin approves the new one; a cancel leaves it as it was.
	 *
	 * @return void
	 */
	private static function handle_change_access(): void {
		if ( ! isset( $_GET['scanfully-change-access-nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['scanfully-change-access-nonce'] ) ), 'scanfully-change-access' ) ) {
			wp_die( 'Invalid Scanfully nonce' );
		}
		if ( ! self::user_has_access() ) {
			wp_die( 'You do not have permission to do this.' );
		}

		wp_redirect( OAuth::authorize_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Scanfully's own OAuth server, on another host.
		exit;
	}

	/**
	 * Handle a reconnect request: disconnect the current account and immediately start a new connect flow.
	 *
	 * @return void
	 */
	private static function handle_reconnect(): void {
		// check nonce.
		if ( ! isset( $_GET['scanfully-reconnect-nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['scanfully-reconnect-nonce'] ) ), 'scanfully-reconnect' ) ) {
			wp_die( 'Invalid Scanfully reconnect nonce' );
		}

		// check permissions.
		if ( ! self::user_has_access() ) {
			wp_die( 'You do not have permission to do this.' );
		}

		// revoke and clear the existing connection.
		self::revoke_tokens();
		OptionsController::clear();

		// straight into a new connection, where the admin can choose again
		// what Scanfully may do.
		wp_redirect( OAuth::authorize_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Scanfully's own OAuth server, on another host.
		exit;
	}

	/**
	 * Handle the OAuth server's answer: an error (the admin cancelled), or a
	 * code to exchange for the site's tokens.
	 *
	 * @return void
	 */
	private static function handle_oauth_answer(): void {
		if ( ! self::user_has_access() ) {
			wp_die( 'You do not have permission to do this.' );
		}

		// the state is single use: deleted before comparing, and an empty
		// value never matches. It also ends a cancelled request.
		$expected_state = self::get_state();
		$given_state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		self::delete_state();
		$verifier = self::get_code_verifier();
		self::delete_code_verifier();

		if ( isset( $_GET['error'] ) ) {
			$error = sanitize_key( wp_unslash( $_GET['error'] ) );
			self::print_notice( 'access_denied' === $error ? __( 'The connection was cancelled.', 'scanfully' ) : __( 'An unknown error occurred.', 'scanfully' ), 'error' );
			return;
		}
		if ( '' === $expected_state || '' === $given_state || ! hash_equals( $expected_state, $given_state ) ) {
			wp_die( 'Invalid Scanfully connect state' );
		}
		// the answer names who issued it (RFC 9207): Scanfully's own server only.
		if ( isset( $_GET['iss'] ) && untrailingslashit( esc_url_raw( wp_unslash( $_GET['iss'] ) ) ) !== untrailingslashit( Main::get_oauth_url() ) ) {
			wp_die( 'Invalid Scanfully connect issuer' );
		}
		if ( ! isset( $_GET['code'] ) || '' === $verifier ) {
			wp_die( 'Invalid Scanfully connect parameters' );
		}

		$tokens = OAuth::exchange_code( sanitize_text_field( wp_unslash( $_GET['code'] ) ), $verifier );
		// the site ID ends up in API URL paths, so only allow plain ID characters.
		if ( empty( $tokens ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}\z/', (string) $tokens['site_id'] ) ) {
			wp_die( 'Could not complete Scanfully connection: invalid response from the Scanfully API. Please try again or contact support.' );
		}

		$now = gmdate( self::DATE_FORMAT );
		OptionsController::set_options(
			new Options( true, $tokens['site_id'], $tokens['access_token'], $tokens['refresh_token'], gmdate( self::DATE_FORMAT, strtotime( $tokens['expires'] ) ), '', $now )
		);
		self::store_grant( $tokens['scopes'] );
		self::schedule_first_syncs();

		wp_redirect( add_query_arg( [ 'scanfully-connect-done' => '1' ], Page::get_page_url() ) );
		exit;
	}

	/**
	 * Remember what Scanfully may do on the site, and that the connection
	 * lives on the OAuth server.
	 *
	 * @param string[] $scopes The granted scopes.
	 *
	 * @return void
	 */
	public static function store_grant( array $scopes ): void {
		OptionsController::set_option( 'scopes', implode( ' ', $scopes ) );
		OptionsController::set_option( 'flow', 'oauth' );

		/**
		 * Fires when the scopes of the site's connection were stored, on
		 * connect and on every token refresh, so what depends on them (the
		 * Scanfully Manage clock) follows.
		 *
		 * @param string[] $scopes The granted scopes.
		 */
		do_action( 'scanfully_grant_stored', $scopes );
	}

	/**
	 * The scopes of the site's connection; for a connection from before this
	 * version, what it always allowed.
	 *
	 * @return string[]
	 */
	public static function granted_scopes(): array {
		$scopes = array_values( array_filter( explode( ' ', OptionsController::get_option( 'scopes' ) ) ) );

		return [] === $scopes ? [ OAuth::SCOPE_READ ] : $scopes;
	}

	/**
	 * Run the first syncs at once, so the dashboard does not wait for the next job.
	 *
	 * @return void
	 */
	private static function schedule_first_syncs(): void {
		as_schedule_single_action( time(), \Scanfully\Cron\Controller::ACTION_SYNC_DIRECTORIES, [], 'scanfully' );
		as_schedule_single_action( time(), \Scanfully\Cron\Controller::ACTION_SYNC_SITE_HEALTH, [], 'scanfully' );
		if ( \Scanfully\WooCheckout\Controller::is_woocommerce_active() ) {
			// fetches the probe secret, which is cleared on disconnect.
			as_schedule_single_action( time(), \Scanfully\Cron\Controller::ACTION_SYNC_WOOCHECKOUT_CONFIG, [], 'scanfully' );
		}
	}

	/**
	 * Catch the request that is returned from the connect process on success.
	 *
	 * @return void
	 */
	private static function handle_request_connect_success(): void {

		// check permissions.
		if ( ! self::user_has_access() ) {
			wp_die( 'You do not have permission to do this.' );
		}

		// check if state matches. The stored state is single use, so it is
		// deleted before comparing, and an empty value never matches.
		$expected_state = self::get_state();
		$given_state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		self::delete_state();
		if ( '' === $expected_state || '' === $given_state || ! hash_equals( $expected_state, $given_state ) ) {
			wp_die( 'Invalid Scanfully connect state' );
		}

		// check if required parameters are set.
		if ( ! isset( $_GET['code'] ) || ! isset( $_GET['site'] ) ) {
			wp_die( 'Invalid Scanfully connect parameters' );
		}

		// get variables.
		$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$site = sanitize_text_field( wp_unslash( $_GET['site'] ) );

		// the PKCE verifier proves to the API that this site started the
		// connect request; like the state, it is single use.
		$verifier = self::get_code_verifier();
		self::delete_code_verifier();

		// the site ID ends up in API URL paths, so only allow plain ID characters.
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}\z/', $site ) ) {
			wp_die( 'Invalid Scanfully connect parameters' );
		}

		// exchange authorization code for access token.
		$tokens = self::exchange_authorization_code( $code, $site, $verifier );

		// validate token response so we fail gracefully if the API is unreachable or returned an error.
		if ( empty( $tokens['access_token'] )
			|| empty( $tokens['refresh_token'] )
			|| empty( $tokens['expires'] )
		) {
			wp_die( 'Could not complete Scanfully connection: invalid response from the Scanfully API. Please try again or contact support.' );
		}

		try {
			$now = new \DateTime();
			$now->setTimezone( new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			wp_die( 'Error setting parsing now date. Please contact support.' );
		}

		try {
			$expires = new \DateTime( $tokens['expires'] );
			$expires->setTimezone( new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			wp_die( 'Error setting parsing expires date. Please contact support.' );
		}

		// format options.
		$options = new Options(
			true,
			$site,
			$tokens['access_token'],
			$tokens['refresh_token'],
			$expires->format( self::DATE_FORMAT ),
			'',
			$now->format( self::DATE_FORMAT )
		);

		// save options.
		OptionsController::set_options( $options );

		// run cron jobs a single time so user doesn't have to wait for the next cron job.
		as_schedule_single_action( time(), \Scanfully\Cron\Controller::ACTION_SYNC_DIRECTORIES, [], 'scanfully' );
		as_schedule_single_action( time(), \Scanfully\Cron\Controller::ACTION_SYNC_SITE_HEALTH, [], 'scanfully' );
		if ( \Scanfully\WooCheckout\Controller::is_woocommerce_active() ) {
			// fetches the probe secret, which is cleared on disconnect.
			as_schedule_single_action( time(), \Scanfully\Cron\Controller::ACTION_SYNC_WOOCHECKOUT_CONFIG, [], 'scanfully' );
		}

		// redirect to base connect page with success message.
		wp_redirect( add_query_arg( [ 'scanfully-connect-done' => '1' ], Page::get_page_url() ) );
		exit;
	}

	/**
	 *  Catch the request that is returned from the connect process on error.
	 *
	 * @return void
	 */
	private static function handle_request_connect_error(): void {
		if ( isset( $_GET['scanfully-connect-error'] ) ) {

			// check permissions.
			if ( ! self::user_has_access() ) {
				wp_die( 'You do not have permission to do this.' );
			}

			$error_message = '';
			switch ( $_GET['scanfully-connect-error'] ) {
				case 'access_denied':
					$error_message = __( 'Access denied', 'scanfully' );
					break;
				default:
					$error_message = __( 'An unknown error occurred.', 'scanfully' );
					break;
			}

			self::print_notice( $error_message, 'error' );
		}
	}

	/**
	 * Print a notice to the connect admin.
	 *
	 * @param  string $message The notice message, unescaped (it is escaped on output).
	 * @param  string $type    The notice type.
	 *
	 * @return void
	 */
	private static function print_notice( string $message, string $type = 'error' ): void {
		add_action(
			'scanfully_connect_notices',
			function () use ( $message, $type ) {
				?>
			<div class="scanfully-connect-notice scanfully-connect-notice-<?php echo esc_attr( $type ); ?> is-dismissible">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
				<?php
			}
		);
	}

	/**
	 * Exchange the authorization code for an access and refresh token.
	 *
	 * @param  string $code The authorization code.
	 * @param  string $site     The Scanfully site ID.
	 * @param  string $verifier The PKCE code verifier of the connect request, or an empty string without one.
	 *
	 * @return array<string, mixed> The token response (access_token, refresh_token, expires), or an empty array on failure.
	 */
	private static function exchange_authorization_code( string $code, string $site, string $verifier ): array {

		$body = [
			'grant_type' => 'authorization_code',
			'code'       => $code,
			'site_id'    => $site,
		];
		if ( '' !== $verifier ) {
			$body['code_verifier'] = $verifier;
		}

		// request arguments for the requests.
		$request_args = [
			'headers'     => [ 'Content-Type' => 'application/json' ],
			'timeout'     => 60,
			'blocking'    => true,
			'httpversion' => '1.0',
			'sslverify'   => Main::get_sslverify(),
			'body'        => wp_json_encode( $body ),
		];

		// later check if post failed and show a notice to admins.
		$resp = wp_remote_post( Main::get_api_url() . '/connect/token', $request_args );

		// check if the request failed.
		if ( is_wp_error( $resp ) ) {
			return [];
		}

		// only a 200 response carries tokens; anything else (including an error
		// page from a proxy in front of the API) is a failure.
		if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return [];
		}

		$body = wp_remote_retrieve_body( $resp );

		if ( empty( $body ) ) {
			return [];
		}

		// return the response, or an empty array when the body is not a JSON object.
		$tokens = json_decode( $body, true );

		return is_array( $tokens ) ? $tokens : [];
	}

	/**
	 * Use the refresh token to get a new access and refresh token
	 *
	 * @param  string $refresh_token   The refresh token.
	 * @param  string $site            The Scanfully site ID.
	 * @param  bool   $legacy_fallback Whether an API without the plugin's
	 *                                 client gets the refresh on the legacy route.
	 *
	 * @return array
	 */
	public static function refresh_access_token( string $refresh_token, string $site, bool $legacy_fallback = true ): array {
		if ( false === get_transient( self::OAUTH_UNAVAILABLE_TRANSIENT ) ) {
			$tokens = OAuth::refresh( $refresh_token, $site );
			if ( ! empty( $tokens ) ) {
				self::store_grant( $tokens['scopes'] );
				return $tokens;
			}
			// a connection that has not moved yet keeps the legacy route when
			// the OAuth server refuses it (the API could not move it yet).
			if ( null !== $tokens && ( 'oauth' === OptionsController::get_option( 'flow' ) || ! $legacy_fallback ) ) {
				return $tokens;
			}
			// an API that does not connect sites on its OAuth server yet: the
			// OAuth server again tomorrow.
			set_transient( self::OAUTH_UNAVAILABLE_TRANSIENT, 1, DAY_IN_SECONDS );
		}
		if ( ! $legacy_fallback ) {
			return [];
		}

		return self::refresh_on_legacy_route( $refresh_token, $site );
	}

	/**
	 * Transient set while the API's OAuth server does not take the plugin's client.
	 */
	public const OAUTH_UNAVAILABLE_TRANSIENT = 'scanfully_oauth_unavailable';

	/**
	 * Refresh the tokens on the legacy connect route, for an API without the
	 * plugin's client on its OAuth server.
	 *
	 * @param  string $refresh_token The refresh token.
	 * @param  string $site          The Scanfully site ID.
	 *
	 * @return array
	 */
	private static function refresh_on_legacy_route( string $refresh_token, string $site ): array {

		// request arguments for the requests.
		$request_args = [
			'headers'     => [ 'Content-Type' => 'application/json' ],
			'timeout'     => 15,
			'blocking'    => true,
			'httpversion' => '1.0',
			'sslverify'   => Main::get_sslverify(),
			'body'        => wp_json_encode(
				[
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
					'site_id'       => $site,
				]
			),
		];

		// later check if post failed and show a notice to admins.
		$resp = wp_remote_post( Main::get_api_url() . '/connect/token', $request_args );

		// check if the request failed.
		if ( is_wp_error( $resp ) ) {
			return [];
		}

		// only a 200 response carries tokens; anything else (including an error
		// page from a proxy in front of the API) is a failure.
		if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return [];
		}

		$body = wp_remote_retrieve_body( $resp );

		if ( empty( $body ) ) {
			return [];
		}

		// return the response, or an empty array when the body is not a JSON object.
		$tokens = json_decode( $body, true );

		return is_array( $tokens ) ? $tokens : [];
	}


	/**
	 * Ask the API to revoke this site's tokens, so they stop working once the
	 * site forgets them. Without this, a copy of the tokens (from a backup or
	 * a database dump) stays valid on the API side until it expires.
	 *
	 * Best effort: a failure never blocks the disconnect, and the timeout is
	 * short so an unreachable API doesn't hold up the admin. It says the
	 * site was disconnected, and by whom, so the site's owner learns why the
	 * plugin stopped reporting.
	 *
	 * @return void
	 */
	public static function revoke_tokens(): void {
		self::tell_connection( 'revoke', [ 'reason' => 'disconnected' ] );
	}

	/**
	 * Tell the API that the plugin is being deactivated, and by whom, so the
	 * site's owner learns why the plugin stopped reporting. Called from the
	 * deactivation hook, before the jobs are cancelled. The tokens stay:
	 * activating the plugin again lets it report with them.
	 *
	 * @return void
	 */
	public static function report_deactivated(): void {
		self::tell_connection( 'deactivated', [] );
	}

	/**
	 * Send a request about the connection itself to the API: the revoke or
	 * the deactivation, with who did it. Best effort with a short timeout:
	 * it never blocks what the user is doing.
	 *
	 * @param string $action The connect route: revoke or deactivated.
	 * @param array  $body   What the request says, besides who did it.
	 *
	 * @return void
	 */
	private static function tell_connection( string $action, array $body ): void {
		$site_id      = OptionsController::get_option( 'site_id' );
		$access_token = OptionsController::get_option( 'access_token' );
		if ( '' === $site_id || '' === $access_token ) {
			return;
		}

		$body['by'] = \Scanfully\Events\Event::safe_display_name( wp_get_current_user() );

		wp_remote_post(
			Main::get_api_url() . '/sites/' . rawurlencode( $site_id ) . '/connect/' . $action,
			[
				'headers'     => [
					'Authorization' => sprintf( 'Bearer %s', $access_token ),
					'Content-Type'  => 'application/json',
				],
				'body'        => wp_json_encode( $body ),
				'timeout'     => 5,
				'blocking'    => true,
				'httpversion' => '1.0',
				'sslverify'   => Main::get_sslverify(),
			]
		);
	}

	/**
	 * Generate a state variable for the connect request.
	 * This also saves it in a transient, so we can validate it when the authorization is returned.
	 * The state is stored per user, so it can only be completed by the user who started the flow.
	 *
	 * @return string
	 */
	public static function generate_state(): string {
		$state = wp_generate_password( 32, false, false );
		set_transient( self::get_state_key(), $state, 15 * MINUTE_IN_SECONDS );

		return $state;
	}

	/**
	 * Get the state variable for the connect request.
	 *
	 * @return string The stored state, or an empty string when there is none.
	 */
	public static function get_state(): string {
		$state = get_transient( self::get_state_key() );

		return is_string( $state ) ? $state : '';
	}

	/**
	 * Delete the state variable for the connect request.
	 *
	 * @return void
	 */
	public static function delete_state(): void {
		delete_transient( self::get_state_key() );

		// state stored by earlier versions, shared by all users.
		delete_transient( 'scanfully_connect_state' );
	}

	/**
	 * Get the transient key holding the connect state for the current user.
	 *
	 * @return string
	 */
	private static function get_state_key(): string {
		return 'scanfully_connect_state_' . get_current_user_id();
	}

	/**
	 * Generate and store the PKCE code verifier (RFC 7636) for a connect
	 * request of the current user. The API only exchanges the code for a
	 * request that started with its challenge for the verifier, so a code
	 * that ends up elsewhere is worthless.
	 *
	 * @return string The verifier: 64 random letters and digits.
	 */
	public static function generate_code_verifier(): string {
		$verifier = wp_generate_password( 64, false, false );
		set_transient( self::get_code_verifier_key(), $verifier, 15 * MINUTE_IN_SECONDS );

		return $verifier;
	}

	/**
	 * Get the stored PKCE code verifier of the current user.
	 *
	 * @return string The verifier, or an empty string when there is none.
	 */
	public static function get_code_verifier(): string {
		$verifier = get_transient( self::get_code_verifier_key() );

		return is_string( $verifier ) ? $verifier : '';
	}

	/**
	 * Delete the stored PKCE code verifier of the current user.
	 *
	 * @return void
	 */
	public static function delete_code_verifier(): void {
		delete_transient( self::get_code_verifier_key() );
	}

	/**
	 * The S256 challenge of a code verifier: its SHA-256, base64url without padding.
	 *
	 * @param  string $verifier The code verifier.
	 *
	 * @return string
	 */
	public static function code_challenge( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url is the encoding RFC 7636 requires.
	}

	/**
	 * Get the transient key holding the PKCE code verifier for the current user.
	 *
	 * @return string
	 */
	private static function get_code_verifier_key(): string {
		return 'scanfully_connect_verifier_' . get_current_user_id();
	}
}