<?php

namespace Scanfully\Profiler\Data;

/**
 * Plugin profile data
 */
class Stage implements DataInterface{

	use Data;

	/**
	 * Plugin name
	 *
	 * @var string
	 */
	public string $id;

	/**
	 * @param  string $id
	 */
	public function __construct( string $id ) {
		$this->id = $id;
	}

}