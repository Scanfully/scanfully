<?php

namespace Scanfully\Profiler;

/**
 * Profiler class.
 * Heavily inspired by https://github.com/wp-cli/profile-command/blob/main/src/Profiler.php
 *
 * Do NOT use this from within WordPress,
 * it's meant to be used in Scanfully's custom profile file.
 */
class Profiler {

	private LogCollection $collection;

//	private int $hook_depth = 0;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->collection = new LogCollection();
	}

	/**
	 * Mimics the wp_hook_build_unique_id function from WordPress core.
	 *
	 * @param $tag
	 * @param $function
	 * @param $priority
	 *
	 * @return string
	 */
	private static function wp_hook_build_unique_id( $tag, $function, $priority ): string {
		global $wp_filter;
		static $filter_id_count = 0;

		if ( is_string( $function ) ) {
			return $function;
		}

		if ( is_object( $function ) ) {
			// Closures are currently implemented as objects
			$function = [ $function, '' ];
		} else {
			$function = (array) $function;
		}

		if ( is_object( $function[0] ) ) {
			// Object Class Calling
			if ( function_exists( 'spl_object_hash' ) ) {
				return spl_object_hash( $function[0] ) . $function[1];
			}

			$obj_idx = get_class( $function[0] ) . $function[1];
			if ( ! isset( $function[0]->wp_filter_id ) ) {
				if ( false === $priority ) {
					return '';
				}
				$obj_idx                   .= isset( $wp_filter[ $tag ][ $priority ] ) ? count( (array) $wp_filter[ $tag ][ $priority ] ) : $filter_id_count;
				$function[0]->wp_filter_id = $filter_id_count;
				++ $filter_id_count;
			} else {
				$obj_idx .= $function[0]->wp_filter_id;
			}

			return $obj_idx;
		}

		if ( is_string( $function[0] ) ) {
			// Static Calling
			return $function[0] . '::' . $function[1];
		}

		return '';
	}

	/**
	 * Method forked from CLI. This way we can add hooks before loading WordPress.
	 *
	 * @param $tag
	 * @param $function_to_add
	 * @param $priority
	 * @param $accepted_args
	 *
	 * @return true
	 */
	public static function add_wp_hook( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ): bool {
		global $wp_filter, $merged_filters;

		if ( function_exists( 'add_filter' ) ) {
			add_filter( $tag, $function_to_add, $priority, $accepted_args );
		} else {
			$idx = self::wp_hook_build_unique_id( $tag, $function_to_add, $priority );

			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This is intentional & the purpose of this function.
			$wp_filter[ $tag ][ $priority ][ $idx ] = [
				'function'      => $function_to_add,
				'accepted_args' => $accepted_args,
			];
			unset( $merged_filters[ $tag ] );
		}

		return true;
	}

	/**
	 * Check and set if not set needed PHP constants
	 *
	 * @return void
	 */
	public function handle_constants(): void {
		error_log( 'handle_constants' );

		if ( defined( 'SAVEQUERIES' ) && ! SAVEQUERIES ) {
			die( "'SAVEQUERIES' is defined as false, and must be true. Please check your wp-config.php" );
		}
		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}
	}

	/**
	 * Listen for hooks.
	 *
	 * @return void
	 */
	public function listen(): void {
		self::add_wp_hook( 'all', [ $this, 'hook_begin' ], 1, 0 );

		// @todo: add request begin and end hooks
	}

	/**
	 * Called on all filters and actions
	 *
	 * @return void
	 */
	public function hook_begin(): void {

		// get current filter
		$current_filter = current_filter();

		// one level down in hook depth
//		++$this->hook_depth;

		// bind hook_end to the end of this hook
		add_action( $current_filter, [ $this, 'hook_end' ], PHP_INT_MAX );

		error_log( sprintf( "[START] Hook: %s | Depth: %d", $current_filter, 0 ) );
	}

	/**
	 * Called when a hook is done (end)
	 *
	 * @return void
	 */
	public function hook_end( $filter_value = null ) {
		$current_filter = current_filter();

		error_log( sprintf( "[END] Hook: %s | Depth: %d", $current_filter, 0 ) );

		// one level up in hook depth
//		--$this->hook_depth;

		return $filter_value;
	}

}
