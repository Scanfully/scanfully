<?php

namespace Scanfully\Profiler\Data;

/**
 * HookLog class
 *
 * Inspired from https://github.com/wp-cli/profile-command/blob/main/src/Logger.php
 */
class Hook implements DataInterface {

	// use the data and profiling traits
	use ResultData, Profiling;

	// unique identifier
	public string $id;

	// hook name
	public string $hook_name;

	// collection of hook children
	private array $children = [];

	// collection of callbacks
	private array $callbacks = [];

	/**
	 * Log constructor.
	 *
	 * @param  string $id
	 */
	public function __construct( string $id ) {
		$this->id        = $id . '_' . uniqid();
		$this->hook_name = $id;
	}

	/**
	 * Add a child log
	 *
	 * @param  Hook $child
	 *
	 * @return void
	 */
	public final function add_child( Hook $child ): void {
		$this->children[] = $child;
	}

	/**
	 * Attach a callback to this hook
	 *
	 * @param  Callback $cb
	 *
	 * @return void
	 */
	public final function add_callback(Callback $cb): void {
		$this->callbacks[] = $cb;
	}

	/**
	 * Format data to JSON
	 *
	 * @return array
	 */
	public final function data(): array {
		return array_merge( $this->hook_data( $this ), $this->result_data_array() );
	}

	/**
	 * A recursive function to format the hook data, so we can include an infinite number of children
	 *
	 * @param  Hook $h
	 *
	 * @return array
	 */
	private final function hook_data( Hook $h ): array {
		return array_merge( [
			'id'        => $h->id,
			'hook_name' => $h->hook_name,
			'callbacks' => array_map( fn( $cb ) => $cb->data(), $h->callbacks ),
			'children'  => array_map( fn( $child ) => $this->hook_data( $child ), $h->children ),
		], $h->result_data_array() );
	}


}
