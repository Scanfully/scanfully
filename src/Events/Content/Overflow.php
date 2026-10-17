<?php
/**
 * The content overflow class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\Content;

use Scanfully\Events\ContentEvent;
use Scanfully\Events\Controller;
use Scanfully\Events\PostChanges\Stash;

/**
 * Class Overflow
 *
 * Posts and terms changed while the per-minute cap was reached. Each
 * request stores what it couldn't send; a minute later one job reports all
 * of it as summary rows, per person, action and type, so nothing is
 * dropped silently.
 *
 * @package Scanfully\Events\Content
 */
final class Overflow {

	/**
	 * Prefix of the transient that lists a minute's stored requests.
	 */
	private const PREFIX = 'scanfully_content_overflow_';

	/**
	 * Seconds after the end of the minute that the job runs, so requests
	 * that end late in the minute are still in it.
	 */
	private const DELAY = 30;

	/**
	 * Store what a request couldn't send for this minute's job: one stash
	 * with its items, and its key in the minute's list. The first request
	 * of the minute schedules the job.
	 *
	 * @param string                           $kind  Report::KIND_POST or Report::KIND_TERM.
	 * @param array<int, array<string, mixed>> $items The items.
	 * @param array{id: int, name: string}     $user  Who changed them.
	 *
	 * @return void
	 */
	public static function add( string $kind, array $items, array $user ): void {
		$items = array_map(
			static function ( array $item ): array {
				unset( $item['event'] );
				return $item;
			},
			$items
		);

		$stash = Stash::put(
			[
				'user'  => $user,
				'items' => $items,
			]
		);
		if ( '' === $stash ) {
			return;
		}

		$bucket = (int) floor( time() / MINUTE_IN_SECONDS );
		$key    = self::PREFIX . $kind . '_' . $bucket;
		$stored = get_transient( $key );
		$list   = is_array( $stored ) ? $stored : [];
		if ( [] === $list ) {
			as_schedule_single_action( ( $bucket + 1 ) * MINUTE_IN_SECONDS + self::DELAY, Controller::ACTION_SEND_OVERFLOW, [ $kind, $bucket ], 'scanfully' );
		}
		$list[] = $stash;

		set_transient( $key, $list, HOUR_IN_SECONDS );
	}

	/**
	 * Action Scheduler callback: report a minute's stored items as summary
	 * rows, per person, action and type.
	 *
	 * @param string $kind   Report::KIND_POST or Report::KIND_TERM.
	 * @param int    $bucket The minute.
	 *
	 * @return void
	 */
	public static function send( string $kind, int $bucket ): void {
		$key    = self::PREFIX . $kind . '_' . $bucket;
		$stored = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $stored ) ) {
			return;
		}

		$by_user = [];
		foreach ( $stored as $stash ) {
			$entry = Stash::get( (string) $stash );
			Stash::delete( (string) $stash );
			if ( ! is_array( $entry ) || ! is_array( $entry['items'] ?? null ) ) {
				continue;
			}

			$user = [
				'id'   => (int) ( $entry['user']['id'] ?? 0 ),
				'name' => (string) ( $entry['user']['name'] ?? '' ),
			];
			$who  = $user['id'] . '|' . $user['name'];

			$by_user[ $who ]['user'] = $user;
			foreach ( $entry['items'] as $item ) {
				$by_user[ $who ]['items'][] = $item;
			}
		}

		foreach ( $by_user as $entry ) {
			foreach ( Summary::group( $entry['items'] ?? [] ) as $group ) {
				foreach ( Summary::rows( $kind, $group, true ) as $row ) {
					ContentEvent::queue( $row['type'], $entry['user'], $row['data'] );
				}
			}
		}
	}
}
