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
	 * @param  array  $data The data to send with the request.
	 *
	 * @return void
	 */
	public function do_request( string $endpoint, array $data ): void {

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
			'blocking'    => true,
			'httpversion' => '1.0',
			'sslverify'   => false,
		];

		// add body to request if there's any.
		$request_body = $this->get_body( $data );
		if ( ! empty( $request_body ) ) {
			$request_args['body'] = wp_json_encode( $request_body );
		}

		$response = wp_remote_post( $this->get_url( $endpoint ), $request_args );

		// Only update last_used when we can confirm a successful response.
		if ( ! is_wp_error( $response ) ) {
			$status = wp_remote_retrieve_response_code( $response );
			if ( $status >= 200 && $status < 300 ) {
				// A successful request proves the connection works, so clear any
				// stale refresh-failure state that would otherwise keep the
				// broken-connection notice showing while data is flowing.
				\Scanfully\Cron\Controller::clear_refresh_failures();
				try {
					$now = new \DateTime();
					$now->setTimezone( new \DateTimeZone( 'UTC' ) );
					OptionController::set_option( 'last_used', $now->format( \Scanfully\Connect\Controller::DATE_FORMAT ) );
				} catch ( \Exception $e ) {
					// do nothing for now, just don't break the plugin.
				}
			}
		}
	}

	/**
	 * Send the request to the API and return the parsed response.
	 *
	 * Variant of do_request() used by callers that need the response body
	 * and HTTP status (e.g. provisioning + state-fetch flows). Returns an
	 * associative array with keys 'status' (int) and 'body' (mixed, decoded
	 * JSON or null on parse failure). Returns null on transport error.
	 *
	 * @param string $endpoint The endpoint to send the request to.
	 * @param array  $data     The data to send with the request.
	 *
	 * @return array|null
	 */
	public function do_request_with_response( string $endpoint, array $data ): ?array {
		$headers      = [
			'Content-Type' => 'application/json',
		];
		$auth_headers = $this->get_auth_headers();
		if ( ! empty( $auth_headers ) ) {
			$headers = array_merge( $headers, $auth_headers );
		}

		$request_args = [
			'headers'     => $headers,
			'timeout'     => 30,
			'blocking'    => true,
			'httpversion' => '1.0',
			'sslverify'   => false,
		];
		$request_body = $this->get_body( $data );
		if ( ! empty( $request_body ) ) {
			$request_args['body'] = wp_json_encode( $request_body );
		}

		$response = wp_remote_post( $this->get_url( $endpoint ), $request_args );

		return $this->process_response( $response );
	}

	/**
	 * Send a GET request to the API.
	 *
	 * Mirrors do_request_with_response() but uses wp_remote_get(). Query
	 * parameters are appended to the URL.
	 *
	 * @param string $endpoint The endpoint to send the request to.
	 * @param array  $query    Optional query parameters.
	 *
	 * @return array|null
	 */
	public function do_get_request( string $endpoint, array $query = [] ): ?array {
		$headers      = [
			'Accept' => 'application/json',
		];
		$auth_headers = $this->get_auth_headers();
		if ( ! empty( $auth_headers ) ) {
			$headers = array_merge( $headers, $auth_headers );
		}

		$url = $this->get_url( $endpoint );
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$response = wp_remote_get(
			$url,
			[
				'headers'     => $headers,
				'timeout'     => 30,
				'blocking'    => true,
				'httpversion' => '1.0',
				'sslverify'   => false,
			]
		);

		return $this->process_response( $response );
	}

	/**
	 * Parse a wp_remote_* response into a {status, body} array.
	 *
	 * @param array|\WP_Error $response The raw response from wp_remote_*.
	 *
	 * @return array|null Null on transport error; otherwise ['status' => int, 'body' => mixed].
	 */
	private function process_response( $response ): ?array {
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$status   = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );

		// A successful request proves the connection works, so clear any stale
		// refresh-failure state that would otherwise keep the broken-connection
		// notice showing while the plugin is communicating fine.
		if ( $status >= 200 && $status < 300 ) {
			\Scanfully\Cron\Controller::clear_refresh_failures();
		}

		$decoded  = null;
		if ( '' !== $raw_body ) {
			$decoded = json_decode( $raw_body, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$decoded = null;
			}
		}
		return [
			'status' => $status,
			'body'   => $decoded,
		];
	}

	/**
	 * Get the auth headers for the request.
	 *
	 * @return array
	 */
	public function get_auth_headers(): array {
		$headers                  = [];
		$headers['Authorization'] = sprintf( 'Bearer %s', OptionController::get_option( 'access_token' ) );

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
