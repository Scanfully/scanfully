<?php

namespace Scanfully\Profiler\Data;

/**
 * Plugin profile data
 */
class Stage implements DataInterface {

	use ResultData;

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

	/**
	 * Format to JSON
	 *
	 * @return array
	 */
	public final function data(): array {
		return array_merge( [], $this->result_data_array() );
	}

}