<?php
/**
 * The Manage ledger class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

/**
 * What the plugin did with each Scanfully Manage job it pulled, so a job id
 * runs once on the site: the API hands a leased job out again on every pull
 * while its lease lasts, and the poke and the clock may pull at the same
 * time.
 *
 * One row per job in the options table (never autoloaded), written straight
 * to the database: a claim is an INSERT IGNORE, which only one process wins,
 * and a change of state an UPDATE that only succeeds from the state it read,
 * as the token refresh lock does. A row holds the job as pulled, so the
 * run action carries only its id. A job goes scheduled, running, done (its
 * result kept until the API took it) and reported.
 */
class Ledger {

	/**
	 * The option name of a job's row, before its id.
	 */
	public const PREFIX = 'scanfully_manage_job_';

	public const SCHEDULED = 'scheduled';
	public const RUNNING   = 'running';
	public const DONE      = 'done';
	public const REPORTED  = 'reported';

	/**
	 * Seconds a row is kept: far beyond any lease, so the API never hands
	 * out a job the plugin forgot.
	 */
	private const KEEP = 7 * DAY_IN_SECONDS;

	/**
	 * Rows read per query.
	 */
	private const PAGE = 500;

	/**
	 * Whether a job id has the form of one: a UUID.
	 *
	 * @param mixed $id The id.
	 *
	 * @return bool
	 */
	public static function valid_id( $id ): bool {
		return is_string( $id ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id );
	}

	/**
	 * Claim a job the plugin has not seen: true for the one process that
	 * records it as scheduled, with the job as pulled, false when it is known
	 * already.
	 *
	 * @param array{id: string, action: string, params: array} $job The job.
	 * @param int                                              $now The time.
	 *
	 * @return bool
	 */
	public static function claim( array $job, int $now ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- An atomic claim must bypass the options cache.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				self::PREFIX . $job['id'],
				self::encode(
					[
						'state' => self::SCHEDULED,
						'at'    => $now,
						'job'   => $job,
					]
				)
			)
		);

		return 1 === (int) $inserted;
	}

	/**
	 * A job's row, with the raw value under raw for a change of state, or
	 * null when the plugin has not seen the job.
	 *
	 * @param string $id The job id.
	 *
	 * @return array{state: string, at: int, started_at?: int, job?: array, result?: array, raw: string}|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read past the options cache, as the claim writes past it.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::PREFIX . $id ) );
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$row = json_decode( $raw, true );
		if ( ! is_array( $row ) || ! isset( $row['state'] ) || ! is_string( $row['state'] ) ) {
			return null;
		}
		$row['at']  = (int) ( $row['at'] ?? 0 );
		$row['raw'] = $raw;

		return $row;
	}

	/**
	 * Move a job's row on from the row read: true for the one process whose
	 * change went through.
	 *
	 * @param string $id   The job id.
	 * @param array  $from The row as get() read it.
	 * @param array  $to   The new row, without raw.
	 *
	 * @return bool
	 */
	public static function move( string $id, array $from, array $to ): bool {
		global $wpdb;

		unset( $to['raw'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A compare-and-swap must bypass the options cache.
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				self::encode( $to ),
				self::PREFIX . $id,
				(string) ( $from['raw'] ?? '' )
			)
		);

		return 1 === (int) $changed;
	}

	/**
	 * Remove the rows older than a week.
	 *
	 * @param int $now The time.
	 *
	 * @return int How many rows went.
	 */
	public static function prune( int $now ): int {
		global $wpdb;

		$removed = 0;
		foreach ( self::rows() as $name => $value ) {
			$row = json_decode( $value, true );
			if ( is_array( $row ) && (int) ( $row['at'] ?? 0 ) > $now - self::KEEP ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The ledger's own rows, past the options cache.
			$removed += (int) $wpdb->delete( $wpdb->options, [ 'option_name' => $name ] );
		}

		return $removed;
	}

	/**
	 * Note a running job's sign of life, a heartbeat, so it does not count
	 * as stopped while it works.
	 *
	 * @param string $id  The job id.
	 * @param int    $now The time.
	 *
	 * @return void
	 */
	public static function touch( string $id, int $now ): void {
		$row = self::get( $id );
		if ( null !== $row && self::RUNNING === $row['state'] ) {
			$row['at'] = $now;
			self::move( $id, $row, $row );
		}
	}

	/**
	 * The jobs whose result still has to reach the API: done ones, and
	 * running ones whose last sign of life is before the time given.
	 *
	 * @param int $stale_before Running jobs quiet since before this time count.
	 *
	 * @return string[] The job ids.
	 */
	public static function open( int $stale_before ): array {
		$ids = [];
		foreach ( self::rows() as $name => $value ) {
			$row   = json_decode( $value, true );
			$state = is_array( $row ) ? ( $row['state'] ?? '' ) : '';
			if ( self::DONE === $state || ( self::RUNNING === $state && (int) ( $row['at'] ?? 0 ) < $stale_before ) ) {
				$ids[] = substr( $name, strlen( self::PREFIX ) );
			}
		}

		return $ids;
	}

	/**
	 * The jobs scheduled to start: pulled, not started yet, or whose first
	 * heartbeat got no yes.
	 *
	 * @return string[] The job ids.
	 */
	public static function scheduled(): array {
		$ids = [];
		foreach ( self::rows() as $name => $value ) {
			$row = json_decode( $value, true );
			if ( is_array( $row ) && self::SCHEDULED === ( $row['state'] ?? '' ) ) {
				$ids[] = substr( $name, strlen( self::PREFIX ) );
			}
		}

		return $ids;
	}

	/**
	 * The ledger's rows, by option name, in the order they were written,
	 * read a page at a time so a long ledger is read to its end.
	 *
	 * @return array<string, string>
	 */
	private static function rows(): array {
		global $wpdb;

		$out   = [];
		$after = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The ledger's own rows, past the options cache.
			$page = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id > %d ORDER BY option_id LIMIT %d",
					$wpdb->esc_like( self::PREFIX ) . '%',
					$after,
					self::PAGE
				),
				ARRAY_A
			);
			foreach ( $page as $row ) {
				$out[ (string) $row['option_name'] ] = (string) $row['option_value'];
				$after                               = (int) $row['option_id'];
			}
			$full = self::PAGE === count( $page );
		} while ( $full );

		return $out;
	}

	/**
	 * A row as stored.
	 *
	 * @param array $row The row.
	 *
	 * @return string
	 */
	private static function encode( array $row ): string {
		return (string) wp_json_encode( $row );
	}
}
