<?php
/**
 * The date utility class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Util;

/**
 * Date helpers.
 */
class Date {

	/**
	 * Get the site's timezone, whether it is set as a city (Europe/Amsterdam)
	 * or as a manual UTC offset (UTC+2).
	 *
	 * @return \DateTimeZone
	 */
	public static function get_timezone(): \DateTimeZone {
		return wp_timezone();
	}
}
