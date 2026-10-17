<?php
/**
 * The content event limiter class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\Content;

/**
 * Class Limiter
 *
 * How many post or term events a site sends a minute. Bulk actions in one
 * request already become summary rows; the cap is for tools that change
 * many posts or terms in many requests. What is over the cap is not
 * dropped: it goes to the Overflow, which reports it a minute later.
 *
 * @package Scanfully\Events\Content
 */
final class Limiter {

	/**
	 * Default number of events a minute, per kind. Filterable via
	 * `scanfully_post_saved_events_per_minute` (posts) and
	 * `scanfully_term_events_per_minute` (terms); 0 or less is no cap.
	 */
	private const PER_MINUTE = 30;

	/**
	 * Take room for events in this minute's cap: how many of them may be
	 * sent now. One transient read and write per request, however many
	 * events it has.
	 *
	 * @param string $kind   Report::KIND_POST or Report::KIND_TERM.
	 * @param int    $wanted How many events the request has.
	 *
	 * @return int
	 */
	public static function take( string $kind, int $wanted ): int {
		if ( $wanted < 1 ) {
			return 0;
		}

		$limit = Report::KIND_TERM === $kind
			? (int) apply_filters( 'scanfully_term_events_per_minute', self::PER_MINUTE )
			: (int) apply_filters( 'scanfully_post_saved_events_per_minute', self::PER_MINUTE );
		if ( $limit < 1 ) {
			return $wanted;
		}

		$bucket = 'scanfully_' . $kind . '_events_' . (int) floor( time() / MINUTE_IN_SECONDS );
		$count  = (int) get_transient( $bucket );
		$taken  = max( 0, min( $wanted, $limit - $count ) );
		if ( $taken > 0 ) {
			set_transient( $bucket, $count + $taken, 2 * MINUTE_IN_SECONDS );
		}

		return $taken;
	}
}
