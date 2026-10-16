<?php
/**
 * The theme installed event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class ThemeInstalled
 *
 * A theme installed from WordPress.org, an uploaded zip or WP-CLI. Replacing
 * an installed theme with an uploaded zip is an install for WordPress too,
 * and is reported the same way.
 *
 * @package Scanfully\Events
 */
class ThemeInstalled extends Event {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'ThemeInstalled', 'upgrader_process_complete', 10, 2 );
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send: the upgrader, the hook extras.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$theme = self::installed_theme( $data );
		if ( null === $theme ) {
			return [];
		}

		$name = self::plain_text( $theme->get( 'Name' ) );

		return [
			'name'    => '' !== $name ? $name : $theme->get_stylesheet(),
			'slug'    => $theme->get_stylesheet(),
			'version' => (string) $theme->get( 'Version' ),
			'author'  => self::plain_text( $theme->get( 'Author' ) ),
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
		return null !== self::installed_theme( $data );
	}

	/**
	 * The theme a successful install put in place, or null when this wasn't a
	 * successful theme install.
	 *
	 * @param array $data The event data: the upgrader, the hook extras.
	 *
	 * @return \WP_Theme|null
	 */
	private static function installed_theme( array $data ): ?\WP_Theme {
		$upgrader   = $data[0] ?? null;
		$hook_extra = $data[1] ?? null;

		if ( ! is_array( $hook_extra ) || 'install' !== ( $hook_extra['action'] ?? '' ) || 'theme' !== ( $hook_extra['type'] ?? '' ) ) {
			return null;
		}

		// The upgrader's result is an array only when the install succeeded.
		if ( ! is_object( $upgrader ) || ! method_exists( $upgrader, 'theme_info' ) || ! isset( $upgrader->result ) || ! is_array( $upgrader->result ) ) {
			return null;
		}

		$theme = $upgrader->theme_info();

		return $theme instanceof \WP_Theme && $theme->exists() ? $theme : null;
	}
}
