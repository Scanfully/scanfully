<?php
/**
 * The failed update event base class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class UpdateFailedEvent
 *
 * Base for the failed plugin, theme and core update events. The Controller
 * fires a custom action with a ready payload; this class sends it at most once
 * a day for the same item and versions, so an automatic update that fails
 * every twelve hours, or a retry right after a failure, isn't logged again.
 *
 * @package Scanfully\Events
 */
abstract class UpdateFailedEvent extends Event {

	/**
	 * The same failure is reported at most once per this many seconds.
	 */
	private const THROTTLE_SECONDS = DAY_IN_SECONDS;

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		// custom event so already formatted to perfection.
		return is_array( $data[0] ?? null ) ? $data[0] : [];
	}

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The event data.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		$payload = $data[0] ?? null;

		// Other code can call the custom action too; only an array payload is
		// a valid failure.
		if ( ! is_array( $payload ) ) {
			return false;
		}

		$key = 'scanfully_update_failed_' . md5(
			implode(
				'|',
				[
					static::class,
					(string) ( $payload['slug'] ?? '' ),
					(string) ( $payload['version'] ?? '' ),
					(string) ( $payload['new_version'] ?? $payload['old_version'] ?? '' ),
				]
			)
		);
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, self::THROTTLE_SECONDS );

		return true;
	}
}
