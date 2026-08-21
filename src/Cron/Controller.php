<?php
/**
 * The cron controller class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Cron;

use Scanfully\Connect;
use Scanfully\Events;
use Scanfully\Health;
use Scanfully\Options;

/**
 * Class Controller
 */
class Controller {

	/**
	 * Hook: sync site health data (recurring every 3h + debounced single runs
	 * after plugin install/activate/deactivate/update).
	 */
	public const ACTION_SYNC_SITE_HEALTH = 'scanfully_sync_site_health';

	/**
	 * Hook: sync directory sizes (recurring daily).
	 */
	public const ACTION_SYNC_DIRECTORIES = 'scanfully_sync_directories';

	/**
	 * Hook: per-site email deliverability ping (recurring; cadence is
	 * computed by EmailHealth\Controller::current_interval_seconds()).
	 */
	public const ACTION_EMAIL_DELIVERABILITY_PING = 'scanfully_email_deliverability_ping';

	/**
	 * Hook: sync WooCommerce checkout (probe) config (recurring daily).
	 */
	public const ACTION_SYNC_WOOCHECKOUT_CONFIG = 'scanfully_sync_woocheckout_config';

	/**
	 * Args marker for debounced (single) site health runs, to distinguish them
	 * from the recurring schedule so each can be managed independently.
	 */
	private const DEBOUNCED_ARGS = [ 'trigger' => 'debounced' ];

	/**
	 * Option flag marking that legacy WP-Cron hooks have been cleaned up.
	 */
	private const OPTION_HOOKS_MIGRATED = 'scanfully_as_hooks_migrated_v3';

	/**
	 * Action Scheduler group for all Scanfully jobs.
	 */
	private const AS_GROUP = 'scanfully';

	/**
	 * Grace period in seconds before the debounced site health sync fires.
	 * Each new triggering action resets this delay.
	 */
	private const HEALTH_SYNC_DELAY = 60;

	/**
	 * Register cron callbacks, triggers and scheduling.
	 *
	 * @return void
	 */
	public static function setup(): void {
		// Register Action Scheduler callbacks.
		add_action( self::ACTION_SYNC_SITE_HEALTH, [ self::class, 'sync_site_health' ] );
		add_action( self::ACTION_SYNC_DIRECTORIES, [ self::class, 'sync_directories' ] );
		add_action( self::ACTION_SYNC_WOOCHECKOUT_CONFIG, [ self::class, 'sync_woocheckout_config' ] );

		// Register hooks that trigger a debounced site health sync.
		self::register_health_sync_hooks();

		// Email deliverability sub-feature registers its own AS callback +
		// admin panel hooks.
		\Scanfully\EmailHealth\Controller::register();

		// Cancel email-deliverability schedule on disconnect.
		add_action( 'scanfully_options_cleared', [ self::class, 'clear_email_deliverability_schedule' ] );

		// Schedule recurring events once Action Scheduler has initialised its data store.
		add_action( 'action_scheduler_init', [ self::class, 'schedule_events' ] );
	}

	/**
	 * Sync site health data.
	 * Runs on the recurring 3-hour schedule AND as a debounced single action after
	 * plugin install/activate/deactivate/update. Refreshes the access token if needed.
	 *
	 * @return void
	 */
	public static function sync_site_health(): void {
		self::refresh_access_token_if_needed();

		$options = Options\Controller::get_options();
		if ( $options->is_connected ) {
			Health\Controller::send_site_data();
		}
	}

	/**
	 * Sync directory size data. Runs on the recurring daily schedule.
	 * Refreshes the access token if needed.
	 *
	 * @return void
	 */
	public static function sync_directories(): void {
		self::refresh_access_token_if_needed();

		$options = Options\Controller::get_options();
		if ( $options->is_connected ) {
			Health\Controller::send_directories_data();
		}
	}

	/**
	 * Sync WooCommerce checkout probe config. Runs on the recurring daily
	 * schedule, also when WooCommerce is inactive: the report then carries
	 * the `wc_inactive` disabled reason so the API knows scanning is off.
	 *
	 * @return void
	 */
	public static function sync_woocheckout_config(): void {
		self::refresh_access_token_if_needed();

		$options = Options\Controller::get_options();
		if ( ! $options->is_connected ) {
			return;
		}
		if ( ! class_exists( '\\Scanfully\\WooCheckout\\Controller' ) ) {
			return;
		}
		\Scanfully\WooCheckout\Controller::report();
	}

