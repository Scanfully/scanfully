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
 * authenticated website -> API channel. A rate limiter prevents the endpoint
 * from being abused to flood the Scanfully API.
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
				// Unauthenticated by design: the endpoint returns no data and only
				// triggers a self-push. Abuse is bounded by the rate limiter below.
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle an on-demand sync request. Schedules the existing health and
	 * directory syncs to run as soon as possible and returns immediately.
	 *
	 * @return \WP_REST_Response
	 */
	public static function handle_sync(): \WP_REST_Response {
		// Nothing to sync (and no token) when the site isn't connected. Reject
		// before consuming the rate-limit budget.
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

		as_schedule_single_action( time(), Cron\Controller::ACTION_SYNC_SITE_HEALTH, [], self::AS_GROUP );
		as_schedule_single_action( time(), Cron\Controller::ACTION_SYNC_DIRECTORIES, [], self::AS_GROUP );
	}

}
