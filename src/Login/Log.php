<?php
/**
 * The login log class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Login;

/**
 * The plugin's record of refused sign-in links: the time and the reason (one
 * of the Controller's reason codes), never anything of the token. It keeps
 * the last KEEP in an option that is not autoloaded, writes them to the PHP
 * error log while WP_DEBUG_LOG is on, as the plugin's other logs do, and
 * fires scanfully_login_refused so a site can pass them to its own logger.
 * The rate limits bound it: per minute, at most one line per signed link
 * below the limit and one for a burst beyond it, and at most LIMIT lines
 * for links refused before the rate limit (not Scanfully's, or not meant
 * for this site at this address) and one for a burst of those.
 */
final class Log {

	/**
	 * The option holding the last refusals.
	 */
	public const OPTION = 'scanfully_login_refusals';

	/**
	 * How many refusals the option keeps.
	 */
	public const KEEP = 20;

	/**
	 * Record a refused sign-in link.
	 *
	 * @param string $reason The reason code.
	 * @param int    $now    The time.
	 *
	 * @return void
	 */
	public static function refused( string $reason, int $now ): void {
		$list   = get_option( self::OPTION, [] );
		$list   = is_array( $list ) ? array_values( $list ) : [];
		$list[] = [
			'time'   => $now,
			'reason' => $reason,
		];
		update_option( self::OPTION, array_slice( $list, -self::KEEP ), false );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The plugin's debug log, as the email checks write theirs.
			error_log( '[scanfully:login] refused a sign-in link: ' . $reason );
		}

		/**
		 * Fires when the plugin refused a sign-in link from Scanfully.
		 *
		 * @param string $reason The reason code, such as expired or replayed.
		 */
		do_action( 'scanfully_login_refused', $reason );
	}

	/**
	 * The last refusals, oldest first.
	 *
	 * @return array<int, array{time: int, reason: string}>
	 */
	public static function recent(): array {
		$list = get_option( self::OPTION, [] );

		return is_array( $list ) ? array_values( $list ) : [];
	}
}
