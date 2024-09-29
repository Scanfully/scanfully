<?php

namespace Scanfully\Profiler\Data;

/**
 * Data trait
 */
trait Data {

	// total time taken
	public string $display_time = '0.0000';
	public float $time = 0;

	// WP query
	public int $query_count = 0;
	public float $query_time = 0;

	// Cache information
	public int $cache_hits = 0;
	public int $cache_misses = 0;
	public ?string $cache_ratio = null;

	// Hook information
	public int $hook_count = 0;
	public float $hook_time = 0;

	// Request information
	public int $request_count = 0;
	public float $request_time = 0;

	/**
	 * @param  DataInterface $data
	 *
	 * @return void
	 */
	public function add( DataInterface $data ) {
		$this->time          += $data->time;
		$this->query_count   += $data->query_count;
		$this->query_time    += $data->query_time;
		$this->cache_hits    += $data->cache_hits;
		$this->cache_misses  += $data->cache_misses;
		$this->hook_count    += $data->hook_count;
		$this->hook_time     += $data->hook_time;
		$this->request_count += $data->request_count;
		$this->request_time  += $data->request_time;

		$this->format_display_time();
	}

	/**
	 * Calculate cache ratio
	 *
	 * @return void
	 */
	public function calculate_cache_ratio(): void {
		$cache_total = $this->cache_hits + $this->cache_misses;
		if ( $cache_total > 0 ) {
			$ratio             = ( $this->cache_hits / $cache_total ) * 100;
			$this->cache_ratio = round( $ratio, 2 ) . '%';
		}
	}

	/**
	 * Format display time
	 *
	 * @return void
	 */
	public function format_display_time(): void {
		$this->display_time = $this->format_time( $this->time );
	}

	/**
	 * Format a float time to a string with 8 decimal places
	 *
	 * @param  float $time
	 *
	 * @return string
	 */
	private function format_time( float $time ): string {
		return number_format( $time, 8 );
	}
}