<?php

namespace Scanfully\Profiler\Ticks;


use Scanfully\Profiler\Data\Plugin;
use Scanfully\Profiler\Data\ProfilingInterface;
use Scanfully\Profiler\Data\Theme;
use Scanfully\Profiler\Utils;

class TickProfiler {

	const ticks = 1;
	const debug_backtrace_limit = 2;

	private ?Tick $current_tick = null;

	private ?ProfilingInterface $current_profiling = null;

	/**
	 * @var array key is plugin slug, value is Data\Plugin
	 */
	private array $plugins = [];

	/**
	 * @var array key is theme slug, value is Data\Theme
	 */
	private array $themes = [];

	//private array $stack = [];

	/**
	 * Start time
	 *
	 * @return void
	 */
	public final function start(): void {
		// set ticks
		register_tick_function( [ $this, 'tick_handler' ] );

		// disable code optimizers
		$this->disable_code_optimizers();

		// register shutdown function
		register_shutdown_function( [ $this, 'shutdown' ] );
	}

	/**
	 * @return array
	 */
	public final function get_plugins(): array {
		$d = [];
		foreach ( $this->plugins as $p ) {
			$d[] = $p->data();
		}

		return $d;
	}

	/**
	 * @return array
	 */
	public final function get_themes(): array {
		$d = [];
		foreach ( $this->themes as $t ) {
			$d[] = $t->data();
		}

		return $d;
	}

	/**
	 * Runs every PHP tick
	 *
	 * @return void
	 */
	public final function tick_handler(): void {

		// get backtrace
		$backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, self::debug_backtrace_limit );

		// get the "real" source of
		if ( isset( $backtrace[1]['function'] ) &&
		     isset( $backtrace[0]['function'] ) && $backtrace[0]['function'] == __FUNCTION__ ) {

			// class method or function
			if ( isset( $backtrace[1]['class'] ) && isset( $backtrace[1]['type'] ) ) {
				if ( method_exists( $backtrace[1]['class'], $backtrace[1]['function'] ) ) {
					$rm                   = new \ReflectionMethod( $backtrace[1]['class'], $backtrace[1]['function'] );
					$backtrace[1]['line'] = $rm->getStartLine();
					$backtrace[1]['file'] = $backtrace[0]['file'];
				}
			} elseif ( function_exists( $backtrace[1]['function'] ) ) {
				$rm                   = new \ReflectionFunction( $backtrace[1]['function'] );
				$backtrace[1]['line'] = $rm->getStartLine();
				$backtrace[1]['file'] = $backtrace[0]['file'];
			}

			// check for closures / anon functions
			if ( strpos( $backtrace[1]['function'], '{closure}' ) !== false ) {
				$backtrace[1]['line'] = $backtrace[0]['line'];
				$backtrace[1]['file'] = $backtrace[0]['file'];
			}
		}

		// remove first item
		array_shift( $backtrace );

		// not enough data
		if ( count( $backtrace ) < 1 ) {
			return;
		}

		// check if we're not in our own function
		if ( isset( $backtrace[0]['function'] ) && $backtrace[0]['function'] == __FUNCTION__ ) {
			return;
		}

		// check we're not in profile.php
		if ( isset( $backtrace[0]['file'] ) && strpos( $backtrace[0]['file'], "scanfully/profile.php" ) > 0 ) {
			return;
		}

		// check if this tick is in the same function as the last tick
		$tick = Tick::from_backtrace( $backtrace[0] );

		if ( $this->current_tick != null && $this->current_tick->same_function( $tick ) ) {

			return;
		}

		// it's a different function, stop the current profiling
		if ( $this->current_profiling !== null ) {
			$this->current_profiling->stop();
			$this->current_profiling = null;
		}

		// check if there's a file
		if ( $tick->file === null ) {
			return;
		}

		// identify origin
		$file_origin = Utils::identify_file_origin( $tick->file );

		switch ( $file_origin['type'] ) {
			case 'plugin':
			case 'muplugin':
				if ( ! isset( $this->plugins[ $file_origin['name'] ] ) ) {
					$this->plugins[ $file_origin['name'] ] = new Plugin( $file_origin['name'], $file_origin['type'] === 'muplugin' );
				}

				// set current profiling
				$this->current_profiling = $this->plugins[ $file_origin['name'] ];
				
				break;
			case 'theme':
				if ( ! isset( $this->themes[ $file_origin['name'] ] ) ) {
					$this->themes[ $file_origin['name'] ] = new Theme( $file_origin['name'] );
				}

				// set current profiling
				$this->current_profiling = $this->themes[ $file_origin['name'] ];

				break;
		}

		// start whatever we're profiling
		if ( $this->current_profiling !== null ) {
			$this->current_profiling->start();
		}

		// new tick, set current tick
		$this->current_tick = $tick;

		// add stack to tick stack
//		$this->stack[] = $stack; // todo, check if the last item on the stick is already this
//		error_log( print_r( $backtrace, true ), 0 );

	}

	/**
	 * Runs on shutdown of PHP script
	 *
	 * @return void
	 */
	public final function shutdown(): void {

		// close last profiler if we have one
		if ( $this->current_profiling !== null ) {
			$this->current_profiling->stop();
		}

		unregister_tick_function( [ $this, 'tick_handler' ] );
	}

	/**
	 * Disable known code optimizers, as they can hide / edit calls from the tick handler
	 *
	 * @return void
	 */
	private function disable_code_optimizers(): void {
//		if ( version_compare( PHP_VERSION, '7.0.0' ) >= 0 ) {
//			error_log( 'Profiling intermediate hooks is broken in PHP 7, see https://bugs.php.net/bug.php?id=72966' );
//		}

		// Disable opcode optimizers.  These "optimize" calls out of the stack
		// and hide calls from the tick handler and backtraces.
		// Copied from P3 Profiler
		if ( extension_loaded( 'xcache' ) ) {
			@ini_set( 'xcache.optimizer', false ); // phpcs:ignore
			// WordPress.PHP.NoSilencedErrors.Discouraged -- ini_set can be disabled on server.
		} elseif ( extension_loaded( 'apc' ) ) {
			@ini_set( 'apc.optimization', 0 ); // phpcs:ignore
			// WordPress.PHP.NoSilencedErrors.Discouraged -- ini_set can be disabled on server.
			apc_clear_cache();
		} elseif ( extension_loaded( 'eaccelerator' ) ) {
			@ini_set( 'eaccelerator.optimizer', 0 ); // phpcs:ignore
			// WordPress.PHP.NoSilencedErrors.Discouraged -- ini_set can be disabled on server.
			if ( function_exists( 'eaccelerator_optimizer' ) ) {
				@eaccelerator_optimizer( false ); // phpcs:ignore
				// WordPress.PHP.NoSilencedErrors.Discouraged -- disabling eaccelerator on runtime can faild
			}
		} elseif ( extension_loaded( 'Zend Optimizer+' ) ) {
			@ini_set( 'zend_optimizerplus.optimization_level', 0 ); // phpcs:ignore
			// WordPress.PHP.NoSilencedErrors.Discouraged -- ini_set can be disabled on server.
		}
	}

}