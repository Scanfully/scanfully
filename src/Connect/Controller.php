<?php

namespace Scanfully\Connect;

class Controller {

	private const CONNECT_URL = 'http://localhost:5173/connect';

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
				self::CONNECT_URL
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

			add_action( 'admin_notices', function() {
				?>
				<div class="notice notice-error is-dismissible">
					<p>There was an error connecting to Scanfully. Please try again.</p>
				</div>
				<?php
			} );


		}
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
	 * Check if the user is connected to Scanfully.
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		return false;
	}

}