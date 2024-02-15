<?php
/**
 * The request class file.
 *
 * @package Scanfully
 */

namespace Scanfully\API;

use Scanfully\Options\Controller as OptionController;

/**
 * Request class.
 */
abstract class Request {

	/**
	 * Send the request to the API.
	 *
	 * @param  string $endpoint The endpoint to send the request to.
	 * @param  array $data The data to send with the request.
	 *
	 * @return void
	 */
	public function send( string $endpoint, array $data ): void {

		// headers for the requests.
		$headers = [
			'Content-Type' => 'application/json',
		];

		// add auth if needed.
		$auth_headers = $this->get_auth_headers();
		if ( ! empty( $auth_headers ) ) {
			$headers = array_merge( $headers, $auth_headers );
		}

		// request arguments for the requests.
		$request_args = [
			'headers'     => $headers,
			'timeout'     => 60,
			'blocking'    => false,
			'httpversion' => '1.0',
			'sslverify'   => false,
		];

		// add body to request if there's any.
		$request_body = $this->get_body( $data );
		if ( ! empty( $request_body ) ) {
			$request_args['body'] = wp_json_encode( $request_body );
		}

		// later check if post failed and show a notice to admins.
		wp_remote_post( $this->get_url( $endpoint ), $request_args );
	}

	/**
	 * Get the auth headers for the request.
	 *
	 * @return array
	 */
	public function get_auth_headers(): array {
		$headers                  = [];
		$headers['Authorization'] = sprintf( "Bearer %s", OptionController::get_option( 'access_token' ) );

		return apply_filters( 'scanfully_auth_headers', $headers );
	}

	/**
	 * Get the url for the request.
	 *
	 * @param  string $endpoint The endpoint to send the request to.
	 *
	 * @return string
	 */
	abstract public function get_url( string $endpoint ): string;


	/**
	 * Get the body for the request.
	 *
	 * @param  array $data The data to send with the request.
	 *
	 * @return array
	 */
	abstract public function get_body( array $data ): array;
}
