<?php
/**
 * The login attempts class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Login;

/**
 * Counts the sign-in links opened on the site per minute, in two counters.
 * The limit counts only links whose signature checked out against
 * Scanfully's keys: beyond it a link is refused before its claims are read
 * or the burn list is touched. Links refused before their signature checked
 * out (plain HTTP, not a JWT, another key) are counted apart, and that
 * counter only limits how many of them are logged, so junk sent to the site
 * cannot keep real links out. The key set behind the signature check is
 * fetched at most once per few minutes, whatever is sent.
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
	 * The transient counting the links refused before their signature
	 * checked out, for the log's limit.
	 */
	public const UNSIGNED_TRANSIENT = 'scanfully_login_unsigned';

	/**
	 * Count an attempt.
	 *
	 * @param int    $now       The time.
	 * @param string $transient The counter: TRANSIENT or UNSIGNED_TRANSIENT.
	 *
	 * @return int The attempt's number in this minute: above LIMIT it is
	 *             refused, and LIMIT + 1 is the first refused one (the count
	 *             is stored up to there, so a burst writes nothing more and
	 *             every later one reads LIMIT + 2).
	 */
	public static function take( int $now, string $transient = self::TRANSIENT ): int {
		$minute = (int) floor( $now / MINUTE_IN_SECONDS );
		$state  = get_transient( $transient );
		$count  = is_array( $state ) && (int) ( $state[0] ?? -1 ) === $minute ? (int) ( $state[1] ?? 0 ) : 0;
		if ( $count > self::LIMIT ) {
			return $count + 1;
		}
		set_transient( $transient, [ $minute, $count + 1 ], 2 * MINUTE_IN_SECONDS );

		return $count + 1;
	}
}
