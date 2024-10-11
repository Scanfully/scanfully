<?php

namespace Scanfully\Profiler\Data;


/**
 * Plugin profile data
 */
class Core implements ResultDataInterface, ProfilingInterface {

	use ResultData, Profiling;

	/**
	 * Constructor
	 */
	public function __construct() {
	}

	/**
	 * Plugin data
	 *
	 * @return array
	 */
	public final function data(): array {
		return array_merge( [], $this->result_data_array() );
	}
}