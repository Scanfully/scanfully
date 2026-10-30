<?php
/**
 * The request class file.
 *
 * @package Scanfully
 */

namespace Scanfully\API;

use Scanfully\Main;
use Scanfully\Options\Controller as OptionController;

/**
 * Request class.
 */
abstract class Request {

	/**
	 * Option holding the plugin's last failed request to Scanfully: when,
	 * the HTTP status (0 when no answer came) and the error. The sync route
	 * answers it to the API, so the site's owner learns why the plugin
	 * stopped reporting. Written only on a failure, never autoloaded.
	 */
	public const OPTION_LAST_ERROR = 'scanfully_connect_last_request_error';

	/**
	 * The longest error text kept.
	 */
	private const MAX_ERROR_LENGTH = 200;

	/**
	 * Send the request to the API.
	 *
	 * @param  string $endpoint The endpoint to send the request to.
	 * @param  array  $data The data to send with the request.
	 *
	 * @return int|null The HTTP status, or null when the request itself failed.
	 */
	public function do_request( string $endpoint, array $data ): ?int {

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
			'sslverify'   => Main::get_sslverify(),
		];

		// add body to request if there's any.
		$request_body = $this->get_body( $data );
		if ( ! empty( $request_body ) ) {
			$request_args['body'] = wp_json_encode( $request_body );
		}

		$response = wp_remote_post( $this->get_url( $endpoint ), $request_args );
		if ( is_wp_error( $response ) ) {
			self::record_failure( 0, self::error_message( $response ) );
			return null;
		}

		// Only update last_used when we can confirm a successful response.
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			self::record_success();
		} else {
			self::record_failure( $status, '' );
		}

		return $status;
	}

	/**
	 * Keep that a request to Scanfully worked: it proves the connection
	 * works, so any stale refresh-failure state that would keep the
	 * broken-connection notice showing goes, and last_used says when. The
	 * sync route answers last_used to the API as the last success, next to
	 * the last failure, so every kind of request records both.
	 *
	 * @return void
	 */
	private static function record_success(): void {
		\Scanfully\Cron\Controller::clear_refresh_failures();
		try {
			$now = new \DateTime();
			$now->setTimezone( new \DateTimeZone( 'UTC' ) );
			OptionController::set_option( 'last_used', $now->format( \Scanfully\Connect\Controller::DATE_FORMAT ) );
		} catch ( \Exception $e ) {
			// do nothing for now, just don't break the plugin.
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
	 * @param int    $timeout  Optional timeout in seconds.
	 *
	 * @return array|null
	 */
	public function do_request_with_response( string $endpoint, array $data, int $timeout = 30 ): ?array {
		$headers      = [
			'Content-Type' => 'application/json',
		];
		$auth_headers = $this->get_auth_headers();
		if ( ! empty( $auth_headers ) ) {
			$headers = array_merge( $headers, $auth_headers );
		}

		$request_args = [
			'headers'     => $headers,
			'timeout'     => $timeout,
			'blocking'    => true,
			'httpversion' => '1.0',
			'sslverify'   => Main::get_sslverify(),
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
	 * @param int    $timeout  Optional timeout in seconds.
	 *
	 * @return array|null
	 */
	public function do_get_request( string $endpoint, array $query = [], int $timeout = 30 ): ?array {
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
				'timeout'     => $timeout,
				'blocking'    => true,
				'httpversion' => '1.0',
				'sslverify'   => Main::get_sslverify(),
			]
		);

		return $this->process_response( $response );
	}

	/**
	 * Parse a wp_remote_* response into a {status, body} array.
	 *
	 * @param array|\WP_Error $response The raw response from wp_remote_*.
	 *
	 * @return array|null Null on transport error; otherwise ['status' => int, 'body' => mixed, 'raw' => string].
	 */
	private function process_response( $response ): ?array {
		if ( is_wp_error( $response ) ) {
			self::record_failure( 0, self::error_message( $response ) );
			return null;
		}
		$status   = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			self::record_failure( $status, '' );
		}

		// A successful request proves the connection works, so clear any stale
		// refresh-failure state that would otherwise keep the broken-connection
		// notice showing while the plugin is communicating fine.
		if ( $status >= 200 && $status < 300 ) {
			self::record_success();
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
			'raw'    => (string) $raw_body,
		];
	}

	/**
	 * Keep a failed request to Scanfully: no answer at all, with the
	 * transport's error, or an answer that says it did not get through.
	 * Scanfully answering 400 or 429 about one request does not count: the
	 * connection works, and the API would tell the site's owner it doesn't.
	 *
	 * @param int    $status  The HTTP status; 0 when no answer came.
	 * @param string $message The error of a request that got no answer.
	 *
	 * @return void
	 */
	private static function record_failure( int $status, string $message ): void {
		if ( $status >= 400 && $status < 500 && ! in_array( $status, [ 401, 403, 407 ], true ) ) {
			return;
		}

		update_option(
			self::OPTION_LAST_ERROR,
			[
				'at'      => time(),
				'status'  => $status,
				'message' => mb_substr( trim( $message ), 0, self::MAX_ERROR_LENGTH ),
			],
			false
		);
	}

	/**
	 * The message of a transport error, or '' when it holds none.
	 *
	 * @param mixed $error What is_wp_error() took for an error.
	 *
	 * @return string
	 */
	private static function error_message( $error ): string {
		return $error instanceof \WP_Error ? $error->get_error_message() : '';
	}

	/**
	 * The plugin's last failed request to Scanfully, or null when none
	 * failed since the site connected.
	 *
	 * @return array{at: int, status: int, message: string}|null
	 */
	public static function last_error(): ?array {
		$error = get_option( self::OPTION_LAST_ERROR );
		if ( ! is_array( $error ) || ! isset( $error['at'] ) ) {
			return null;
		}

		return [
			'at'      => (int) $error['at'],
			'status'  => (int) ( $error['status'] ?? 0 ),
			'message' => (string) ( $error['message'] ?? '' ),
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
