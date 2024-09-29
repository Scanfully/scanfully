<?php

namespace Scanfully\Profiler\Data;

/**
 * Plugin profile data
 */
class Plugin {

	use Data;

	/**
	 * Plugin name
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * @param  string $name
	 */
	public function __construct( string $name ) {
		$this->name = $name;
	}

}