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
class ThemeUpdate extends Event {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'ThemeUpdate', 'scanfully_theme_updated');
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		// custom event so already formatted to perfection
		return $data[0];
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
