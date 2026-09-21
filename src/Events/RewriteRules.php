<?php
/**
 * The rewrite rules event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class RewriteRules
 *
 * @package Scanfully\Events
 */
class RewriteRules extends Event {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'RewriteRules', 'update_option_rewrite_rules', 10, 3 );
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		// The rewrite rules array is large and not useful in the timeline.
		// The event type alone is sufficient to indicate the rules changed.
		return [];
	}

	/**
	 * At most one rewrite rules event per this many seconds.
	 */
	private const THROTTLE_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * Whether an event was already reported in this request.
	 *
	 * @var bool
	 */
	private static bool $fired = false;

	/**
	 * A check if a event should fire
	 *
	 * Before WordPress 6.4 every flush first emptied the option and then
	 * saved the rules, so each flush (even with unchanged rules) triggered
	 * this twice. Some plugins flush on every page load, so events are also
	 * limited to one per request and one per 5 minutes.
	 *
	 * @param  array $data The event data: old value, new value, option name.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		// The intermediate empty write of a flush isn't a change.
		if ( empty( $data[1] ) ) {
			return false;
		}

		if ( self::$fired || get_transient( 'scanfully_rewrite_rules_event' ) ) {
			return false;
		}

		set_transient( 'scanfully_rewrite_rules_event', 1, self::THROTTLE_SECONDS );
		self::$fired = true;

		return true;
	}
}
