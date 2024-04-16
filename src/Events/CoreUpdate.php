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
class CoreUpdate extends Event {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'CoreUpdate', '_core_updated_successfully' );
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$version = $data[0];

		return [
			'version'      => $version,
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