	/**
	 * Schedule recurring events if not already scheduled, and run one-time
	 * cleanup of legacy hook names from older plugin versions.
	 * Must run after Action Scheduler is initialised (action_scheduler_init or later).
	 *
	 * @return void
	 */
	public static function schedule_events(): void {
		self::migrate_legacy_hooks();

		if ( ! as_has_scheduled_action( self::ACTION_SYNC_SITE_HEALTH, [], self::AS_GROUP ) ) {
			as_schedule_recurring_action( time(), 3 * HOUR_IN_SECONDS, self::ACTION_SYNC_SITE_HEALTH, [], self::AS_GROUP );
		}

		if ( ! as_has_scheduled_action( self::ACTION_SYNC_DIRECTORIES, [], self::AS_GROUP ) ) {
			as_schedule_recurring_action( time(), DAY_IN_SECONDS, self::ACTION_SYNC_DIRECTORIES, [], self::AS_GROUP );
		}

		// Email deliverability runs as a self-scheduling single action (each run
		// arms the next via EmailHealth\Controller::schedule_next_ping) so the
		// cadence can adapt without the duplicate pending actions a recurring
		// action produced. This only bootstraps the chain, or heals it if it stalls.
		if ( ! as_has_scheduled_action( self::ACTION_EMAIL_DELIVERABILITY_PING, [], self::AS_GROUP ) ) {
			$interval = \Scanfully\EmailHealth\Controller::current_interval_seconds();
			as_schedule_single_action( time() + $interval, self::ACTION_EMAIL_DELIVERABILITY_PING, [], self::AS_GROUP );
		}

		if ( ! as_has_scheduled_action( self::ACTION_SYNC_WOOCHECKOUT_CONFIG, [], self::AS_GROUP ) ) {
			as_schedule_recurring_action( time(), DAY_IN_SECONDS, self::ACTION_SYNC_WOOCHECKOUT_CONFIG, [], self::AS_GROUP );
		}
	}

