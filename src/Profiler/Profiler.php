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

//	private HookCollection $hook_collection;

	// hook related
	private array $hooks;
	private array $hook_stack;

	// plugins
	private array $stages;

	private $stage_hooks = array(
		'bootstrap'  => array(
			'muplugins_loaded',
			'plugins_loaded',
			'setup_theme',
			'after_setup_theme',
			'init',
			'wp_loaded',
		),
		'main_query' => array(
			'parse_request',
			'send_headers',
			'pre_get_posts',
			'the_posts',
			'wp',
		),
		'template'   => array(
			'template_redirect',
			'template_include',
			'wp_head',
			'loop_start',
			'loop_end',
			'wp_footer',
		),
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->hooks      = [];
		$this->hook_stack = [];
		$this->stages     = [
			'bootstrap'  => new Data\Stage( 'bootstrap' ),
			'main_query' => new Data\Stage( 'main_query' ),
			'template'   => new Data\Stage( 'template' )
		];
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
		$hook_name = current_filter();

		// @todo wrap the callback

		// create object with hook name
		$hook = new Data\Hook( $hook_name );

		// if current hook stack is empty, this is a 'root' hook
		if ( empty( $this->hook_stack ) ) {
			$this->hooks[] = $hook;
		} else {
			// Otherwise, it's a child hook of the last hook on the stack
			$parent_hook = end( $this->hook_stack );
			$parent_hook->add_child( $hook );
		}

		// Push the current event onto the stack
		$this->hook_stack[] = $hook;

		// start the hook
		$hook->start();

		// bind hook_end to the end of this hook
		add_action( $hook_name, [ $this, 'hook_end' ], PHP_INT_MAX );

//		error_log( sprintf( "[START] Hook: %s | Depth: %d", $current_filter, 0 ) );
	}

	/**
	 * Called when a hook is done (end)
	 *
	 * @return void
	 */
	public function hook_end( $filter_value = null ) {

		// get current filter
		$hook_name = current_filter();

		// start hook in log collection
		$s = count( $this->hook_stack );

		// ge the hook from the stack
		$hook = array_pop( $this->hook_stack );

		if ( $hook == null ) {
			error_log( 'Hook not started but we\'re in hook_end: ' . $hook_name . ' | current stack count: ' . $s );

			return null;
		}

		// stop the hook
		$hook->stop();

		// check if this hook is part of one of the stages
		foreach ( $this->stage_hooks as $stage_name => $stage_hooks ) {
			foreach ( $stage_hooks as $stage_hook ) {
				if ( $hook->id == $stage_hook ) {
					error_log( sprintf( "adding to %s for hook %s", $stage_name, $stage_hook ) );
					$this->stages[ $stage_name ]->add( $hook );
				}
			}
		}


		return $filter_value;
	}

	/**
	 * Generate JSON data for current profiler results
	 *
	 * @return string
	 */
	public function generate_json(): string {
		$json_data = [
			'stages' => $this->stages,
			'hook'   => $this->hooks,
		];

		return json_encode( $json_data );
	}
}
