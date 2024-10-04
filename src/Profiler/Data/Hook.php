<?php

namespace Scanfully\Profiler\Data;

/**
 * Plugin profile data
 */
class Hook implements ResultDataInterface {

	use ResultData;

	/**
	 * Plugin name
	 *
	 * @var string
	 */
	public string $hook_name;

	/**
	 * @param  string $hook_name
	 */
	public function __construct( string $hook_name ) {
		$this->hook_name = $hook_name;
	}

	/**
	 * Format to JSON
	 *
	 * @return array
	 */
	public final function data(): array {
		return array_merge( [ 'hook_name' => $this->hook_name ], $this->result_data_array() );
	}

}