<?php
/**
 * The options class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Options;

/**
 * The options class handles everything related to the plugin options.
 */
class Options {

	/**
	 * The options key
	 *
	 * @var string
	 */
	private static $key = 'scanfully_connect';

	/**
	 * Get options helper
	 *
	 * @return array
	 */
	public static function get_options(): array {
		return apply_filters(
			'scanfully_options',
			[
				'is_connected'   => self::get_option( 'is_connected' ),
				'site_id'        => self::get_option( 'site_id' ),
				'access_token'   => self::get_option( 'access_token' ),
				'refresh_token'  => self::get_option( 'refresh_token' ),
				'expires'        => self::get_option( 'expires' ),
				'last_used'      => self::get_option( 'last_used' ),
				'date_connected' => self::get_option( 'date_connected' ),
			]
		);
	}

	/**
	 * WordPress get_option wrapper
	 *
	 * @param  string $name The name of the option.
	 *
	 * @return string
	 */
	public static function get_option( string $name ): string {
		return apply_filters( 'scanfully_option', get_option( self::$key . '_' . $name, '' ) );
	}
}