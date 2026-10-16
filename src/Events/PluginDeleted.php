<?php
/**
 * The plugin deleted event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class PluginDeleted
 *
 * The plugin's details are read on `delete_plugin`, while its files still
 * exist, and sent on `deleted_plugin` once the files are really gone.
 *
 * @package Scanfully\Events
 */
class PluginDeleted extends Event {

	/**
	 * Details of the plugins about to be deleted in this request, by basename.
	 *
	 * @var array<string, array{name: string, slug: string, version: string}>
	 */
	private static array $pending = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'PluginDeleted', 'deleted_plugin', 10, 2 );

		add_action( 'delete_plugin', [ self::class, 'remember' ], 10, 1 );
	}

	/**
	 * Read the details of a plugin that is about to be deleted.
	 *
	 * @param mixed $plugin_file Plugin basename.
	 *
	 * @return void
	 */
	public static function remember( $plugin_file ): void {
		if ( ! is_string( $plugin_file ) || '' === $plugin_file ) {
			return;
		}

		$plugin_data = get_file_data(
			WP_PLUGIN_DIR . '/' . $plugin_file,
			[
				'Name'    => 'Plugin Name',
				'Version' => 'Version',
			]
		);

		self::$pending[ $plugin_file ] = [
			'name'    => '' !== ( $plugin_data['Name'] ?? '' ) ? $plugin_data['Name'] : $plugin_file,
			'slug'    => $plugin_file,
			'version' => $plugin_data['Version'] ?? '',
		];
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send: plugin basename, whether it was deleted.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$plugin_file = (string) ( $data[0] ?? '' );

		return self::$pending[ $plugin_file ] ?? [
			'name'    => $plugin_file,
			'slug'    => $plugin_file,
			'version' => '',
		];
	}

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The event data: plugin basename, whether it was deleted.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		return is_string( $data[0] ?? null ) && '' !== $data[0] && true === ( $data[1] ?? false );
	}
}
