<?php

namespace Watchfully;

class Main {

	/** @var ?Main */
	private static $instance = null;

	/**
	 * Singleton getter
	 *
	 * @return Main|null
	 */
	public static function Get(): ?Main {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function Setup(): void {
		error_log("setup main");
	}

}