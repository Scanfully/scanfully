<?php
/**
 * The PHP error store class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Errors;

use Scanfully\Connect\Target;

/**
 * Class Store
 *
 * The errors that are not sent yet, as rows of the options table named
 * `scanfully_pe_<key>_<field>`, where the key is the fingerprint and a short
 * hash of the source's version:
 *
 * - `_n`: how often it happened since it was last sent;
 * - `_l`: when it last happened (a Unix time);
 * - `_s`: the error as it is sent, from its first occurrence, with when that was.
 *
 * And two counters per UTC hour: `scanfully_pe_h_<hour>_n`, the new errors
 * kept that hour, and `scanfully_pe_o_<hour>_n`, the new errors dropped
 * because the hour was over its cap.
 *
 * A request adds what it saw with one atomic query at shutdown, like the
 * failed login counters: counts are added, the last time is the latest, and
 * the first sample stays. The rows are never autoloaded and skip the object
 * cache on purpose: only the sender reads them.
 *
 * @package Scanfully\Errors
 */
final class Store {

	/**
	 * Prefix of the rows.
	 */
	public const PREFIX = 'scanfully_pe_';

	/**
	 * Option with the errors the API got recently: key to when it was sent.
	 * Per target (Target::option()): the own connection's, and `_network`
	 * for a monitored subsite's child site.
	 */
	public const KNOWN_OPTION = 'scanfully_php_errors_known';

	/**
	 * Option with what one target of a site with two already got of the
	 * counts that are still stored, because the other did not take them
	 * yet: key (or `o:<hour>` for an hour's overflow) to count. The store
	 * gives up a count once every target has it. Per target.
	 */
	public const SENT_OPTION = 'scanfully_php_errors_sent';

	/**
	 * Option name prefix of the direct send locks: one per fingerprint.
	 */
	public const LOCK_PREFIX = 'scanfully_pe_lock_';

	/**
	 * How long an error counts as known after it was sent.
	 */
	public const KNOWN_FOR = DAY_IN_SECONDS;

	/**
	 * The most errors remembered as known.
	 */
	public const MAX_KNOWN = 500;

	/**
	 * The most new errors kept per hour; more are counted as overflow.
	 */
	public const MAX_NEW_PER_HOUR = 50;

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
	 * The store key of an error: its fingerprint and a short hash of the
	 * version of its source.
	 *
	 * @param array<string, mixed> $error The built error.
	 *
	 * @return string
	 */
	public static function key( array $error ): string {
		$version = (string) ( $error['source']['version'] ?? '' );

		return (string) ( $error['fingerprint'] ?? '' ) . '_' . substr( md5( $version ), 0, 8 );
	}

	/**
	 * Whether a key is one this store makes.
	 *
	 * @param string $key The key.
	 *
	 * @return bool
	 */
	public static function is_key( string $key ): bool {
		return 1 === preg_match( '/^[0-9a-f]{40}_[0-9a-f]{8}$/', $key );
	}

