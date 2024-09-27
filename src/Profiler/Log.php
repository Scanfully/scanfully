<?php

namespace Scanfully\Profiler;

/**
 * Log class
 *
 * Inspired from https://github.com/wp-cli/profile-command/blob/main/src/Logger.php
 */
class Log {
	public float $time = 0;
	private ?float $start_time = null;

	public int $query_count = 0;
	public float $query_time = 0;
	private ?int $query_offset = null;

	private ?int $cache_hit_offset = null;
	private ?int $cache_miss_offset = null;
	public int $cache_hits = 0;
	public int $cache_misses = 0;
	public ?string $cache_ratio = null;

	public int $hook_count = 0;
	public float $hook_time = 0;
	private ?float $hook_start_time = null;
	private int $hook_depth = 0;

	public int $request_count = 0;
	public float $request_time = 0;
	private ?float $request_start_time = null;


	/**
	 * Start the log
	 *
	 * @return void
	 */
	public function start(): void {
		global $wpdb, $wp_object_cache;
		$this->start_time        = microtime( true );
		$this->query_offset      = ! empty( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
		$this->cache_hit_offset  = ! empty( $wp_object_cache->cache_hits ) ? $wp_object_cache->cache_hits : 0;
		$this->cache_miss_offset = ! empty( $wp_object_cache->cache_misses ) ? $wp_object_cache->cache_misses : 0;
	}

	/**
	 * Stop the log
	 *
	 * @return void
	 */
	public function stop(): void {
		global $wpdb, $wp_object_cache;

		// set total time
		if ( ! is_null( $this->start_time ) ) {
			$this->time += microtime( true ) - $this->start_time;
		}

		// set total query time and count
		if ( ! is_null( $this->query_offset ) && isset( $wpdb ) && ! empty( $wpdb->queries ) ) {

			$query_total_count = count( $wpdb->queries );

			for ( $i = $this->query_offset; $i < $query_total_count; $i ++ ) {
				$this->query_time += $wpdb->queries[ $i ][1];
				++ $this->query_count;
			}
		}

		// set cache hits, misses and ratio
		if ( ! is_null( $this->cache_hit_offset ) && ! is_null( $this->cache_miss_offset ) && isset( $wp_object_cache ) ) {
			$cache_hits         = ! empty( $wp_object_cache->cache_hits ) ? $wp_object_cache->cache_hits : 0;
			$cache_misses       = ! empty( $wp_object_cache->cache_misses ) ? $wp_object_cache->cache_misses : 0;
			$this->cache_hits   = $cache_hits - $this->cache_hit_offset;
			$this->cache_misses = $cache_misses - $this->cache_miss_offset;
			$cache_total        = $this->cache_hits + $this->cache_misses;
			if ( $cache_total ) {
				$ratio             = ( $this->cache_hits / $cache_total ) * 100;
				$this->cache_ratio = round( $ratio, 2 ) . '%';
			}
		}

		// reset all the values
		$this->start_time        = null;
		$this->query_offset      = null;
		$this->cache_hit_offset  = null;
		$this->cache_miss_offset = null;
	}

	/**
	 * Check if the log is running
	 */
	public function running(): bool {
		return ! is_null( $this->start_time );
	}

	/**
	 * Start this logger's request timer
	 *
	 * @return void
	 */
	public function start_request_timer(): void {
		++ $this->request_count;
		$this->request_start_time = microtime( true );
	}

	/**
	 * Stop this logger's request timer
	 *
	 * @return void
	 */
	public function stop_request_timer(): void {
		if ( ! is_null( $this->request_start_time ) ) {
			$this->request_time += microtime( true ) - $this->request_start_time;
		}
		$this->request_start_time = null;
	}

	/**
	 * Start this logger's hook timer
	 *
	 * @todo check if this is needed
	 *
	 * @return void
	 */
	public function start_hook_timer(): void {
		++ $this->hook_count;
		// Timer already running means a subhook has been called
		if ( ! is_null( $this->hook_start_time ) ) {
			++ $this->hook_depth;
		} else {
			$this->hook_start_time = microtime( true );
		}
	}

	/**
	 * Stop this logger's hook timer
	 *
	 * @todo check if this is needed
	 *
	 * @return void
	 */
	public function stop_hook_timer():void {
		if ( $this->hook_depth ) {
			-- $this->hook_depth;
		} else {
			if ( ! is_null( $this->hook_start_time ) ) {
				$this->hook_time += microtime( true ) - $this->hook_start_time;
			}
			$this->hook_start_time = null;
		}
	}

}
