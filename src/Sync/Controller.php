<?php
/**
 * The sync controller class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Sync;

use Scanfully\Cron;
use Scanfully\Options;

/**
 * Registers an inbound REST endpoint the Scanfully API can call to request an
 * on-demand refresh of this site. The endpoint never returns site data: it only
 * schedules the site's existing outbound syncs, which push data over the
 * authenticated website -> API channel.
 *
 * Callers must send the site's current access token, either as
 * `Authorization: Bearer <token>` or as `X-Scanfully-Token: <token>` for hosts
 * that strip the Authorization header. A rate limiter bounds how often even an
 * authenticated caller can trigger a sync.
 */
class Controller {

	/**
	 * REST namespace for Scanfully routes.
	 */
	private const REST_NAMESPACE = 'scanfully/v1';

	/**
	 * REST route for the on-demand sync trigger.
	 */
	private const REST_ROUTE = '/sync';

	/**
	 * Transient storing the timestamp of the last accepted on-demand sync.
	 */
	private const RATE_LIMIT_TRANSIENT = 'scanfully_sync_last_run';

	/**
	 * Minimum seconds between two accepted on-demand syncs.
	 */
	private const RATE_LIMIT_WINDOW = 5 * MINUTE_IN_SECONDS;

	/**
	 * Action Scheduler group for all Scanfully jobs.
	 */
	private const AS_GROUP = 'scanfully';

	/**
	 * Register the REST route.
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	/**
	 * Register the on-demand sync route.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle_sync' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			]
		);
	}

	/**
	 * Only the Scanfully API may trigger a sync: the request must carry the
	 * site's current access token. Unauthenticated requests are rejected
	 * before they can consume the rate-limit budget.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return bool
	 */
	public static function check_permission( \WP_REST_Request $request ): bool {
		$options = Options\Controller::get_options();
		if ( ! $options->is_connected || '' === $options->access_token ) {
			return false;
		}

		$token = self::get_request_token( $request );

		return '' !== $token && hash_equals( $options->access_token, $token );
	}

	/**
	 * Read the token from the Authorization bearer header, falling back to
	 * the X-Scanfully-Token header.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return string The token, or an empty string when none was sent.
	 */
	private static function get_request_token( \WP_REST_Request $request ): string {
		$authorization = (string) $request->get_header( 'authorization' );
		if ( 1 === preg_match( '/^Bearer\s+(\S+)\s*\z/i', $authorization, $matches ) ) {
			return $matches[1];
		}

		return trim( (string) $request->get_header( 'x_scanfully_token' ) );
	}

	/**
	 * Handle an on-demand sync request. Schedules the existing health and
	 * directory syncs to run as soon as possible and returns immediately.
	 *
	 * @return \WP_REST_Response
	 */
	public static function handle_sync(): \WP_REST_Response {
		// Nothing to sync when the site isn't connected. check_permission()
		// already rejects these requests; this guards direct calls.
		if ( ! Options\Controller::get_options()->is_connected ) {
			return new \WP_REST_Response( [ 'status' => 'not_connected' ], 409 );
		}

		$last_run = (int) get_transient( self::RATE_LIMIT_TRANSIENT );
		if ( $last_run > 0 ) {
			$retry_after = max( 1, self::RATE_LIMIT_WINDOW - ( time() - $last_run ) );

			$response = new \WP_REST_Response( [ 'status' => 'rate_limited' ], 429 );
			$response->header( 'Retry-After', (string) $retry_after );

			return $response;
		}

		set_transient( self::RATE_LIMIT_TRANSIENT, time(), self::RATE_LIMIT_WINDOW );

		self::schedule_syncs();

		return new \WP_REST_Response( [ 'status' => 'scheduled' ], 202 );
	}

	/**
	 * Schedule the health and directory syncs to run immediately, mirroring the
	 * post-connect flow. The scheduled actions handle token refresh and the
	 * connection check themselves.
	 *
	 * @return void
	 */
	private static function schedule_syncs(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		// Unique: parallel requests can't queue duplicate syncs.
		as_schedule_single_action( time(), Cron\Controller::ACTION_SYNC_SITE_HEALTH, [], self::AS_GROUP, true );
		as_schedule_single_action( time(), Cron\Controller::ACTION_SYNC_DIRECTORIES, [], self::AS_GROUP, true );
	}
}
