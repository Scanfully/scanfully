<?php
/**
 * The theme deleted event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class ThemeDeleted
 *
 * The theme's details are read on `delete_theme`, while its files still
 * exist, and sent on `deleted_theme` once the files are really gone.
 *
 * @package Scanfully\Events
 */
class ThemeDeleted extends Event {

	/**
	 * Details of the themes about to be deleted in this request, by stylesheet.
	 *
	 * @var array<string, array{name: string, slug: string, version: string}>
	 */
	private static array $pending = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'ThemeDeleted', 'deleted_theme', 10, 2 );

		add_action( 'delete_theme', [ self::class, 'remember' ], 10, 1 );
	}

	/**
	 * Read the details of a theme that is about to be deleted.
	 *
	 * @param mixed $stylesheet The theme's stylesheet (directory name).
	 *
	 * @return void
	 */
	public static function remember( $stylesheet ): void {
		if ( ! is_string( $stylesheet ) || '' === $stylesheet ) {
			return;
		}

		$theme = wp_get_theme( $stylesheet );
		$name  = $theme->exists() ? (string) $theme->get( 'Name' ) : '';

		self::$pending[ $stylesheet ] = [
			'name'    => '' !== $name ? $name : $stylesheet,
			'slug'    => $stylesheet,
			'version' => $theme->exists() ? (string) $theme->get( 'Version' ) : '',
		];
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send: stylesheet, whether it was deleted.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$stylesheet = (string) ( $data[0] ?? '' );

		return self::$pending[ $stylesheet ] ?? [
			'name'    => $stylesheet,
			'slug'    => $stylesheet,
			'version' => '',
		];
	}

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The event data: stylesheet, whether it was deleted.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		return is_string( $data[0] ?? null ) && '' !== $data[0] && true === ( $data[1] ?? false );
	}
}
