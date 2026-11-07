<?php
/**
 * The Manage run lock class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

/**
 * One Scanfully Manage job at a time on the site. The lock is one row in the
 * options table (never autoloaded) that names the job holding it and until
 * when, written straight to the database as the ledger's rows: taken with an
 * INSERT IGNORE that only one process wins, taken over only from the expired
 * row it read, extended on every heartbeat and released on every way out of
 * a run, a PHP fatal included. A job that finds the lock held does not start
 * and stays for the next pull.
 */
final class RunLock {

	/**
	 * The option name of the lock.
	 */
	public const OPTION = 'scanfully_manage_lock';

	/**
	 * Take the lock for a job until a time: true when the job holds it now,
	 * because it was free, had expired or was the job's already.
	 *
	 * @param string $owner The job id.
	 * @param int    $until Until when.
	 * @param int    $now   The time.
	 *
	 * @return bool
	 */
	public static function take( string $owner, int $until, int $now ): bool {
		global $wpdb;

		$value = self::encode( $owner, $until );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- An atomic claim must bypass the options cache.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				self::OPTION,
				$value
			)
		);
		if ( 1 === (int) $inserted ) {
			return true;
		}
		$held = self::read();
		if ( null === $held ) {
			return false;
		}
		if ( $held['owner'] !== $owner && $held['until'] >= $now ) {
			return false;
		}

		return self::swap( $held['raw'], $value );
	}

	/**
	 * Extend the lock of a job that holds it.
	 *
	 * @param string $owner The job id.
	 * @param int    $until The new end.
	 *
	 * @return bool Whether the job still holds the lock.
	 */
	public static function extend( string $owner, int $until ): bool {
		$held = self::read();
		if ( null === $held || $held['owner'] !== $owner ) {
			return false;
		}

		return self::swap( $held['raw'], self::encode( $owner, $until ) );
	}

	/**
	 * Release the lock, when the job holds it.
	 *
	 * @param string $owner The job id.
	 *
	 * @return void
	 */
	public static function release( string $owner ): void {
		global $wpdb;

		$held = self::read();
		if ( null === $held || $held['owner'] !== $owner ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Remove only the row read, past the options cache.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::OPTION,
				$held['raw']
			)
		);
	}

	/**
	 * The job holding the lock at a time, or null when it is free.
	 *
	 * @param int $now The time.
	 *
	 * @return string|null
	 */
	public static function holder( int $now ): ?string {
		$held = self::read();

		return null !== $held && $held['until'] >= $now ? $held['owner'] : null;
	}

	/**
	 * The lock as stored, or null when there is none.
	 *
	 * @return array{owner: string, until: int, raw: string}|null
	 */
	private static function read(): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read past the options cache, as the claim writes past it.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$lock = json_decode( $raw, true );

		return [
			'owner' => is_array( $lock ) && isset( $lock['owner'] ) && is_string( $lock['owner'] ) ? $lock['owner'] : '',
			'until' => is_array( $lock ) ? (int) ( $lock['until'] ?? 0 ) : 0,
			'raw'   => $raw,
		];
	}

	/**
	 * Replace the lock row read with a new value: true for the one process
	 * whose change went through.
	 *
	 * @param string $from The value read.
	 * @param string $to   The new value.
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

		return 1 === (int) $changed || $from === $to;
	}

	/**
	 * The lock as stored.
	 *
	 * @param string $owner The job id.
	 * @param int    $until Until when.
	 *
	 * @return string
	 */
	private static function encode( string $owner, int $until ): string {
		return (string) wp_json_encode(
			[
				'owner' => $owner,
				'until' => $until,
			]
		);
	}
}
