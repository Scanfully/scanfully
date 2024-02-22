<?php

namespace Scanfully\Util;

class Date {

	/**
	 * Get the current date time zone
	 *
	 * @return \DateTimeZone
	 * @throws \Exception
	 */
	public static function get_timezone(): \DateTimeZone {
		$tz_string = get_option( 'timezone_string', 'UTC' );
		if ( $tz_string === '' ) {
			$tz_string = 'UTC';
		}

		return new \DateTimeZone( $tz_string );
	}

}