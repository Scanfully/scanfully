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
	 * A check if a event should fire
	 *
	 * @param  array $data
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		return true;
	}
}
