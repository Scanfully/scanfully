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
		// The second argument says whether it was activated for the whole network.
		parent::__construct( 'PluginActivate', 'activated_plugin', 10, 2 );
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
			'network_wide' => ! empty( $data[1] ),
		];
	}

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The event data.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		return true;
	}
}
