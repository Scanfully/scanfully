<?php
/**
 * The theme switched event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class ThemeSwitched
 *
 * @package Scanfully\Events
 */
class ThemeSwitched extends Event {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'ThemeSwitched', 'switch_theme', 10, 3 );
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send: new theme name, new theme, old theme.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$new_theme = $data[1] ?? null;
		$old_theme = $data[2] ?? null;

		$name = $new_theme instanceof \WP_Theme ? (string) $new_theme->get( 'Name' ) : '';
		if ( '' === $name && is_string( $data[0] ?? null ) ) {
			$name = $data[0];
		}

		return [
			'name'     => $name,
			'slug'     => $new_theme instanceof \WP_Theme ? $new_theme->get_stylesheet() : '',
			'old_name' => $old_theme instanceof \WP_Theme ? (string) $old_theme->get( 'Name' ) : '',
			'old_slug' => $old_theme instanceof \WP_Theme ? $old_theme->get_stylesheet() : '',
		];
	}

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The event data: new theme name, new theme, old theme.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		$new_theme = $data[1] ?? null;
		$old_theme = $data[2] ?? null;

		if ( ! $new_theme instanceof \WP_Theme ) {
			return false;
		}

		return ! $old_theme instanceof \WP_Theme || $old_theme->get_stylesheet() !== $new_theme->get_stylesheet();
	}
}
