<?php

namespace Scanfully\Profiler\Data;

class Callback implements DataInterface {

	// use the data and profiling traits
	use ResultData, Profiling;

	public string $name;
	public string $file;
	public int $line;

	/**
	 * @param  string $name
	 * @param  string $file
	 * @param  int $line
	 */
	public function __construct( string $name, string $file, int $line ) {
		$this->name = $name;
		$this->file = $file;
		$this->line = $line;
	}

	//public $callback;

	//public string $type;

	/**
	 * @return array
	 */
	public function data(): array {
		return array_merge( [
			'name' => $this->name,
			'file' => $this->file,
			'line' => $this->line,
		], $this->result_data_array() );
	}
}