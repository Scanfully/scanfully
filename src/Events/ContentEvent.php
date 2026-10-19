<?php
/**
 * The content event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

use Scanfully\Events\PostChanges\Stash;

/**
 * Class ContentEvent
 *
 * An event about posts or terms that the end of the request reports
 * (Content\Report): a permanent delete, a term created, changed or deleted,
 * or a summary row of many. The request passes the data it noted; the
 * background job (Content\Builder) adds names that cost a lookup and sends
 * it.
 *
 * @package Scanfully\Events
 */
abstract class ContentEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param string $type The event type.
	 */
	public function __construct( string $type ) {
		parent::__construct( $type, self::hook( $type ), 10, 1 );
	}

	/**
	 * The action the report fires for an event type.
	 *
	 * @param string $type The event type.
	 *
	 * @return string
	 */
	public static function hook( string $type ): string {
		return 'scanfully_content_event_' . $type;
	}

	/**
	 * A user's name as events report it.
	 *
	 * @param object $user The user (a WP_User).
	 *
	 * @return string
	 */
	public static function user_name( object $user ): string {
		return self::safe_display_name( $user );
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data passed to the action: the event data.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		return is_array( $data[0] ?? null ) ? $data[0] : [];
	}

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The data passed to the action: the event data.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		// The report has already decided what to send.
		return is_array( $data[0] ?? null ) && [] !== $data[0];
	}

	/**
	 * Schedule the background job that names and sends the event.
	 *
	 * @param string $type The event type.
	 * @param array  $user The user who made the change.
	 * @param array  $data The event data.
	 *
	 * @return void
	 */
	protected function schedule( string $type, array $user, array $data ): void {
		self::queue( $type, $user, $data );
	}

	/**
	 * Schedule the job for an event. Data longer than the job's arguments
	 * allow (a summary row lists every post) is stored for the job.
	 *
	 * @param string $type The event type.
	 * @param array  $user The user who made the change.
	 * @param array  $data The event data.
	 *
	 * @return void
	 */
	public static function queue( string $type, array $user, array $data ): void {
		$args = [
			'type'  => $type,
			'user'  => $user,
			'data'  => $data,
			'stash' => '',
		];

		if ( strlen( (string) wp_json_encode( $args ) ) > self::MAX_ARGS_LENGTH ) {
			$stash = Stash::put( [ 'data' => $data ] );
			if ( '' !== $stash ) {
				$args['data']  = [ 'stashed' => true ];
				$args['stash'] = $stash;
			} else {
				$args = self::fit_args( $args );
			}
		}

		as_schedule_single_action( time(), Controller::ACTION_BUILD_CONTENT_EVENT, $args, 'scanfully' );
	}
}
