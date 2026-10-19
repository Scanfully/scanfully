<?php
/**
 * The failed login counter store class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\FailedLogins;

/**
 * Class Store
 *
 * Counters of failed logins per UTC hour, as rows of the options table named
 * `scanfully_lf_<hour>_<counter>`, where the hour counts hours since 1970.
 * A failed login adds 1 to a few counters with one atomic query, so attempts
 * that arrive at the same moment during an attack lose no counts, and no row
 * is read first. The rows are never autoloaded and skip the object cache on
 * purpose: they are only read and removed by the hourly job, with SQL.
 *
 * @package Scanfully\Events\FailedLogins
 */
final class Store {

	/**
	 * Prefix of the counter rows.
	 */
	private const PREFIX = 'scanfully_lf_';

	/**
	 * The hour a time falls in, in hours since 1970 (UTC).
	 *
	 * @param int|null $time A Unix time; now when null.
	 *
	 * @return int
	 */
	public static function hour( ?int $time = null ): int {
		return (int) floor( ( $time ?? time() ) / HOUR_IN_SECONDS );
	}

	/**
	 * Whether a counter name is one this store keeps.
	 *
	 * @param string $counter The counter name.
	 *
	 * @return bool
	 */
	private static function is_counter( string $counter ): bool {
		return 1 === preg_match( '/^[a-z0-9_]{1,40}$/', $counter );
	}

	/**
	 * Add 1 to counters of an hour, in one query.
	 *
	 * @param string[] $counters The counter names.
	 * @param int      $hour     The hour.
	 *
	 * @return bool Whether the query ran.
	 */
	public static function increment( array $counters, int $hour ): bool {
		global $wpdb;

		$values = [];
		$names  = [];
		foreach ( array_unique( $counters ) as $counter ) {
			if ( ! self::is_counter( (string) $counter ) ) {
				continue;
			}
			$values[] = "(%s, '1', 'no')";
			$names[]  = self::PREFIX . $hour . '_' . $counter;
		}
		if ( [] === $names ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- An atomic increment the options API can't do; the placeholders are built from the fixed fragment above.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES " . implode( ', ', $values ) . ' ON DUPLICATE KEY UPDATE option_value = option_value + 1',
				$names
			)
		);
		// phpcs:enable

		return false !== $result;
	}

	/**
	 * The count of one counter in an hour; 0 when there is none.
	 *
	 * @param string $counter The counter name.
	 * @param int    $hour    The hour.
	 *
	 * @return int
	 */
	public static function count( string $counter, int $hour ): int {
		global $wpdb;

		if ( ! self::is_counter( $counter ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The counters skip the object cache on purpose (see the class comment).
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::PREFIX . $hour . '_' . $counter ) );
	}

	/**
	 * The counters of the hours before a given hour, oldest first.
	 *
	 * @param int $before_hour The first hour not to read (the current one).
	 *
	 * @return array<int, array<string, int>> Hour to counter to count.
	 */
	public static function hours_before( int $before_hour ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The counters are found by name prefix, which the options API can't do.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) );

		$hours = [];
		foreach ( (array) $rows as $row ) {
			$name = (string) ( $row->option_name ?? '' );
			if ( 1 !== preg_match( '/^(\d+)_([a-z0-9_]+)$/', substr( $name, strlen( self::PREFIX ) ), $match ) ) {
				continue;
			}
			$hour = (int) $match[1];
			if ( $hour >= $before_hour ) {
				continue;
			}
			$hours[ $hour ][ $match[2] ] = (int) ( $row->option_value ?? 0 );
		}
		ksort( $hours );

		return $hours;
	}

	/**
	 * Remove the counters of an hour.
	 *
	 * @param int $hour The hour.
	 *
	 * @return void
	 */
	public static function delete_hour( int $hour ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The counters are removed by name prefix, which the options API can't do.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX . $hour . '_' ) . '%' ) );
	}

	/**
	 * Remove every counter (when the site disconnects).
	 *
	 * @return void
	 */
	public static function delete_all(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The counters are removed by name prefix, which the options API can't do.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) );
	}
}
