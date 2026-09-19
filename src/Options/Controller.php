<?php
/**
 * The Controller class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Options;

/**
 * The options class handles everything related to the plugin options.
 */
class Controller {

	/**
	 * The options key
	 *
	 * @var string
	 */
	private static string $db_prefix = 'scanfully_connect_';

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
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		foreach ( [ 'is_connected', 'site_id', 'access_token', 'refresh_token', 'expires', 'last_used', 'date_connected' ] as $name ) {
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
		return apply_filters( 'scanfully_option', get_option( self::$db_prefix . $name ) );
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
		update_option( self::$db_prefix . $name, $value, $autoload );
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
		delete_option( self::$db_prefix . 'is_connected' );
		delete_option( self::$db_prefix . 'site_id' );
		delete_option( self::$db_prefix . 'access_token' );
		delete_option( self::$db_prefix . 'refresh_token' );
		delete_option( self::$db_prefix . 'expires' );
		delete_option( self::$db_prefix . 'last_used' );
		delete_option( self::$db_prefix . 'date_connected' );

		// Email deliverability sub-feature: per-site secret + cached state.
		delete_option( self::$db_prefix . 'email_deliverability_enabled' );
		delete_option( self::$db_prefix . 'email_deliverability_secret' );
		delete_option( self::$db_prefix . 'email_deliverability_inbound_address' );
		delete_option( self::$db_prefix . 'email_deliverability_interval_seconds' );
		delete_option( self::$db_prefix . 'email_deliverability_last_failure_at' );
		delete_option( self::$db_prefix . 'email_deliverability_last_as_run_at' );
		delete_option( self::$db_prefix . 'email_deliverability_from_address' );
		delete_option( self::$db_prefix . 'email_deliverability_last_api_error' );
		delete_option( self::$db_prefix . 'email_deliverability_last_api_error_at' );

		// WooCheckout probe secret: without this, whoever held the old secret
		// could still sign probe requests after a disconnect. The literal name
		// keeps this working when WooCommerce (and its controller) is absent.
		delete_option( 'scanfully_woocheckout_probe_secret' );

		\Scanfully\Cron\Controller::clear_refresh_failures();

		do_action( 'scanfully_options_cleared' );
	}
}
