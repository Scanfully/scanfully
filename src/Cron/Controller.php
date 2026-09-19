<?php
/**
 * The cron controller class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Cron;

use Scanfully\Connect;
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
	 * Hook: delete old WooCommerce probe orders (recurring daily).
	 */
	public const ACTION_CLEANUP_PROBE_ORDERS = 'scanfully_woocheckout_cleanup_probe_orders';

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
		add_action( self::ACTION_CLEANUP_PROBE_ORDERS, [ self::class, 'cleanup_woocheckout_probe_orders' ] );

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
	 * Delete old WooCommerce probe orders. Runs on the recurring daily
	 * schedule; a no-op when WooCommerce is inactive.
	 *
	 * @return void
	 */
	public static function cleanup_woocheckout_probe_orders(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		\Scanfully\WooCheckout\Cleanup::run();
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

		if ( ! as_has_scheduled_action( self::ACTION_CLEANUP_PROBE_ORDERS, [], self::AS_GROUP ) ) {
			as_schedule_recurring_action( time(), DAY_IN_SECONDS, self::ACTION_CLEANUP_PROBE_ORDERS, [], self::AS_GROUP );
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
		// Cancel every pending job in the Scanfully group in one go. Cancelling
		// per hook only matches jobs with exactly the given arguments, which
		// missed the event jobs: each one carries its own event data.
		as_unschedule_all_actions( '', [], self::AS_GROUP );
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
	 * Nothing is scheduled when the plugin being changed is Scanfully itself:
	 * after Scanfully is deactivated or deleted, the job could never run.
	 *
	 * @param string $plugin Optional. Basename of the plugin that changed.
	 *
	 * @return void
	 */
	public static function schedule_health_sync( $plugin = '' ): void {
		if ( is_string( $plugin ) && '' !== $plugin && \Scanfully\Main::is_own_plugin( $plugin ) ) {
			return;
		}

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

	/**
	 * Option used as a lock so only one process refreshes the tokens at a time.
	 */
	private const REFRESH_LOCK_OPTION = 'scanfully_refresh_lock';

	/**
	 * Seconds after which a refresh lock is considered abandoned.
	 */
	private const REFRESH_LOCK_TIMEOUT = 2 * MINUTE_IN_SECONDS;

	/**
	 * Refresh the access token when it is about to expire.
	 *
	 * The API invalidates the old refresh token on every refresh, so two
	 * processes refreshing at once would leave one of them storing tokens
	 * that no longer work. A lock makes sure only one process refreshes, and
	 * the options are re-read once the lock is held, so a process that waited
	 * does not refresh again with a refresh token that was just used.
	 *
	 * @return void
	 */
	private static function refresh_access_token_if_needed(): void {
		$options = Options\Controller::get_options();
		if ( ! $options->is_connected || ! self::token_needs_refresh( $options ) ) {
			return;
		}

		if ( ! self::acquire_refresh_lock() ) {
			// another process is refreshing; the current token stays valid until
			// it expires, which is at least two days away.
			return;
		}

		try {
			$options = Options\Controller::get_fresh_options();
			if ( ! $options->is_connected || ! self::token_needs_refresh( $options ) ) {
				return;
			}

			self::refresh_access_token( $options );
		} finally {
			self::release_refresh_lock();
		}
	}

	/**
	 * Whether the access token expires within two days. An expiry date that
	 * cannot be parsed also needs a refresh, which stores a fresh one.
	 *
	 * @param Options\Options $options The current options.
	 *
	 * @return bool
	 */
	private static function token_needs_refresh( Options\Options $options ): bool {
		try {
			$refresh_after = new \DateTime( $options->expires, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return true;
		}
		$refresh_after->modify( '-2 days' );

		return new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) > $refresh_after;
	}

	/**
	 * Request new tokens and store them.
	 *
	 * @param Options\Options $options The current options.
	 *
	 * @return void
	 */
	private static function refresh_access_token( Options\Options $options ): void {
		$tokens = Connect\Controller::refresh_access_token( $options->refresh_token, $options->site_id );

		if ( ! self::is_valid_token_response( $tokens ) ) {
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

		$site_id = isset( $tokens['site_id'] ) && is_string( $tokens['site_id'] ) && '' !== $tokens['site_id']
			? $tokens['site_id']
			: $options->site_id;

		Options\Controller::set_options(
			new Options\Options(
				true,
				$site_id,
				$tokens['access_token'],
				$tokens['refresh_token'],
				$new_expires->format( Connect\Controller::DATE_FORMAT ),
				// A refresh isn't a new connection: keep when the site was
				// connected and when the connection was last used.
				$options->last_used,
				$options->date_connected
			)
		);

		// refresh succeeded, clear any previous failure state.
		self::clear_refresh_failures();
	}

	/**
	 * Whether a token response has everything needed to store new tokens.
	 *
	 * @param array $tokens The decoded token response.
	 *
	 * @return bool
	 */
	private static function is_valid_token_response( array $tokens ): bool {
		foreach ( [ 'access_token', 'refresh_token', 'expires' ] as $key ) {
			if ( ! isset( $tokens[ $key ] ) || ! is_string( $tokens[ $key ] ) || '' === $tokens[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Take the refresh lock.
	 *
	 * Uses INSERT IGNORE on the options table, like WordPress core's upgrader
	 * lock: the unique option name makes the insert atomic, which add_option()
	 * is not. A lock older than the timeout is taken over, with an UPDATE that
	 * only succeeds for one process.
	 *
	 * @return bool Whether this process holds the lock.
	 */
	private static function acquire_refresh_lock(): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The lock must bypass the options cache to be atomic.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				self::REFRESH_LOCK_OPTION,
				(string) time()
			)
		);
		if ( 1 === (int) $inserted ) {
			// phpcs:enable WordPress.DB.DirectDatabaseQuery
			return true;
		}

		$locked_at = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::REFRESH_LOCK_OPTION )
		);
		if ( (int) $locked_at > time() - self::REFRESH_LOCK_TIMEOUT ) {
			// phpcs:enable WordPress.DB.DirectDatabaseQuery
			return false;
		}

		$taken = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				(string) time(),
				self::REFRESH_LOCK_OPTION,
				$locked_at
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return 1 === (int) $taken;
	}

	/**
	 * Release the refresh lock.
	 *
	 * @return void
	 */
	private static function release_refresh_lock(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Counterpart of the atomic lock in acquire_refresh_lock().
		$wpdb->delete( $wpdb->options, [ 'option_name' => self::REFRESH_LOCK_OPTION ] );
	}

	/**
	 * Record a refresh failure. Increments the consecutive failure counter
	 * and stores the error message for display in admin notices.
	 *
	 * @param string $error_message The error to record.
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
