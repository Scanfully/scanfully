<?php
/**
 * The permalinks changed event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class PermalinksChanged
 *
 * Reports a change of the permalink structure (Settings, Permalinks). An empty
 * structure means plain permalinks (`?p=123`).
 *
 * @package Scanfully\Events
 */
class PermalinksChanged extends Event {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'PermalinksChanged', 'update_option_permalink_structure', 10, 2 );

		// When the option row doesn't exist yet, update_option() adds it and
		// only the add_option hook fires. Report that as a change from plain
		// permalinks.
		add_action(
			'add_option_permalink_structure',
			function ( $option, $value ) {
				$this->listener_callback( '', $value );
			},
			10,
			2
		);
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send: old value, new value.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		return [
			'old' => self::to_string( $data[0] ?? '' ),
			'new' => self::to_string( $data[1] ?? '' ),
		];
	}

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The event data: old value, new value.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		return self::to_string( $data[0] ?? '' ) !== self::to_string( $data[1] ?? '' );
	}

	/**
	 * The structure as a string; anything else counts as plain permalinks.
	 *
	 * @param mixed $value The option value.
	 *
	 * @return string
	 */
	private static function to_string( $value ): string {
		return is_string( $value ) ? $value : '';
	}
}
