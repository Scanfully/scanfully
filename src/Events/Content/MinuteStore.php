<?php
/**
 * The minute store class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\Content;

/**
 * Class MinuteStore
 *
 * What requests leave for a job that runs after the minute is over: the
 * posts and terms changed over the per-minute cap (Overflow) and the media
 * changes (MediaChanges\Batch).
 *
 * Each request stores its entry in a non-autoloaded option of its own, named
 * by kind, minute and a random key. Requests that end at the same moment
 * (the block editor uploads several files at once) can't overwrite each
 * other's entry, as they could when each appended to one shared list. The
 * job reads the entries straight from the options table, so it finds them
 * with a persistent object cache too, and claims each one by deleting it:
 * a job that runs twice reports nothing twice.
 *
 * @package Scanfully\Events\Content
 */
final class MinuteStore {

	/**
	 * Prefix of the option names.
	 */
	private const PREFIX = 'scanfully_minute_';

	/**
	 * Seconds after the end of the minute that its job runs, so requests
	 * that end late in the minute are in it.
	 */
	public const DELAY = 30;

	/**
	 * Store a request's entry for this minute. The first entry of a kind in
	 * a minute schedules the minute's job: `$hook` with `$args` and the
	 * minute.
	 *
	 * @param string               $kind  What the entry is about: lower case letters and `_`.
	 * @param array<string, mixed> $entry The entry.
	 * @param string               $hook  The job's Action Scheduler hook.
	 * @param array<int, mixed>    $args  The job's arguments before the minute.
	 *
	 * @return bool Whether the entry was stored.
	 */
	public static function add( string $kind, array $entry, string $hook, array $args = [] ): bool {
		$bucket = self::bucket();
		$key    = str_replace( '-', '', wp_generate_uuid4() );
		if ( ! add_option( self::PREFIX . $kind . '_' . $bucket . '_' . $key, $entry, '', false ) ) {
			return false;
		}

		// One transient read per request; two requests that both find it
		// missing schedule the job twice, which is harmless (see take()).
		$marker = self::PREFIX . $kind . '_' . $bucket . '_job';
		if ( false === get_transient( $marker ) ) {
			set_transient( $marker, 1, HOUR_IN_SECONDS );
			as_schedule_single_action( ( $bucket + 1 ) * MINUTE_IN_SECONDS + self::DELAY, $hook, array_merge( $args, [ $bucket ] ), 'scanfully' );
		}

		return true;
	}

	/**
	 * Take the entries of a kind stored up to and including a minute, oldest
	 * first, and remove them. Earlier minutes are included so entries whose
	 * job never ran are not left behind.
	 *
	 * @param string $kind   What the entries are about.
	 * @param int    $bucket The minute.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function take( string $kind, int $bucket ): array {
		global $wpdb;

		$prefix = self::PREFIX . $kind . '_';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The entries are found by name prefix, which the options API can't do; the object cache is skipped on purpose (see the class comment).
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id", $wpdb->esc_like( $prefix ) . '%' ) );

		$entries = [];
		foreach ( (array) $rows as $row ) {
			$name = (string) ( $row->option_name ?? '' );
			if ( 1 !== preg_match( '/^(\d+)_[a-f0-9]{32}$/', substr( $name, strlen( $prefix ) ), $match ) || (int) $match[1] > $bucket ) {
				continue;
			}

			// Another run of the job took it first.
			if ( ! delete_option( $name ) ) {
				continue;
			}

			$entry = maybe_unserialize( $row->option_value ?? '' );
			if ( is_array( $entry ) ) {
				$entries[] = $entry;
			}
		}

		delete_transient( self::PREFIX . $kind . '_' . $bucket . '_job' );

		return $entries;
	}

	/**
	 * The current minute.
	 *
	 * @return int
	 */
	public static function bucket(): int {
		return (int) floor( time() / MINUTE_IN_SECONDS );
	}
}
