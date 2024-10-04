<?php

namespace Scanfully\Profiler\Data;

/**
 * Plugin profile data
 */
class Plugin implements ResultDataInterface, ProfilingInterface {

	use ResultData, Profiling;

	/**
	 * Plugin name
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * Is this a must-use plugin
	 *
	 * @var bool
	 */
	public bool $is_mu;

	/**
	 * @param  string $name
	 * @param  bool $is_mu
	 */
	public function __construct( string $name, bool $is_mu = false ) {
		$this->name  = $name;
		$this->is_mu = $is_mu;
	}

	/**
	 * Plugin data
	 *
	 * @return array
	 */
	public function data(): array {
		return array_merge( [ 'name' => $this->name, 'mu' => $this->is_mu ], $this->result_data_array() );
	}
}