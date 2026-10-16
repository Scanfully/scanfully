<?php
/**
 * The plugin installed event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class PluginInstalled
 *
 * A plugin installed from WordPress.org, an uploaded zip or WP-CLI. Replacing
 * an installed plugin with an uploaded zip is an install for WordPress too,
 * and is reported the same way.
 *
 * @package Scanfully\Events
 */
class PluginInstalled extends Event {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'PluginInstalled', 'upgrader_process_complete', 10, 2 );
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send: the upgrader, the hook extras.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$plugin      = self::installed_plugin( $data );
		$plugin_data = get_file_data(
			WP_PLUGIN_DIR . '/' . $plugin,
			[
				'Name'    => 'Plugin Name',
				'Version' => 'Version',
				'Author'  => 'Author',
			]
		);

		return [
			'name'    => '' !== ( $plugin_data['Name'] ?? '' ) ? $plugin_data['Name'] : $plugin,
			'slug'    => $plugin,
			'version' => $plugin_data['Version'] ?? '',
			'author'  => $plugin_data['Author'] ?? '',
		];
	}

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The event data: the upgrader, the hook extras.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		return '' !== self::installed_plugin( $data );
	}

	/**
	 * The basename of the plugin a successful install put in place, or ''
	 * when this wasn't a successful plugin install.
	 *
	 * @param array $data The event data: the upgrader, the hook extras.
	 *
	 * @return string
	 */
	private static function installed_plugin( array $data ): string {
		$upgrader   = $data[0] ?? null;
		$hook_extra = $data[1] ?? null;

		if ( ! is_array( $hook_extra ) || 'install' !== ( $hook_extra['action'] ?? '' ) || 'plugin' !== ( $hook_extra['type'] ?? '' ) ) {
			return '';
		}

		// The upgrader's result is an array only when the install succeeded.
		if ( ! is_object( $upgrader ) || ! method_exists( $upgrader, 'plugin_info' ) || ! isset( $upgrader->result ) || ! is_array( $upgrader->result ) ) {
			return '';
		}

		$plugin = $upgrader->plugin_info();

		return is_string( $plugin ) ? $plugin : '';
	}
}
