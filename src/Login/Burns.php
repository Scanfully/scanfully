<?php
/**
 * The login burn list class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Login;

/**
 * The sign-in links already used: the jti of every login token the plugin
 * has seen, with its expiry, so a token works once. One option, read and
 * written past the options cache with a compare-and-swap (as the Manage run
 * lock does), so two requests with the same token at the same moment cannot
 * both get in: the second one's write fails, it reads the list again and
 * finds the jti.
 *
 * The list is pruned on every login (an entry goes once its token could no
 * longer pass the expiry check) and bounded: when it is full of tokens that
 * have not expired yet, which the rate limit makes impossible in normal use,
 * a new token is refused rather than an old one forgotten.
 */
final class Burns {

	/**
	 * The option holding the list: a JSON object of jti hash to expiry.
	 */
	public const OPTION = 'scanfully_login_burned';

	/**
	 * The most entries the list keeps.
	 */
	public const MAX = 500;

	/**
	 * The token was not seen before and is burned now.
	 */
	public const BURNED = 'burned';

	/**
	 * The token was seen before.
	 */
	public const SEEN = 'seen';

	/**
	 * The list is full of tokens that have not expired.
	 */
	public const FULL = 'full';

	/**
	 * The list kept changing under the write; nothing was burned.
	 */
	public const BUSY = 'busy';

	/**
	 * How often a write that lost the race is tried again.
	 */
	private const TRIES = 3;

	/**
	 * Burn a token's jti on first sight.
	 *
	 * @param string $jti     The token's jti.
	 * @param int    $expires The token's exp.
	 * @param int    $now     The time.
	 *
	 * @return string One of BURNED, SEEN, FULL, BUSY.
	 */
	public static function burn( string $jti, int $expires, int $now ): string {
		$key = hash( 'sha256', $jti );
		for ( $try = 0; $try < self::TRIES; $try++ ) {
			$raw  = self::read();
			$list = self::prune( self::decode( $raw ), $now );
			if ( isset( $list[ $key ] ) ) {
				return self::SEEN;
			}
			if ( count( $list ) >= self::MAX ) {
				return self::FULL;
			}
			$list[ $key ] = $expires;
			$encoded      = (string) wp_json_encode( $list );
			if ( null === $raw ? self::insert( $encoded ) : self::swap( $raw, $encoded ) ) {
				return self::BURNED;
			}
		}

		return self::BUSY;
	}

	/**
	 * The entries a token could still pass the expiry check with: those
	 * whose exp, with the clock skew, has not passed.
	 *
	 * @param array<string, int> $list The list.
	 * @param int                $now  The time.
	 *
	 * @return array<string, int>
	 */
	public static function prune( array $list, int $now ): array {
		return array_filter(
			$list,
			static function ( int $expires ) use ( $now ): bool {
				return $expires + Controller::SKEW >= $now;
			}
		);
	}

	/**
	 * The list as stored.
	 *
	 * @param string|null $raw The option's value.
	 *
	 * @return array<string, int>
	 */
	private static function decode( ?string $raw ): array {
		$decoded = null === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}
		$list = [];
		foreach ( $decoded as $key => $expires ) {
			if ( is_string( $key ) && is_int( $expires ) ) {
				$list[ $key ] = $expires;
			}
		}

		return $list;
	}

	/**
	 * The option's value, read past the options cache.
	 *
	 * @return string|null Null when there is no list yet.
	 */
	private static function read(): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read past the options cache, as the burn writes past it.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );

		return is_string( $raw ) ? $raw : null;
	}

	/**
	 * Store the first list, unless another request stored one first.
	 *
	 * @param string $value The list.
	 *
	 * @return bool
	 */
	private static function insert( string $value ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- An atomic first write must bypass the options cache.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				self::OPTION,
				$value
			)
		);

		return 1 === (int) $inserted;
	}

	/**
	 * Replace the list, unless another request changed it since it was read.
	 *
	 * @param string $from The list as read.
	 * @param string $to   The new list.
	 *
	 * @return bool
	 */
	private static function swap( string $from, string $to ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A compare-and-swap must bypass the options cache.
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$to,
				self::OPTION,
				$from
			)
		);

		return 1 === (int) $changed;
	}
}
