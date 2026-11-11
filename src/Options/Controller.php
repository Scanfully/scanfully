<?php
/**
 * The Controller class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Options;

/**
 * The options class handles everything related to the plugin options.
 *
 * The connection lives in one of two places: the site's own options, or on a
 * multisite network's main site, while the network is connected as a whole,
 * the network's options (site options, see Connect\Network). Every read and
 * write of a connection option goes to the place that applies (the scope),
 * which is decided when the plugin boots and again when WordPress switches
 * to another site.
 */
class Controller {

	/**
	 * The options key
	 *
	 * @var string
	 */
	private static string $db_prefix = 'scanfully_connect_';

	/**
	 * Whether the network connection applies to this request, as detected.
	 *
	 * @var bool
	 */
	private static bool $network = false;

	/**
	 * The scope a caller chose for what it does now, over the detected one;
	 * null when none.
	 *
	 * @var bool|null
	 */
	private static ?bool $forced = null;

	/**
	 * Decide the scope for this request: the network's options on the main
	 * site of a network connected as a whole, the site's own otherwise.
	 *
	 * @return void
	 */
	public static function detect_scope(): void {
		self::$network = \Scanfully\Connect\Network::applies();
	}

	/**
	 * Decide the scope again from the stored state, not from what this
	 * process cached: a long-running process, such as an Action Scheduler
	 * queue runner, may have started before the network was connected or
	 * disconnected.
	 *
	 * @return void
	 */
	public static function refresh_scope(): void {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$network = get_current_network_id();
			wp_cache_delete( $network . ':notoptions', 'site-options' );
			wp_cache_delete( $network . ':' . self::$db_prefix . 'is_connected', 'site-options' );
			wp_cache_delete( $network . ':active_sitewide_plugins', 'site-options' );
		}
		self::detect_scope();
	}

	/**
	 * Whether the connection options are the network's.
	 *
	 * @return bool
	 */
	public static function network_scope(): bool {
		return self::$forced ?? self::$network;
	}

	/**
	 * End a scope a caller forced: a request that exits inside with_scope()
	 * (a redirect) skips its finally, so what runs at shutdown (the activity
	 * log's flushes) would still use it.
	 *
	 * @return void
	 */
	public static function end_forced_scope(): void {
		self::$forced = null;
	}

	/**
	 * Run a callable with the connection options in the given scope: the
	 * network's (true) or the site's own (false).
	 *
	 * @template T
	 *
	 * @param bool          $network Whether to use the network's options.
	 * @param callable(): T $run     What to run.
	 *
	 * @return T
	 */
	public static function with_scope( bool $network, callable $run ) {
		$before       = self::$forced;
		self::$forced = $network;
		try {
			return $run();
		} finally {
			self::$forced = $before;
		}
	}

	/**
	 * Get options helper
	 *
	 * @return Options
	 */
	public static function get_options(): Options {
		return apply_filters(
			'scanfully_options',
			new Options(
				self::get_option( 'is_connected' ) === 'yes',
				self::get_option( 'site_id' ),
				self::get_option( 'access_token' ),
				self::get_option( 'refresh_token' ),
				self::get_option( 'expires' ),
				self::get_option( 'last_used' ),
				self::get_option( 'date_connected' )
			)
		);
	}

	/**
	 * Get the options, re-read from the database instead of the options cache.
	 *
	 * Use this when another process may have changed the options during the
	 * current request, for example a token refresh in a parallel cron run.
	 *
	 * @return Options
	 */
	public static function get_fresh_options(): Options {
		$names = [ 'is_connected', 'site_id', 'access_token', 'refresh_token', 'expires', 'last_used', 'date_connected' ];
		if ( self::network_scope() ) {
			$network = get_current_network_id();
			wp_cache_delete( $network . ':notoptions', 'site-options' );
			foreach ( $names as $name ) {
				wp_cache_delete( $network . ':' . self::$db_prefix . $name, 'site-options' );
			}

			return self::get_options();
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		foreach ( $names as $name ) {
			wp_cache_delete( self::$db_prefix . $name, 'options' );
		}

		return self::get_options();
	}

	/**
	 * WordPress get_option wrapper
	 *
	 * @param  string $name The name of the option.
	 *
	 * @return string
	 */
	public static function get_option( string $name ): string {
		$value = self::network_scope() ? get_site_option( self::$db_prefix . $name ) : get_option( self::$db_prefix . $name );

		return apply_filters( 'scanfully_option', $value );
	}

	/**
	 * Save options to WP options table
	 *
	 * @param  Options $options The options to save.
	 *
	 * @return void
	 */
	public static function set_options( Options $options ): void {
		self::set_option( 'is_connected', $options->is_connected ? 'yes' : 'no' );
		self::set_option( 'site_id', $options->site_id );
		// The tokens are only needed for API calls, so they aren't loaded on
		// every request (and don't end up in the autoloaded options cache).
		self::set_option( 'access_token', $options->access_token, false );
		self::set_option( 'refresh_token', $options->refresh_token, false );
		self::set_option( 'expires', $options->expires );
		self::set_option( 'last_used', $options->last_used );
		self::set_option( 'date_connected', $options->date_connected );
	}

	/**
	 * Set an option
	 *
	 * @param  string $name     The option name (without the Scanfully prefix).
	 * @param  string $value    The string value to store.
	 * @param  bool   $autoload Whether the option should be autoloaded by WordPress.
	 *                          Defaults to true to preserve historical behaviour;
	 *                          large or rarely-read options should pass false.
	 *
	 * @return void
	 */
	public static function set_option( string $name, string $value, bool $autoload = true ): void {
		if ( self::network_scope() ) {
			update_site_option( self::$db_prefix . $name, $value );
			return;
		}
		update_option( self::$db_prefix . $name, $value, $autoload );
	}

	/**
	 * Delete an option in the scope that applies.
	 *
	 * @param string $name The option name (without the Scanfully prefix).
	 *
	 * @return void
	 */
	private static function delete_option( string $name ): void {
		if ( self::network_scope() ) {
			delete_site_option( self::$db_prefix . $name );
			return;
		}
		delete_option( self::$db_prefix . $name );
	}

	/**
	 * Stop autoloading the tokens stored by earlier versions. Runs once;
	 * set_options() stores new tokens without autoload.
	 *
	 * @return void
	 */
	public static function maybe_stop_autoloading_tokens(): void {
		if ( get_option( 'scanfully_tokens_autoload_off' ) ) {
			return;
		}

		foreach ( [ 'access_token', 'refresh_token' ] as $name ) {
			$option = self::$db_prefix . $name;
			if ( function_exists( 'wp_set_option_autoload' ) ) {
				wp_set_option_autoload( $option, false );
			} else {
				// WordPress before 6.4 has no API to change autoload without
				// rewriting the value.
				global $wpdb;
				$wpdb->update( $wpdb->options, [ 'autoload' => 'no' ], [ 'option_name' => $option ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off change of the autoload flag only.
			}
		}
		wp_cache_delete( 'alloptions', 'options' );

		// Autoloaded, so this check costs nothing on later requests.
		update_option( 'scanfully_tokens_autoload_off', 1, true );
	}

	/**
	 * Clear all options
	 *
	 * @return void
	 */
	public static function clear() {
		foreach ( [ 'is_connected', 'site_id', 'access_token', 'refresh_token', 'expires', 'last_used', 'date_connected', 'scopes', 'flow', 'address', 'address_shown' ] as $name ) {
			self::delete_option( $name );
		}
		// written by the API requests on the site that runs them, by its raw name.
		delete_option( self::$db_prefix . 'last_request_error' );
		delete_transient( 'scanfully_oauth_unavailable' );

		// Email deliverability sub-feature: per-connection secret + cached state.
		foreach ( [ 'enabled', 'secret', 'inbound_address', 'interval_seconds', 'last_failure_at', 'last_as_run_at', 'from_address', 'last_api_error', 'last_api_error_at' ] as $name ) {
			self::delete_option( 'email_deliverability_' . $name );
		}

		// WooCheckout probe secret: without this, whoever held the old secret
		// could still sign probe requests after a disconnect. The literal name
		// keeps this working when WooCommerce (and its controller) is absent.
		delete_option( 'scanfully_woocheckout_probe_secret' );

		\Scanfully\Cron\Controller::clear_refresh_failures();

		do_action( 'scanfully_options_cleared' );

		// a cleared network connection no longer applies.
		if ( null === self::$forced ) {
			self::detect_scope();
		}
	}
}
