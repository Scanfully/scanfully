<?php
/**
 * The activated plugin event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class ActivatedPlugin
 *
 * @package Scanfully\Events
 */
class ActivatedPlugin extends Event {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'PluginActivated', 'activated_plugin' );
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $data[0] );

		return [
			'name'         => $plugin_data['Name'],
			'version'      => $plugin_data['Version'],
			'author'       => $plugin_data['AuthorName'],
			'slug'         => $data[0],
			'requires_wp'  => $plugin_data['RequiresWP'],
			'requires_php' => $plugin_data['RequiresPHP'],
		];
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
