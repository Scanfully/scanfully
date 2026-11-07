<?php
/**
 * The connection scopes class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Connect;

use Scanfully\API\PluginConnectionRequest;
use Scanfully\Cron;
use Scanfully\Manage;
use Scanfully\Options\Controller as OptionsController;
use Scanfully\Sync;

/**
 * What Scanfully may do on the site can change in the Scanfully dashboard
 * (Scanfully Manage): the grant there gains or loses wordpress:manage and
 * wordpress:login, and decides from that moment. The plugin keeps a copy of
 * the scopes, which steers its own Manage clock and the settings page. It
 * refreshes that copy from the API, with its own site token
 * (GET /v1/sites/{site_id}/plugin-connection):
 *
 * - when Scanfully pokes it with a signed request (POST
 *   /wp-json/scanfully/v1/scopes, sub scopes), which carries nothing to act
 *   on;
 * - once an hour, for a site whose host blocks the poke;
 * - with every token refresh, whose answer reports the scopes too.
 */
class Scopes {

	/**
	 * REST namespace for Scanfully routes.
	 */
	private const REST_NAMESPACE = 'scanfully/v1';

	/**
	 * REST route of the poke.
	 */
	private const REST_ROUTE = '/scopes';

	/**
	 * The action a signed poke names (sub).
	 */
	public const SIGNED_ACTION = 'scopes';

	/**
	 * Transient storing the time of the last accepted poke.
	 */
	private const RATE_LIMIT_TRANSIENT = 'scanfully_scopes_poke_last';

	/**
	 * Minimum seconds between two accepted pokes.
	 */
	private const RATE_LIMIT_WINDOW = 30;

	/**
	 * Hook: read the scopes, on the hourly clock.
	 */
	public const ACTION_CHECK = 'scanfully_scopes_check';

	/**
	 * Hook: read the scopes now, after a poke.
	 */
	public const ACTION_CHECK_NOW = 'scanfully_scopes_check_now';

	/**
	 * Seconds between two checks of the clock.
	 */
	public const CLOCK_INTERVAL = HOUR_IN_SECONDS;

	/**
	 * Option holding when the scopes last changed in the Scanfully dashboard.
	 */
	public const CHANGED_OPTION = 'scanfully_connect_scopes_changed';

	/**
	 * Action Scheduler group for all Scanfully jobs.
	 */
	private const AS_GROUP = 'scanfully';

	/**
	 * The scopes the plugin knows. Others the API may add later are kept out
	 * of its copy until a version of the plugin knows them.
	 */
	private const KNOWN = [ OAuth::SCOPE_READ, OAuth::SCOPE_MANAGE, OAuth::SCOPE_LOGIN ];

	/**
	 * Register the route, the actions and the clock's upkeep.
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
		add_action( self::ACTION_CHECK, [ self::class, 'refresh' ] );
		add_action( self::ACTION_CHECK_NOW, [ self::class, 'refresh' ] );
		add_action( 'action_scheduler_init', [ self::class, 'maybe_sync_clock' ] );
		add_action( 'scanfully_options_cleared', [ self::class, 'clear' ] );
	}

	/**
	 * Register the poke route.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle_poke' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			]
		);
	}

	/**
	 * Whether the site's connection lives on Scanfully's OAuth server: only
	 * such a connection has scopes that change in the dashboard.
	 *
	 * @return bool
	 */
	public static function applies(): bool {
		return OptionsController::get_options()->is_connected && 'oauth' === OptionsController::get_option( 'flow' );
	}

	/**
	 * Only a request Scanfully signed for this site and the action scopes
	 * may poke; every connection allows it (wordpress:read). The site's
	 * access token is never accepted here.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return bool
	 */
	public static function check_permission( \WP_REST_Request $request ): bool {
		if ( ! self::applies() ) {
			return false;
		}
		$token = Sync\Controller::get_request_token( $request );
		if ( '' === $token || ! Sync\SignedRequest::looks_signed( $token ) ) {
			return false;
		}

		return Sync\SignedRequest::verify( $token, OptionsController::get_options()->site_id, self::SIGNED_ACTION, OAuth::SCOPE_READ );
	}