	/**
	 * Whether the database can take a query: WordPress connected to it.
	 *
	 * @return bool
	 */
	public static function database_ready(): bool {
		global $wpdb;

		return isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->ready ) && ! empty( $wpdb->options );
	}

	/**
	 * The errors the API got recently: key to when it was sent.
	 *
	 * @param Target|null $target The target; null for the own connection.
	 *
	 * @return array<string, int>
	 */
	public static function known( ?Target $target = null ): array {
		$known = get_option( null !== $target ? $target->option( self::KNOWN_OPTION ) : self::KNOWN_OPTION, [] );

		return is_array( $known ) ? array_map( 'intval', $known ) : [];
	}

	/**
	 * The errors every one of some targets got recently: key to the time
	 * the last of them got it. An error one of them does not know yet is
	 * new.
	 *
	 * @param Target[] $targets The targets.
	 *
	 * @return array<string, int>
	 */
	public static function known_by_all( array $targets ): array {
		$all = null;
		foreach ( $targets as $target ) {
			$known = self::known( $target );
			if ( null === $all ) {
				$all = $known;
				continue;
			}
			foreach ( $all as $key => $sent ) {
				if ( ! isset( $known[ $key ] ) ) {
					unset( $all[ $key ] );
				} else {
					$all[ $key ] = min( $sent, $known[ $key ] );
				}
			}
		}

		return $all ?? [];
	}

	/**
	 * Remember that the API got errors.
	 *
	 * @param string[]    $keys   The keys sent.
	 * @param int         $now    The time they were sent.
	 * @param Target|null $target The target that got them; null for the own connection.
	 *
	 * @return void
	 */
	public static function mark_known( array $keys, int $now, ?Target $target = null ): void {
		$known = self::known( $target );
		foreach ( $keys as $key ) {
			$known[ $key ] = $now;
		}
		$known = array_filter(
			$known,
			static function ( int $sent ) use ( $now ): bool {
				return $sent > $now - self::KNOWN_FOR;
			}
		);
		arsort( $known );
		update_option( null !== $target ? $target->option( self::KNOWN_OPTION ) : self::KNOWN_OPTION, array_slice( $known, 0, self::MAX_KNOWN, true ), false );
	}

	/**
	 * What a target already got of the stored counts (see SENT_OPTION).
	 *
	 * @param Target $target The target.
	 *
	 * @return array<string, int>
	 */
	public static function sent( Target $target ): array {
		$sent = get_option( $target->option( self::SENT_OPTION ), [] );

		return is_array( $sent ) ? array_filter( array_map( 'intval', $sent ), static fn( int $count ): bool => $count > 0 ) : [];
	}

	/**
	 * Keep what a target already got of the stored counts; nothing kept is
	 * removed.
	 *
	 * @param Target             $target  The target.
	 * @param array<string, int> $sent    Count by key.
	 * @param bool               $existed Whether the target had a stored value before (saves a query when it had none and has none).
	 *
	 * @return void
	 */
	public static function save_sent( Target $target, array $sent, bool $existed = true ): void {
		$sent = array_filter( $sent, static fn( int $count ): bool => $count > 0 );
		if ( [] === $sent ) {
			if ( $existed ) {
				delete_option( $target->option( self::SENT_OPTION ) );
			}
			return;
		}
		update_option( $target->option( self::SENT_OPTION ), $sent, false );
	}

	/**
	 * Of some keys, the ones with a sample waiting to be sent.
	 *
	 * @param string[] $keys The keys.
	 *
	 * @return string[]
	 */
	public static function pending( array $keys ): array {
		global $wpdb;

		$names = [];
		foreach ( $keys as $key ) {
			if ( self::is_key( $key ) ) {
				$names[] = self::PREFIX . $key . '_s';
			}
		}
		if ( [] === $names ) {
			return [];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The rows skip the object cache on purpose (see the class comment); the placeholders are built from the names above.
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name IN (" . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ')',
				$names
			)
		);
		// phpcs:enable

		$pending = [];
		foreach ( (array) $found as $name ) {
			$pending[] = substr( (string) $name, strlen( self::PREFIX ), -2 );
		}

		return $pending;
	}

	/**
	 * The count of an hour counter: `h` (new errors kept) or `o` (dropped).
	 *
	 * @param string $counter `h` or `o`.
	 * @param int    $hour    The hour.
	 *
	 * @return int
	 */
	public static function hour_count( string $counter, int $hour ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The rows skip the object cache on purpose (see the class comment).
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::PREFIX . $counter . '_' . $hour . '_n' ) );
	}

	/**
	 * Add what a request saw, in one query.
	 *
	 * @param array<string, array<string, mixed>> $errors   Built errors by key, each with `count`, `first_seen` and `last_seen` (Unix times).
	 * @param int                                 $hour     The hour.
	 * @param int                                 $new      New errors kept, for the hour's cap.
	 * @param int                                 $overflow New errors dropped.
	 *
	 * @return bool Whether the query ran.
	 */
	public static function add( array $errors, int $hour, int $new = 0, int $overflow = 0 ): bool {
		global $wpdb;

		$values = [];
		$args   = [];
		foreach ( $errors as $key => $error ) {
			$key = (string) $key;
			if ( ! self::is_key( $key ) ) {
				continue;
			}
			$sample = self::sample( $error );
			if ( null === $sample ) {
				continue;
			}
			array_push(
				$args,
				self::PREFIX . $key . '_n',
				(string) max( 1, (int) ( $error['count'] ?? 1 ) ),
				self::PREFIX . $key . '_l',
				(string) (int) ( $error['last_seen'] ?? time() ),
				self::PREFIX . $key . '_s',
				$sample
			);
			array_push( $values, "(%s, %s, 'no')", "(%s, %s, 'no')", "(%s, %s, 'no')" );
		}
		foreach ( [
			'h' => $new,
			'o' => $overflow,
		] as $counter => $count ) {
			if ( $count > 0 ) {
				array_push( $args, self::PREFIX . $counter . '_' . $hour . '_n', (string) $count );
				$values[] = "(%s, %s, 'no')";
			}
		}
		if ( [] === $values ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- An atomic update the options API can't do; the placeholders are built from the fixed fragment above.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES " . implode( ', ', $values )
				. " ON DUPLICATE KEY UPDATE option_value = CASE RIGHT(option_name, 2) WHEN '_n' THEN option_value + VALUES(option_value) WHEN '_l' THEN GREATEST(CAST(option_value AS UNSIGNED), CAST(VALUES(option_value) AS UNSIGNED)) ELSE option_value END",
				$args
			)
		);
		// phpcs:enable

		return false !== $result;
	}

	/**
	 * The sample of an error as it is stored: the error as it is sent, with
	 * the time of its first occurrence. Null when it can't be encoded.
	 *
	 * @param array<string, mixed> $error The built error.
	 *
	 * @return string|null
	 */
	private static function sample( array $error ): ?string {
		$sample = [
			'fingerprint' => (string) ( $error['fingerprint'] ?? '' ),
			'level'       => (string) ( $error['level'] ?? '' ),
			'source'      => $error['source'] ?? [],
			'message'     => (string) ( $error['message'] ?? '' ),
			'file'        => (string) ( $error['file'] ?? '' ),
			'line'        => (int) ( $error['line'] ?? 0 ),
			'function'    => (string) ( $error['function'] ?? '' ),
			'trace'       => $error['trace'] ?? [],
			'context'     => $error['context'] ?? [],
			'first_seen'  => (int) ( $error['first_seen'] ?? time() ),
		];
		$json   = function_exists( 'wp_json_encode' ) ? wp_json_encode( $sample, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) : json_encode( $sample, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Only without WordPress.

		return is_string( $json ) ? $json : null;
	}

	/**
	 * Everything waiting to be sent: the errors by key, with their count,
	 * last time and sample, and the overflow per hour.
	 *
	 * @return array{errors: array<string, array{count: int, last_seen: int, sample: array<string, mixed>|null}>, overflow: array<int, int>, hours: array<int, int>}
	 */
	public static function read(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The rows are found by name prefix, which the options API can't do.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) );

		$errors   = [];
		$overflow = [];
		$hours    = [];
		foreach ( (array) $rows as $row ) {
			$name  = substr( (string) ( $row->option_name ?? '' ), strlen( self::PREFIX ) );
			$value = (string) ( $row->option_value ?? '' );
			if ( 1 === preg_match( '/^([ho])_(\d+)_n$/', $name, $match ) ) {
				if ( 'o' === $match[1] ) {
					$overflow[ (int) $match[2] ] = (int) $value;
				} else {
					$hours[ (int) $match[2] ] = (int) $value;
				}
				continue;
			}
			if ( 1 !== preg_match( '/^([0-9a-f]{40}_[0-9a-f]{8})_([nls])$/', $name, $match ) ) {
				continue;
			}
			$key = $match[1];
			if ( ! isset( $errors[ $key ] ) ) {
				$errors[ $key ] = [
					'count'     => 0,
					'last_seen' => 0,
					'sample'    => null,
				];
			}
			if ( 'n' === $match[2] ) {
				$errors[ $key ]['count'] = (int) $value;
			} elseif ( 'l' === $match[2] ) {
				$errors[ $key ]['last_seen'] = (int) $value;
			} else {
				$sample                   = json_decode( $value, true );
				$errors[ $key ]['sample'] = is_array( $sample ) ? $sample : null;
			}
		}

		return [
			'errors'   => $errors,
			'overflow' => $overflow,
			'hours'    => $hours,
		];
	}

	/**
	 * Take sent counts off: the counts drop by what was sent. A count that
	 * reaches zero goes with its sample and last time, so the next
	 * occurrence starts a new sample; one that grew while sending stays,
	 * with its sample, for the next send.
	 *
	 * @param array<string, int> $sent Count sent by key.
	 *
	 * @return void
	 */
	public static function take( array $sent ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The rows skip the object cache on purpose (see the class comment).
		foreach ( $sent as $key => $count ) {
			$key = (string) $key;
			if ( ! self::is_key( $key ) ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value - %d WHERE option_name = %s", $count, self::PREFIX . $key . '_n' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS SIGNED) <= 0", self::PREFIX . $key . '_n' ) );
			// The sample and last time go with the count; a count that grew
			// while sending keeps them, so the next send still has a sample.
			$left = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s", self::PREFIX . $key . '_n' ) );
			if ( null === $left ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)", self::PREFIX . $key . '_s', self::PREFIX . $key . '_l' ) );
			}
		}
		// phpcs:enable
	}

	/**
	 * Remove an error's rows.
	 *
	 * @param string $key The key.
	 *
	 * @return void
	 */
	public static function delete( string $key ): void {
		global $wpdb;

		if ( ! self::is_key( $key ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The rows skip the object cache on purpose (see the class comment).
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s, %s)", self::PREFIX . $key . '_n', self::PREFIX . $key . '_l', self::PREFIX . $key . '_s' ) );
	}

	/**
	 * Take sent overflow off, and remove the counters of hours that are over.
	 *
	 * @param array<int, int> $overflow Overflow sent by hour.
	 * @param int             $current  The current hour.
	 *
	 * @return void
	 */
	public static function take_hours( array $overflow, int $current ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The rows skip the object cache on purpose (see the class comment).
		foreach ( $overflow as $hour => $count ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value - %d WHERE option_name = %s", $count, self::PREFIX . 'o_' . (int) $hour . '_n' ) );
		}
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( self::PREFIX . 'h_' ) . '%', $wpdb->esc_like( self::PREFIX . 'o_' ) . '%' ) );
		foreach ( (array) $rows as $name ) {
			if ( 1 === preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '([ho])_(\d+)_n$/', (string) $name, $match ) && (int) $match[2] < $current ) {
				// An hour that is over: its overflow is sent, or it had none.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", (string) $name ) );
			}
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS SIGNED) <= 0", $wpdb->esc_like( self::PREFIX . 'o_' ) . '%' ) );
		// phpcs:enable
	}

	/**
	 * Take the direct send lock of a fingerprint: at most one direct send
	 * per fingerprint per period. Atomic like the token refresh lock: an
	 * insert that only one request wins, and an expired lock is taken over
	 * by an update that only one request wins.
	 *
	 * @param string $fingerprint The fingerprint.
	 * @param int    $period      Seconds between direct sends.
	 * @param int    $now         The time.
	 *
	 * @return bool Whether this request may send.
	 */
	public static function lock( string $fingerprint, int $period, int $now ): bool {
		global $wpdb;

		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/', $fingerprint ) ) {
			return false;
		}
		$name = self::LOCK_PREFIX . $fingerprint;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The lock must bypass the options cache to be atomic.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) $now ) );
		if ( 1 === (int) $inserted ) {
			// phpcs:enable
			return true;
		}

		$locked_at = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( (int) $locked_at > $now - $period ) {
			// phpcs:enable
			return false;
		}

		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $now, $name, $locked_at ) );
		// phpcs:enable

		return 1 === (int) $taken;
	}

	/**
	 * Remove the direct send locks older than a period.
	 *
	 * @param int $period The period.
	 * @param int $now    The time.
	 *
	 * @return void
	 */
	public static function delete_old_locks( int $period, int $now ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The locks are removed by name prefix, which the options API can't do.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", $wpdb->esc_like( self::LOCK_PREFIX ) . '%', $now - $period ) );
	}

	/**
	 * Remove everything (when the site disconnects or capture is turned off).
	 *
	 * @return void
	 */
	public static function delete_all(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The rows are removed by name prefix, which the options API can't do.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) );
		foreach ( [ self::KNOWN_OPTION, self::SENT_OPTION ] as $name ) {
			delete_option( $name );
			delete_option( $name . '_network' );
		}
	}

	/**
	 * Forget what one target got, when it is no target any more; the stored
	 * errors stay for the other.
	 *
	 * @param Target $target The target (only its kind counts).
	 *
	 * @return void
	 */
	public static function forget_target( Target $target ): void {
		foreach ( [ self::KNOWN_OPTION, self::SENT_OPTION ] as $name ) {
			delete_option( $target->option( $name ) );
		}
	}
}