	/**
	 * Cancel all scheduled email-deliverability actions. Called from the
	 * scanfully_options_cleared hook on disconnect.
	 *
	 * @return void
	 */
	public static function clear_email_deliverability_schedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_EMAIL_DELIVERABILITY_PING, [], self::AS_GROUP );
			as_unschedule_all_actions( self::ACTION_EMAIL_DELIVERABILITY_PING, [ 'source' => 'manual' ], self::AS_GROUP );
		}
	}

	/**
	 * One-time cleanup of legacy WP-Cron events from the pre-Action-Scheduler release.
	 *
	 * @return void
	 */
	private static function migrate_legacy_hooks(): void {
		if ( get_option( self::OPTION_HOOKS_MIGRATED ) ) {
			return;
		}

		// Clear WP-Cron hooks scheduled by prior plugin versions.
		wp_clear_scheduled_hook( 'scanfully_twice_daily' );
		wp_clear_scheduled_hook( 'scanfully_daily' );

		// The deliverability ping moved from a recurring action to a
		// self-scheduling single action. Older versions paired a recurring action
		// with a per-cycle reschedule, which left duplicate pending actions. Clear
		// any legacy instances so schedule_events() rebuilds a single clean chain.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_EMAIL_DELIVERABILITY_PING, [], self::AS_GROUP );
		}

		update_option( self::OPTION_HOOKS_MIGRATED, 1, false );
	}

	/**
	 * Unschedule all Scanfully Action Scheduler jobs.
	 *
	 * @return void
	 */
	public static function clear_scheduled_events(): void {
		as_unschedule_all_actions( self::ACTION_SYNC_SITE_HEALTH, [], self::AS_GROUP );
		as_unschedule_all_actions( self::ACTION_SYNC_SITE_HEALTH, self::DEBOUNCED_ARGS, self::AS_GROUP );
		as_unschedule_all_actions( self::ACTION_SYNC_DIRECTORIES, [], self::AS_GROUP );
		as_unschedule_all_actions( self::ACTION_EMAIL_DELIVERABILITY_PING, [], self::AS_GROUP );
		as_unschedule_all_actions( self::ACTION_EMAIL_DELIVERABILITY_PING, [ 'source' => 'manual' ], self::AS_GROUP );
		as_unschedule_all_actions( self::ACTION_SYNC_WOOCHECKOUT_CONFIG, [], self::AS_GROUP );
		as_unschedule_all_actions( Events\Controller::ACTION_SEND_EVENT, [], self::AS_GROUP );
	}

	/**
	 * Register WordPress hooks that trigger a debounced site health sync.
	 *
	 * @return void
	 */
	private static function register_health_sync_hooks(): void {
		add_action( 'activated_plugin', [ self::class, 'schedule_health_sync' ] );
		add_action( 'deactivated_plugin', [ self::class, 'schedule_health_sync' ] );
		add_action( 'deleted_plugin', [ self::class, 'schedule_health_sync' ] );
		add_action( 'upgrader_process_complete', [ self::class, 'handle_upgrader_complete' ], 10, 2 );
	}

	/**
	 * Schedule a debounced site health sync.
	 *
	 * Cancels any previously scheduled debounced sync and reschedules with a fresh
	 * grace period so rapid or bulk plugin actions collapse into a single sync.
	 * The recurring schedule is untouched because it uses different args.
	 *
	 * @return void
	 */
	public static function schedule_health_sync(): void {
		as_unschedule_all_actions( self::ACTION_SYNC_SITE_HEALTH, self::DEBOUNCED_ARGS, self::AS_GROUP );
		as_schedule_single_action(
			time() + self::HEALTH_SYNC_DELAY,
			self::ACTION_SYNC_SITE_HEALTH,
			self::DEBOUNCED_ARGS,
			self::AS_GROUP
		);
	}

	/**
	 * Handle the upgrader_process_complete action.
	 * Only schedules a debounced site health sync for plugin installs and updates.
	 *
	 * @param \WP_Upgrader $upgrader The upgrader instance.
	 * @param array        $hook_extra Extra arguments passed by the upgrader.
	 *
	 * @return void
	 */
	public static function handle_upgrader_complete( $upgrader, array $hook_extra ): void {
		if ( isset( $hook_extra['type'] ) && 'plugin' === $hook_extra['type'] ) {
			self::schedule_health_sync();
		}
	}

	/**
	 * Refresh the access token if needed
	 *
	 * @return void
	 */
	/**
	 * Transient key for tracking consecutive refresh failures.
	 */
	private const TRANSIENT_REFRESH_FAILURES = 'scanfully_refresh_failures';

	/**
	 * Transient key for storing the last refresh error message.
	 */
	private const TRANSIENT_REFRESH_ERROR = 'scanfully_refresh_error';

	/**
	 * Number of consecutive failures before the connection is considered broken.
	 */
	private const MAX_REFRESH_FAILURES = 3;

	private static function refresh_access_token_if_needed(): void {

		// get options
		$options = Options\Controller::get_options();

		// check if we're connected, if not return
		if ( ! $options->is_connected ) {
			return;
		}

		try {
			$now = new \DateTime();
			$now->setTimezone( new \DateTimeZone( 'UTC' ) );

			$expires = new \DateTime( $options->expires );
			$expires->setTimezone( new \DateTimeZone( 'UTC' ) );
			$expires->modify( '-2 days' );
		} catch ( \Exception $e ) {
			self::record_refresh_failure( 'Failed to parse token expiry date: ' . $e->getMessage() );
			return;
		}

		// check if the access token needs refreshing
		if ( $now <= $expires ) {
			return;
		}

		// refresh the access token
		$tokens = Connect\Controller::refresh_access_token( $options->refresh_token, $options->site_id );

		// check if we got tokens
		if ( empty( $tokens ) ) {
			self::record_refresh_failure( 'Token refresh request failed. The Scanfully API may be unreachable or the refresh token may be invalid.' );
			return;
		}

		try {
			$new_expires = new \DateTime( $tokens['expires'] );
			$new_expires->setTimezone( new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			self::record_refresh_failure( 'Failed to parse new token expiry date: ' . $e->getMessage() );
			return;
		}

		// update the options
		$options = new Options\Options(
			true,
			$tokens['site_id'],
			$tokens['access_token'],
			$tokens['refresh_token'],
			$new_expires->format( Connect\Controller::DATE_FORMAT ),
			'',
			$now->format( Connect\Controller::DATE_FORMAT )
		);

		// save options
		Options\Controller::set_options( $options );

		// refresh succeeded, clear any previous failure state
		self::clear_refresh_failures();
	}

	/**
	 * Record a refresh failure. Increments the consecutive failure counter
	 * and stores the error message for display in admin notices.
	 *
	 * @param string $error_message
	 *
	 * @return void
	 */
	private static function record_refresh_failure( string $error_message ): void {
		$failures = (int) get_transient( self::TRANSIENT_REFRESH_FAILURES );
		++$failures;

		// Store for 1 week so it survives between cron runs.
		set_transient( self::TRANSIENT_REFRESH_FAILURES, $failures, WEEK_IN_SECONDS );
		set_transient( self::TRANSIENT_REFRESH_ERROR, $error_message, WEEK_IN_SECONDS );
	}

	/**
	 * Clear all refresh failure tracking state.
	 *
	 * @return void
	 */
	public static function clear_refresh_failures(): void {
		delete_transient( self::TRANSIENT_REFRESH_FAILURES );
		delete_transient( self::TRANSIENT_REFRESH_ERROR );
	}

	/**
	 * Check if the refresh failure threshold has been reached.
	 *
	 * @return bool
	 */
	public static function has_refresh_failure(): bool {
		return (int) get_transient( self::TRANSIENT_REFRESH_FAILURES ) >= self::MAX_REFRESH_FAILURES;
	}

	/**
	 * Get the last recorded refresh error message.
	 *
	 * @return string
	 */
	public static function get_refresh_error(): string {
		return (string) get_transient( self::TRANSIENT_REFRESH_ERROR );
	}
}
