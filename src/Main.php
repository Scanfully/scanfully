<?php

namespace Watchfully;

use Watchfully\Events\Controller;

class Main {

	/** @var ?Main */
	private static $instance = null;

	/**
	 * Singleton getter
	 *
	 * @return Main|null
	 */
	public static function get(): ?Main {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function setup(): void {

		/** register all events */

		// when a plugin is activated
		Controller::register( new Events\ActivatedPlugin() );

	}

}