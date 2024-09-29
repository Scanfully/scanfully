<?php

namespace Scanfully\Profiler\Data;

/**
 * HookLog class
 *
 * Inspired from https://github.com/wp-cli/profile-command/blob/main/src/Logger.php
 */
class Hook implements DataInterface {

	// use the data trait
	use Data;

	// unique identifier
	public string $id;

	// hook name
	public string $hook_name;

	// children log objects
	private array $children = [];

	// internal
	private ?float $start_time = null;

	// internal
	private ?int $query_offset = null;

	// internal
	private ?int $cache_hit_offset = null;
	private ?int $cache_miss_offset = null;

	// internal
	private ?float $hook_start_time = null;
	private int $hook_depth = 0;

	// internal
	private ?float $request_start_time = null;

	/**
	 * Log constructor.
	 *
	 * @param  string $id
	 */
	public function __construct( string $id ) {
		$this->id        = $id;
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
	 * Start the log
	 *
	 * @return void
	 */
	public final function start(): void {
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
	public final function stop(): void {
		global $wpdb, $wp_object_cache;

		// set total time
		if ( ! is_null( $this->start_time ) ) {
			$this->time += microtime( true ) - $this->start_time;
			$this->format_display_time();
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
	public final function running(): bool {
		return ! is_null( $this->start_time );
	}

	/**
	 * Start this logger's request timer
	 *
	 * @return void
	 */
	public final function start_request_timer(): void {
		++ $this->request_count;
		$this->request_start_time = microtime( true );
	}

	/**
	 * Stop this logger's request timer
	 *
	 * @return void
	 */
	public final function stop_request_timer(): void {
		if ( ! is_null( $this->request_start_time ) ) {
			$this->request_time += microtime( true ) - $this->request_start_time;
		}
		$this->request_start_time = null;
	}

}
