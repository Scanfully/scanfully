<?php
/**
 * The PluginUpdate event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class PluginUpdate
 *
 * @package Scanfully\Events
 */
class PluginUpdate extends Event {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'PluginUpdate', 'scanfully_plugin_updated' );
	}

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
		// Other code can call the scanfully_plugin_updated action too; only an array
		// payload is a valid update.
		return is_array( $data[0] ?? null );
	}
}
