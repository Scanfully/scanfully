<?php
/**
 * The content report class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\Content;

use Scanfully\Events\ContentEvent;
use Scanfully\Options;

/**
 * Class Report
 *
 * Reports the posts or terms a request changed, at the end of the request:
 * each on its own row, or, when more than a few got the same action, one
 * summary row for all of them. What is over the per-minute cap goes to the
 * Overflow instead of being dropped.
 *
 * @package Scanfully\Events\Content
 */
final class Report {

	/**
	 * Items about posts.
	 */
	public const KIND_POST = 'post';

	/**
	 * Items about taxonomy terms.
	 */
	public const KIND_TERM = 'term';

	/**
	 * Default number of posts (or terms) of one action and type that still
	 * get rows of their own; more become one summary row. Filterable via
	 * `scanfully_activity_bulk_threshold`.
	 */
	private const THRESHOLD = 10;

	/**
	 * Report the items of a request.
	 *
	 * @param string                           $kind  self::KIND_POST or self::KIND_TERM.
	 * @param array<int, array<string, mixed>> $items The items (see Summary).
	 *
	 * @return void
	 */
	public static function send( string $kind, array $items ): void {
		// A site that isn't connected has nowhere to send events.
		if ( [] === $items || ! Options\Controller::get_options()->is_connected ) {
			return;
		}

		/**
		 * Filters how many posts or terms of one action and type a request
		 * reports on rows of their own; more become one summary row.
		 *
		 * @param int    $threshold The number; 10 by default.
		 * @param string $kind      `post` or `term`.
		 */
		$threshold = max( 0, (int) apply_filters( 'scanfully_activity_bulk_threshold', self::THRESHOLD, $kind ) );

		$rows    = [];
		$singles = [];
		foreach ( Summary::group( $items ) as $group ) {
			if ( count( $group ) > $threshold || self::has_light( $group ) ) {
				array_push( $rows, ...Summary::rows( $kind, $group ) );
			} else {
				array_push( $singles, ...$group );
			}
		}

		// Summary rows first: each one covers many items.
		$room = Limiter::take( $kind, count( $rows ) + count( $singles ) );
		$over = [];
		foreach ( $rows as $row ) {
			if ( $room > 0 ) {
				--$room;
				do_action( ContentEvent::hook( $row['type'] ), $row['data'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The hooks start with scanfully_.
			} else {
				array_push( $over, ...$row['items'] );
			}
		}
		foreach ( $singles as $item ) {
			if ( $room > 0 ) {
				--$room;
				do_action_ref_array( (string) $item['event']['hook'], (array) $item['event']['args'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The hooks start with scanfully_.
			} else {
				$over[] = $item;
			}
		}

		if ( [] !== $over ) {
			Overflow::add( $kind, $over, self::current_user() );
		}
	}

	/**
	 * Whether a group has items that can't be reported on their own (the
	 * request only counted them).
	 *
	 * @param array<int, array<string, mixed>> $group The items.
	 *
	 * @return bool
	 */
	private static function has_light( array $group ): bool {
		foreach ( $group as $item ) {
			if ( ! isset( $item['event'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The signed-in user, as events report it.
	 *
	 * @return array{id: int, name: string}
	 */
	private static function current_user(): array {
		$user = wp_get_current_user();

		return [
			'id'   => (int) $user->ID,
			'name' => ContentEvent::user_name( $user ),
		];
	}
}
