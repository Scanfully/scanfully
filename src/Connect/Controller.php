<?php

namespace Scanfully\Connect;

use Scanfully\Main;
use Scanfully\Options\Options;

class Controller {

	/**
	 * Set up the connect controller
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_action( 'admin_init', [ Controller::class, 'catch_request_connect_start' ] );
		add_action( 'admin_init', [ Controller::class, 'catch_request_connect_success' ] );
		add_action( 'admin_init', [ Controller::class, 'catch_request_connect_error' ] );
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
	 * Catch the request to start the connect process.
	 *
	 * @return void
	 */
	public static function catch_request_connect_start(): void {
		if ( isset( $_GET['scanfully-connect'] ) ) {

			// check nonce
			if ( ! isset( $_GET['scanfully-connect-nonce'] ) || ! wp_verify_nonce( $_GET['scanfully-connect-nonce'], 'scanfully-connect-redirect' ) ) {
				wp_die( 'Invalid Scanfully connect nonce' );
			}

			// check permissions
			if ( ! self::user_has_access() ) {
				wp_die( 'You do not have permission to do this.' );
			}

			// build the connect URL and redirect.
			$connect_url = add_query_arg(
				[
					'redirect_uri' => Page::get_page_url(),
					'site'         => get_site_url(),
					'state'        => self::generate_state(),
				],
				Main::CONNECT_URL
			);
			wp_redirect( $connect_url );
			exit;
		}
	}

	/**
	 * Catch the request that is returned from the connect process on success.
	 *
	 * @return void
	 */
	public static function catch_request_connect_success(): void {

		/**
		 * https://scanfully-plugin.test/wp-admin/options-general.php
		 * ?page=scanfully
		 * &scanfully-connect-success=true
		 * &code=iwYOdU4uCWTSY4lt4gI389FmLkDNqSt4iZwLJBt1
		 * &site=a6dbda75-03ee-44e1-ae65-3cb5e3fc5d30
		 * &state=MAekQwsMivpY
		 */

		if ( isset( $_GET['scanfully-connect-success'] ) ) {

			// check permissions
			if ( ! self::user_has_access() ) {
				wp_die( 'You do not have permission to do this.' );
			}

			// check if state matches
			if ( self::get_state() !== $_GET['state'] ) {
				wp_die( 'Invalid Scanfully connect state' );
			}

			// check if required parameters are set
			if ( ! isset( $_GET['code'] ) || ! isset( $_GET['site'] ) ) {
				wp_die( 'Invalid Scanfully connect parameters' );
			}

			// delete state
//			self::delete_state();


			// get variables
			$code = $_GET['code'];
			$site = $_GET['site'];

			// exchange authorization code for access token
			$tokens = self::exchange_authorization_code( $code, $site );

			echo 'Exchange authorization code for access token';
			echo '<pre>';
			print_r( $tokens );
			echo '</pre>';
			exit;


		}

	}

	/**
	 *  Catch the request that is returned from the connect process on error.
	 *
	 * @return void
	 */
	public static function catch_request_connect_error(): void {
		if ( isset( $_GET['scanfully-connect-error'] ) ) {

			// check permissions
			if ( ! self::user_has_access() ) {
				wp_die( 'You do not have permission to do this.' );
			}

			$error_message = '';
			switch ( $_GET['scanfully-connect-error'] ) {
				case 'access_denied':
					$error_message = esc_html__( 'Access denied', 'scanfully' );
					break;
				default:
					$error_message = esc_html__( 'An unknown error occurred.', 'scanfully' );
					break;
			}

			add_action( 'scanfully_connect_notices', function () use ( $error_message ) {
				?>
				<div class="scanfully-connect-notice scanfully-connect-notice-error">
					<p><?php printf( esc_html__( 'There was an error connecting to Scanfully: %s', 'scanfully' ), $error_message ); ?></p>
				</div>
				<?php
			} );
		}
	}

	/**
	 * Exchange the authorization code for an access and refresh token.
	 *
	 * @param  string $code
	 * @param  string $site
	 *
	 * @return array('access_token' => '...', 'refresh_token' => '...', 'expires_in' => '...')
	 */
	private static function exchange_authorization_code( string $code, string $site ): array {

		// request arguments for the requests.
		$request_args = [
			'headers'     => [ 'Content-Type' => 'application/json' ],
			'timeout'     => 60,
			'blocking'    => true,
			'httpversion' => '1.0',
			'sslverify'   => false,
			'body'        => wp_json_encode( [
				'grant_type' => 'authorization_code',
				'code'       => $code,
				'site'       => $site,
			] ),
		];

		// later check if post failed and show a notice to admins.
		$resp = wp_remote_post( Main::API_URL . '/connect/token', $request_args );

		// check if the request failed
		if ( is_wp_error( $resp ) ) {
			return [];
		}

		$body = wp_remote_retrieve_body( $resp );

		if ( empty( $body ) ) {
			return [];
		}

		// return the response
		return json_decode( $body, true );
	}

	/**
	 * Generate a state variable for the connect request.
	 * This also saves it in a transient, so we can validate it when the authorization is returned.
	 *
	 * @return string
	 */
	public static function generate_state(): string {
		$state = wp_generate_password( 12, false, false );
		set_transient( 'scanfully_connect_state', $state, HOUR_IN_SECONDS );

		return $state;
	}

	/**
	 * Get the state variable for the connect request.
	 *
	 * @return string
	 */
	public static function get_state(): string {
		return get_transient( 'scanfully_connect_state' );
	}

	/**
	 * Delete the state variable for the connect request.
	 *
	 * @return void
	 */
	public static function delete_state(): void {
		delete_transient( 'scanfully_connect_state' );
	}

	/**
	 * Check if the user is connected to Scanfully.
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		$options = Options::get_options();
		if ( ! empty( $options['is_connected'] ) && 'yes' === $options['is_connected'] ) {
			return true;
		}

		return false;
	}

}