<?php

namespace Scanfully\Events;

/**
 * Class DeactivatedPlugin
 *
 * @package Scanfully\Events
 */
class DeactivatedPlugin extends Event {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'PluginDeactivated', 'deactivated_plugin' );
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
}