	/**
	 * A poke: schedule a look at the connection, start the queue runner so it
	 * runs now, and answer at once. The poke carries no scopes: the plugin
	 * reads them from the API with its own token.
	 *
	 * @return \WP_REST_Response
	 */
	public static function handle_poke(): \WP_REST_Response {
		$last = (int) get_transient( self::RATE_LIMIT_TRANSIENT );
		if ( $last > 0 && time() - $last < self::RATE_LIMIT_WINDOW ) {
			// A second change soon after the first: read the scopes again once
			// the window ends, so it is not left to the hourly check.
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( $last + self::RATE_LIMIT_WINDOW, self::ACTION_CHECK_NOW, [], self::AS_GROUP, true );
			}
			$response = new \WP_REST_Response( [ 'status' => 'rate_limited' ], 429 );
			$response->header( 'Retry-After', (string) max( 1, self::RATE_LIMIT_WINDOW - ( time() - $last ) ) );

			return $response;
		}
		set_transient( self::RATE_LIMIT_TRANSIENT, time(), self::RATE_LIMIT_WINDOW );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::ACTION_CHECK_NOW, [], self::AS_GROUP, true );
		}
		Sync\Controller::run_queue_now();

		return new \WP_REST_Response( [ 'status' => 'scheduled' ], 200 );
	}

	/**
	 * Keep the hourly check scheduled where checking it is cheap enough:
	 * wp-admin, cron runs and WP-CLI.
	 *
	 * @return void
	 */
	public static function maybe_sync_clock(): void {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		self::sync_clock();
	}

	/**
	 * Schedule the hourly check while the connection lives on the OAuth
	 * server, and remove it when it does not.
	 *
	 * @return void
	 */
	public static function sync_clock(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! did_action( 'action_scheduler_init' ) ) {
			return;
		}
		$wanted    = self::applies();
		$scheduled = as_has_scheduled_action( self::ACTION_CHECK, [], self::AS_GROUP );
		if ( $wanted && ! $scheduled ) {
			as_schedule_recurring_action( time() + self::CLOCK_INTERVAL, self::CLOCK_INTERVAL, self::ACTION_CHECK, [], self::AS_GROUP, true );
		} elseif ( ! $wanted && $scheduled ) {
			as_unschedule_all_actions( self::ACTION_CHECK, [], self::AS_GROUP );
		}
	}

	/**
	 * Stop the checks on disconnect, and forget the last change.
	 *
	 * @return void
	 */
	public static function clear(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_CHECK, [], self::AS_GROUP );
			as_unschedule_all_actions( self::ACTION_CHECK_NOW, [], self::AS_GROUP );
		}
		self::forget_change();
	}

	/**
	 * Read what the connection allows from the API and keep it. Runs in
	 * Action Scheduler. An answer without scopes (an older API, a refused
	 * token) changes nothing.
	 *
	 * @return void
	 */
	public static function refresh(): void {
		if ( ! self::applies() ) {
			return;
		}
		Cron\Controller::refresh_access_token_if_needed();
		$answer = ( new PluginConnectionRequest() )->read();
		if ( null === $answer || 200 !== (int) $answer['status'] || ! is_array( $answer['body'] ) ) {
			return;
		}
		$scopes = self::of( $answer['body']['scopes'] ?? null );
		if ( null !== $scopes ) {
			self::learn( $scopes );
		}
	}

	/**
	 * The scopes of an answer, checked: the ones the plugin knows, with
	 * wordpress:read, or null when it holds none.
	 *
	 * @param mixed $raw The scopes as the API sent them.
	 *
	 * @return string[]|null
	 */
	public static function of( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$scopes = array_values( array_intersect( self::KNOWN, array_filter( $raw, 'is_string' ) ) );

		return in_array( OAuth::SCOPE_READ, $scopes, true ) ? $scopes : null;
	}

	/**
	 * Keep the scopes the API answered. A change of them was made in the
	 * Scanfully dashboard: the settings page says so, and the Manage clock
	 * follows (it starts with wordpress:manage, and stops without it; a pause
	 * after a refused pull ends when the scope is back).
	 *
	 * @param string[] $scopes The scopes.
	 *
	 * @return void
	 */
	public static function learn( array $scopes ): void {
		if ( self::note_change( $scopes ) ) {
			Controller::store_grant( $scopes );
		}
	}

	/**
	 * Note that the scopes the API answered differ from the plugin's copy:
	 * a change made in the Scanfully dashboard. The caller stores them.
	 *
	 * @param string[] $scopes The scopes.
	 *
	 * @return bool Whether they changed.
	 */
	public static function note_change( array $scopes ): bool {
		$before = Controller::granted_scopes();
		sort( $before );
		$after = $scopes;
		sort( $after );
		if ( $before === $after ) {
			return false;
		}
		update_option( self::CHANGED_OPTION, time(), false );
		if ( in_array( OAuth::SCOPE_MANAGE, $scopes, true ) ) {
			Manage\Controller::resume();
		}

		return true;
	}

	/**
	 * When the scopes last changed in the Scanfully dashboard, or 0.
	 *
	 * @return int
	 */
	public static function changed_at(): int {
		return (int) get_option( self::CHANGED_OPTION, 0 );
	}

	/**
	 * Forget the last change: a new connection has the scopes its admin
	 * approved.
	 *
	 * @return void
	 */
	public static function forget_change(): void {
		delete_option( self::CHANGED_OPTION );
	}
}
