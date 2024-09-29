<?php

namespace Scanfully\Profiler;

use Scanfully\Profiler\Data\Callback;
use Scanfully\Profiler\Data\Hook;

/**
 * Profiler class.
 * Heavily inspired by https://github.com/wp-cli/profile-command/blob/main/src/Profiler.php
 *
 * Do NOT use this from within WordPress,
 * it's meant to be used in Scanfully's custom profile file.
 */
class Profiler {

	// hook related
	private array $hooks;
	private array $hook_stack;

	// stages
	private array $stages;

	// the plugins
	private array $plugins;

	// stage hooks, used to check on hook end if we should add that data to a stage data
	private array $stage_hooks = array(
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

	const debug = true;

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
	 * Check and set if not set needed PHP constants
	 *
	 * @return void
	 */
	public function handle_constants(): void {
		self::debug( 'HANDLE_CONSTANTS', 'handling constants' );
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

		// create object with hook name
		$hook = new Data\Hook( $hook_name );

		// get all callbacks for given hook/filter/action/whatever
		$callbacks = self::get_hook_callbacks( $hook_name );

		// check if there are any callbacks
		if ( $callbacks !== null ) {
			// loop through current hooks, and wrap them all within our own func
			foreach ( $callbacks as $priority => $priority_callbacks ) {
				foreach ( $priority_callbacks as $cb_key => $callback ) {
					$callbacks[ $priority ][ $cb_key ] = array(
						'function'      => function () use ( $callback, $cb_key, $hook ) {

							// get callback details
							$cb_details = self::get_callback_details( $callback['function'] );

							// create callback object
							$callback_object = new Callback( $cb_details['name'], $cb_details['file'], $cb_details['line'] );

							// add callback to hook stack
							$hook->add_callback( $callback_object );
							//self::debug( "CB", 'wrapped cb', [ 'cb' => sprintf( "%v", $callback['function'] ) ] );

							// start callback
							$callback_object->start();

							// run original callback
							$value = call_user_func_array( $callback['function'], func_get_args() );

							// stop callback
							$callback_object->stop();

							return $value;
						},
						'accepted_args' => $callback['accepted_args'],
					);
				}
			}
		}

		// override actual hooks
		self::set_hook_callbacks( $hook_name, $callbacks );

		// if current hook stack is empty, this is a 'root' hook
		if ( empty( $this->hook_stack ) ) {
			$this->hooks[] = $hook;
		} else {
			// Otherwise, it's a child hook of the last hook on the stack
			$parent_hook = $this->get_current_hook();
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
			self::debug( 'HOOK_END', 'Hook not started but we\'re in hook_end', [ 'name' => $hook_name, 'stack_count' => $s ] );

			return null;
		}

		// stop the hook
		$hook->stop();

		// check if this hook is part of one of the stages
		foreach ( $this->stage_hooks as $stage_name => $stage_hooks ) {
			foreach ( $stage_hooks as $stage_hook ) {
				if ( $hook->hook_name == $stage_hook ) {
					self::debug( 'STAGE_ADD', 'adding data to stage hook', [ 'name' => $stage_name, 'hook' => $stage_hook ] );
					$this->stages[ $stage_name ]->add( $hook );
				}
			}
		}

		// let's identify the origin of the


		return $filter_value;
	}

	/**
	 * Get the current hook, end of the stack
	 *
	 * @return Hook|null
	 */
	private final function get_current_hook(): ?Hook {
		return count( $this->hook_stack ) > 0 ? end( $this->hook_stack ) : null;
	}

	/**
	 * Generate JSON data for current profiler results
	 *
	 * @return string
	 */
	public final function generate_json(): string {
		$json_data = [ 'stages' => [], 'hooks' => [] ];

		foreach ( $this->stages as $stage ) {
			$json_data['stages'][] = $stage->data();
		}

		foreach ( $this->hooks as $hook ) {
			$json_data['hooks'][] = $hook->data();
		}

		return json_encode( $json_data );
	}

	/**
	 * This way we can add hooks before loading WordPress.
	 *
	 * @param $tag
	 * @param $function_to_add
	 * @param $priority
	 * @param $accepted_args
	 *
	 * @return true
	 */
	public static final function add_wp_hook( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ): bool {
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
	 * Get the details of a callback
	 *
	 * @param  mixed $callback
	 *
	 * @return array
	 */
	private static final function get_callback_details( $callback ): array {
		$name       = '';
		$reflection = false;
		if ( is_array( $callback ) && is_object( $callback[0] ) ) {
			$reflection = new \ReflectionMethod( $callback[0], $callback[1] );
			$name       = get_class( $callback[0] ) . '->' . $callback[1] . '()';
		} elseif ( is_array( $callback ) && method_exists( $callback[0], $callback[1] ) ) {
			$reflection = new \ReflectionMethod( $callback[0], $callback[1] );
			$name       = $callback[0] . '::' . $callback[1] . '()';
		} elseif ( is_object( $callback ) && is_a( $callback, 'Closure' ) ) {
			$reflection = new \ReflectionFunction( $callback );
			$name       = 'function(){}';
		} elseif ( is_string( $callback ) && function_exists( $callback ) ) {
			$reflection = new \ReflectionFunction( $callback );
			$name       = $callback . '()';
		}
		if ( ! $reflection ) {
			return [
				'name' => 'unknown',
				'file' => 'unknown',
				'line' => 0,
			];
		}

		return [
			'name' => $name,
			'file' => $reflection->getFileName(),
			'line' => $reflection->getStartLine(),
		];
	}

	/**
	 * Get the callbacks for a given filter
	 *
	 * @param  string $hook_name
	 *
	 * @return array
	 */
	private static final function get_hook_callbacks( string $hook_name ): ?array {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			return null;
		}

		if ( is_a( $wp_filter[ $hook_name ], 'WP_Hook' ) ) {
			$callbacks = $wp_filter[ $hook_name ]->callbacks;
		} else {
			$callbacks = $wp_filter[ $hook_name ];
		}
		if ( is_array( $callbacks ) ) {
			return $callbacks;
		}

		return null;
	}

	/**
	 * Set the callbacks for a given filter
	 *
	 * @param  string $hook
	 * @param  mixed $callbacks
	 */
	private static function set_hook_callbacks( string $hook, $callbacks ): void {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) && class_exists( 'WP_Hook' ) ) {
			$wp_filter[ $hook ] = new \WP_Hook(); // phpcs:ignore
		}

		if ( is_a( $wp_filter[ $hook ], 'WP_Hook' ) ) {
			$wp_filter[ $hook ]->callbacks = $callbacks;
		} else {
			$wp_filter[ $hook ] = $callbacks; // phpcs:ignore
		}
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
	 * Pretty CLI debugging
	 *
	 * @param  string $key
	 * @param  string $message
	 * @param  array|null $values
	 *
	 * @return void
	 */
	private static final function debug( string $key, string $message, ?array $values = null ): void {
		if ( ! self::debug ) {
			return;
		}
		$fv     = " |" . str_repeat( " ", 5 );
		$spaces = 15;
		if ( $values != null ) {
			foreach ( $values as $k => $v ) {
				$s  = $spaces - strlen( $v );
				$fv .= sprintf( "%s: %s%s", $k, $v, str_repeat( " ", max( $s, 0 ) ) );
			}
		}

		error_log( sprintf( "[PROFILER][%s]: %s %s", $key, $message, $fv ) );
	}
}
