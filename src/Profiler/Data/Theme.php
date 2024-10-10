<?php

namespace Scanfully\Profiler\Data;

/**
 * Plugin profile data
 */
class Theme implements ResultDataInterface, ProfilingInterface {

	use ResultData, Profiling;

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
		return array_merge( [ 'name' => $this->name ], $this->result_data_array() );
	}
}