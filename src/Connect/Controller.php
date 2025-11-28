<?php

namespace Scanfully\Connect;

use Scanfully\Main;
use Scanfully\Options\Controller as OptionsController;
use Scanfully\Options\Options;

class Controller {

	public const DATE_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Set up the connect controller
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_action( 'admin_init', [ Controller::class, 'catch_connect_requests' ] );
	}

	/**
	 * Check if the user has access to the connect process.
	 *
	 * @return bool
	 */
	private static function user_has_access(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function catch_connect_requests(): void {

		// handle start connect request
		if ( isset( $_GET['scanfully-connect'] ) ) {
			self::handle_connect_start();
		}

		// handle start disconnect request
		if ( isset( $_GET['scanfully-disconnect'] ) ) {
			self::handle_disconnect_start();
		}

		// handle connect success return request
		if ( isset( $_GET['scanfully-connect-success'] ) ) {
			self::handle_request_connect_success();
		}

		// handle connect error return request
		if ( isset( $_GET['scanfully-connect-error'] ) ) {
			self::handle_request_connect_error();
		}

		if ( isset( $_GET['scanfully-connect-done'] ) ) {
			// add success message
			self::print_notice( esc_html__( 'Successfully connected to Scanfully', 'scanfully' ), 'success' );
		}

	}

	/**
	 * Catch the request to start the connect process.
	 *
	 * @return void
	 */
	private static function handle_connect_start(): void {
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
				'redirect_uri' => rawurlencode( Page::get_page_url() ),
				'site'         => rawurlencode( get_site_url() ),
				'state'        => self::generate_state(),
			],
			Main::get_connect_url()
		);

		wp_redirect( $connect_url );
		exit;
	}

	/**
	 * Catch the request to start the connect process.
	 *
	 * @return void
	 */
	private static function handle_disconnect_start(): void {
		// check nonce
		if ( ! isset( $_GET['scanfully-disconnect-nonce'] ) || ! wp_verify_nonce( $_GET['scanfully-disconnect-nonce'], 'scanfully-disconnect-redirect' ) ) {
			wp_die( 'Invalid Scanfully disconnect nonce' );
		}

		// check permissions
		if ( ! self::user_has_access() ) {
			wp_die( 'You do not have permission to do this.' );
		}

		// remove all options / settings
		OptionsController::clear();

		// redirect to base connect page
		wp_redirect( Page::get_page_url() );
		exit;
	}

	/**
	 * Catch the request that is returned from the connect process on success.
	 *
	 * @return void
	 */
	private static function handle_request_connect_success(): void {

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
		self::delete_state();

		// get variables
		$code = $_GET['code'];
		$site = $_GET['site'];

		// exchange authorization code for access token
		$tokens = self::exchange_authorization_code( $code, $site );

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

		// format options
		$options = new Options(
			true,
			$site,
			$tokens['access_token'],
			$tokens['refresh_token'],
			$expires->format( self::DATE_FORMAT ),
			'',
			$now->format( self::DATE_FORMAT )
		);

		// save options
		OptionsController::set_options( $options );


		// run cron jobs a single time so user doesn't have to wait for the next cron job
		wp_schedule_single_event( time(), 'scanfully_daily' );
		wp_schedule_single_event( time(), 'scanfully_twice_daily' );

		// redirect to base connect page with success message
		wp_redirect( add_query_arg( [ 'scanfully-connect-done' => '1' ], Page::get_page_url() ) );
	}

	/**
	 *  Catch the request that is returned from the connect process on error.
	 *
	 * @return void
	 */
	private static function handle_request_connect_error(): void {
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

			self::print_notice( $error_message, 'error' );
		}
	}

	/**
	 * Print a notice to the connect admin.
	 *
	 * @param  string $message
	 * @param  string $type
	 *
	 * @return void
	 */
	private static function print_notice( string $message, string $type = 'error' ): void {
		add_action( 'scanfully_connect_notices', function () use ( $message, $type ) {
			?>
			<div class="scanfully-connect-notice scanfully-connect-notice-<?php echo esc_attr( $type ); ?> is-dismissible">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
			<?php
		} );
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
				'site_id'    => $site,
			] ),
		];

		// later check if post failed and show a notice to admins.
		$resp = wp_remote_post( Main::get_api_url() . '/connect/token', $request_args );

		// check if the request failed
		if ( is_wp_error( $resp ) ) {
			return [];
		}

		// todo check if request failed based on http status code

		$body = wp_remote_retrieve_body( $resp );

		if ( empty( $body ) ) {
			return [];
		}

		// return the response
		return json_decode( $body, true );
	}

	/**
	 * Use the refresh token to get a new access and refresh token
	 *
	 * @param  string $refresh_token
	 * @param  string $site
	 *
	 * @return array
	 */
	public static function refresh_access_token( string $refresh_token, string $site ): array {

		// request arguments for the requests.
		$request_args = [
			'headers'     => [ 'Content-Type' => 'application/json' ],
			'timeout'     => 60,
			'blocking'    => true,
			'httpversion' => '1.0',
			'sslverify'   => false,
			'body'        => wp_json_encode( [
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
				'site_id'       => $site,
			] ),
		];

		// later check if post failed and show a notice to admins.
		$resp = wp_remote_post( Main::get_api_url() . '/connect/token', $request_args );

		// check if the request failed
		if ( is_wp_error( $resp ) ) {
			return [];
		}

		// todo check if request failed based on http status code

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

}