<?php
/**
 * The login attempts class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Login;

/**
 * Counts the sign-in links opened on the site per minute. Beyond the limit a
 * link is refused before any verification work (no key set fetched, no
 * signature checked, no burn list read), so a burst of links costs the site
 * one transient read each.
 */
final class Attempts {

	/**
	 * The most sign-in links checked per minute.
	 */
	public const LIMIT = 10;

	/**
	 * The transient holding the current minute and its count.
	 */
	public const TRANSIENT = 'scanfully_login_attempts';

	/**
	 * Count an attempt.
	 *
	 * @param int $now The time.
	 *
	 * @return int The attempt's number in this minute: above LIMIT it is
	 *             refused, and LIMIT + 1 is the first refused one (the count
	 *             is stored up to there, so a burst writes nothing more and
	 *             every later one reads LIMIT + 2).
	 */
	public static function take( int $now ): int {
		$minute = (int) floor( $now / MINUTE_IN_SECONDS );
		$state  = get_transient( self::TRANSIENT );
		$count  = is_array( $state ) && (int) ( $state[0] ?? -1 ) === $minute ? (int) ( $state[1] ?? 0 ) : 0;
		if ( $count > self::LIMIT ) {
			return $count + 1;
		}
		set_transient( self::TRANSIENT, [ $minute, $count + 1 ], 2 * MINUTE_IN_SECONDS );

		return $count + 1;
	}
}
