<?php
/**
 * The request class file.
 *
 * @package Scanfully
 */

namespace Scanfully\API;

use Scanfully\Connect\Target;
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
	 * Where the request goes; null for the connection that applies here, as
	 * read from the options scope of this request.
	 *
	 * @var Target|null
	 */
	protected ?Target $target = null;

	/**
	 * Whether the token is read from the database, past the options cache.
	 *
	 * @var bool
	 */
	private bool $fresh_token = false;

	/**
	 * Send the request to a target (see Connect\Target): its site id, its
	 * token and, for a network child, the blog id header.
	 *
	 * @param Target|null $target The target; null for the connection that applies here.
	 *
	 * @return static
	 */
	public function to( ?Target $target ) {
		$this->target = $target;

		return $this;
	}

	/**
	 * Read the token from the database for this request: a retry after a
	 * refused token, which another process may have replaced meanwhile.
	 *
	 * @return static
	 */
	public function with_fresh_token() {
		$this->fresh_token = true;

		return $this;
	}

	/**
	 * The Scanfully site id the request is about.
	 *
	 * @return string
	 */
	protected function site_id(): string {
		return null !== $this->target ? $this->target->site_id() : OptionController::get_option( 'site_id' );
	}

	/**
	 * Whether the request goes to a network child, whose state is kept apart
	 * from the site's own connection.
	 *
	 * @return bool
	 */
	private function to_child(): bool {
		return null !== $this->target && $this->target->is_child();
	}

	/**
	 * Send the request to the API.
	 *
	 * @param  string $endpoint The endpoint to send the request to.
	 * @param  array  $data The data to send with the request.
	 * @param  int    $timeout Seconds to wait for an answer.
	 *
	 * @return int|null The HTTP status, or null when the request itself failed.
	 */
	public function do_request( string $endpoint, array $data, int $timeout = 60 ): ?int {

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
			'timeout'     => $timeout,
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
			$this->record_failure( 0, self::error_message( $response ) );
			return null;
		}

		// Only update last_used when we can confirm a successful response.
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			$this->record_success();
		} else {
			$this->record_failure( $status, '' );
		}

		return $status;
	}

	/**
	 * Keep that a request to Scanfully worked: it proves the connection
	 * works, so any stale refresh-failure state that would keep the
	 * broken-connection notice showing goes, and last_used says when. The
	 * sync route answers last_used to the API as the last success, next to
	 * the last failure, so every kind of request records both. A request to
	 * a network child says nothing about the site's own connection.
	 *
	 * @return void
	 */
	private function record_success(): void {
		if ( $this->to_child() ) {
			return;
		}
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
			$this->record_failure( 0, self::error_message( $response ) );
			return null;
		}
		$status   = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$decoded  = null;
		if ( '' !== $raw_body ) {
			$decoded = json_decode( $raw_body, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$decoded = null;
			}
		}
		if ( ( $status < 200 || $status >= 300 ) && ! self::is_permission_answer( $status, $decoded ) ) {
			$this->record_failure( $status, '' );
		}

		// A successful request proves the connection works, so clear any stale
		// refresh-failure state that would otherwise keep the broken-connection
		// notice showing while the plugin is communicating fine.
		if ( $status >= 200 && $status < 300 ) {
			$this->record_success();
		}

		return [
			'status' => $status,
			'body'   => $decoded,
			'raw'    => (string) $raw_body,
		];
	}

	/**
	 * The codes of a 403 that says what the connection may do, not that it
	 * is broken: the grant lacks the scope of the route, the account may not
	 * use a closed-beta feature (Scanfully Manage), or the account's trial
	 * ended or it is paused for an overdue payment.
	 */
	public const PERMISSION_CODES = [ 'insufficient_scope', 'beta_required', 'trial_expired', 'account_paused' ];

	/**
	 * Whether an answer is a 403 about what the connection may do.
	 *
	 * @param int   $status The HTTP status.
	 * @param mixed $body   The decoded body.
	 *
	 * @return bool
	 */
	public static function is_permission_answer( int $status, $body ): bool {
		return 403 === $status && is_array( $body ) && isset( $body['error'] ) && in_array( $body['error'], self::PERMISSION_CODES, true );
	}

	/**
	 * Keep a failed request to Scanfully: no answer at all, with the
	 * transport's error, or an answer that says it did not get through.
	 * Scanfully answering 400 or 429 about one request does not count: the
	 * connection works, and the API would tell the site's owner it doesn't.
	 * A request to a network child keeps its own last failure.
	 *
	 * @param int    $status  The HTTP status; 0 when no answer came.
	 * @param string $message The error of a request that got no answer.
	 *
	 * @return void
	 */
	private function record_failure( int $status, string $message ): void {
		if ( $status >= 400 && $status < 500 && ! in_array( $status, [ 401, 403, 407 ], true ) ) {
			return;
		}

		update_option(
			null !== $this->target ? $this->target->option( self::OPTION_LAST_ERROR ) : self::OPTION_LAST_ERROR,
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
		return self::last_error_of( null );
	}

	/**
	 * The last failed request to a target, or null when none failed.
	 *
	 * @param Target|null $target The target; null for the site's own connection.
	 *
	 * @return array{at: int, status: int, message: string}|null
	 */
	public static function last_error_of( ?Target $target ): ?array {
		$error = get_option( null !== $target ? $target->option( self::OPTION_LAST_ERROR ) : self::OPTION_LAST_ERROR );
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
		$headers = [];
		if ( null !== $this->target ) {
			$headers['Authorization'] = sprintf( 'Bearer %s', $this->target->access_token( $this->fresh_token ) );
			$headers                  = array_merge( $headers, $this->target->headers() );
		} else {
			$headers['Authorization'] = sprintf( 'Bearer %s', $this->fresh_token ? OptionController::get_fresh_options()->access_token : OptionController::get_option( 'access_token' ) );
		}

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
