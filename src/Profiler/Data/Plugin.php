<?php

namespace Scanfully\Profiler\Data;

/**
 * Plugin profile data
 */
class Plugin implements DataInterface {

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

	/**
	 * Plugin data
	 *
	 * @return array
	 */
	public function data(): array {
		return [ 'plugin' => 'hehe' ];
	}
}