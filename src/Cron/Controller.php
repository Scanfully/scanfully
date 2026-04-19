<?php

namespace Scanfully\Cron;

use Scanfully\Connect;
use Scanfully\Health;
use Scanfully\Options;

class Controller {

	public const ACTION_THREE_HOURLY = 'scanfully_three_hourly';
	public const ACTION_DAILY = 'scanfully_daily';

	/**
	 * Legacy action constant, used only to clear old schedules on update.
	 */
	private const ACTION_TWICE_DAILY_LEGACY = 'scanfully_twice_daily';

	/**
	 *
	 *
	 * @return void
	 */
	public static function setup(): void {
		// register custom cron schedules
		add_filter( 'cron_schedules', [ self::class, 'add_cron_schedules' ] );

		// cron 'callbacks'
		add_action( self::ACTION_THREE_HOURLY, [ self::class, 'three_hourly' ] );
		add_action( self::ACTION_DAILY, [ self::class, 'daily' ] );

		// clear legacy schedules and schedule events
		self::clear_legacy_schedules();
		self::schedule_events();
	}

	/**
	 * Register custom cron schedules
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public static function add_cron_schedules( array $schedules ): array {
		$schedules['every_three_hours'] = [
			'interval' => 3 * HOUR_IN_SECONDS,
			'display'  => 'Every 3 Hours',
		];

		return $schedules;
	}

	/**
	 * Daily cron function
	 *
	 * @return void
	 */
	public static function daily(): void {
		// check if we need to refresh the access token
		self::refresh_access_token_if_needed();

		// get options
		$options = Options\Controller::get_options();

		// connected only actions
		if ( $options->is_connected ) {
			// send directory data daily
			Health\Controller::send_directories_data();
		}
	}

	/**
	 * Daily cron function
	 *
	 * @return void
	 */
	public static function three_hourly(): void {
		// check if we need to refresh the access token
		self::refresh_access_token_if_needed();

		// get options
		$options = Options\Controller::get_options();

		// connected only actions
		if ( $options->is_connected ) {
			// send site data every 3 hours
			Health\Controller::send_site_data();
		}

	}

	/**
	 * Schedule events
	 *
	 * @return void
	 */
	private static function schedule_events(): void {
		if ( ! wp_next_scheduled( self::ACTION_THREE_HOURLY ) ) {
			wp_schedule_event( time(), 'every_three_hours', self::ACTION_THREE_HOURLY );
		}

		if ( ! wp_next_scheduled( self::ACTION_DAILY ) ) {
			wp_schedule_event( time(), 'daily', self::ACTION_DAILY );
		}
	}

	/**
	 * Clear legacy schedules from older plugin versions
	 *
	 * @return void
	 */
	private static function clear_legacy_schedules(): void {
		wp_clear_scheduled_hook( self::ACTION_TWICE_DAILY_LEGACY );
	}

	/**
	 * Clear all scheduled events
	 *
	 * @return void
	 */
	public static function clear_scheduled_events(): void {
		wp_clear_scheduled_hook( self::ACTION_DAILY );
		wp_clear_scheduled_hook( self::ACTION_THREE_HOURLY );
		wp_clear_scheduled_hook( self::ACTION_TWICE_DAILY_LEGACY );
	}

	/**
	 * Refresh the access token if needed
	 *
	 * @return void
	 */
	private static function refresh_access_token_if_needed(): void {

		// get options
		$options = Options\Controller::get_options();

		// check if we're connected, if not return
		if ( ! $options->is_connected ) {
			return;
		}

		try {
			// create a time object for now
			$now = new \DateTime();
			$now->setTimezone( new \DateTimeZone( 'UTC' ) );

			// create time object for expires
			$expires = new \DateTime( $options->expires );
			$expires->setTimezone( new \DateTimeZone( 'UTC' ) );
			$expires->modify( '-2 days' );


			// check if the access token is expired
			if ( $now > $expires ) {
				// refresh the access token
				$tokens = Connect\Controller::refresh_access_token( $options->refresh_token, $options->site_id );

				// check if we got tokens
				if ( empty( $tokens ) ) {
					return;
				}
				// create a new expires time object
				$new_expires = new \DateTime( $tokens['expires'] );
				$new_expires->setTimezone( new \DateTimeZone( 'UTC' ) );

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
			}
		} catch ( \Exception $e ) {
			// handle the exception
		}

	}

}