<?php
/**
 * The content overflow class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\Content;

use Scanfully\Events\ContentEvent;
use Scanfully\Events\Controller;

/**
 * Class Overflow
 *
 * Posts and terms changed while the per-minute cap was reached. Each
 * request stores what it couldn't send in the MinuteStore; a minute later
 * one job reports all of it as summary rows, per person, action and type,
 * so nothing is dropped silently.
 *
 * @package Scanfully\Events\Content
 */
final class Overflow {

	/**
	 * Store what a request couldn't send for this minute's job. The first
	 * request of the minute schedules the job.
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

		MinuteStore::add(
			self::store_kind( $kind ),
			[
				'user'  => $user,
				'items' => $items,
			],
			Controller::ACTION_SEND_OVERFLOW,
			[ $kind ]
		);
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
		$by_user = [];
		foreach ( MinuteStore::take( self::store_kind( $kind ), $bucket ) as $entry ) {
			if ( ! is_array( $entry['items'] ?? null ) ) {
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

	/**
	 * The MinuteStore kind of a kind of item.
	 *
	 * @param string $kind Report::KIND_POST or Report::KIND_TERM.
	 *
	 * @return string
	 */
	private static function store_kind( string $kind ): string {
		return 'overflow_' . ( Report::KIND_TERM === $kind ? Report::KIND_TERM : Report::KIND_POST );
	}
}
