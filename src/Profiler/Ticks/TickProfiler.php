<?php

namespace Scanfully\Profiler\Ticks;


use Scanfully\Profiler\Utils;

class TickProfiler {

	const ticks = 1;
	const debug_backtrace_limit = 2;

	private ?Tick $current_tick = null;

	private array $stack = [];

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
	 * Runs every PHP tick
	 *
	 * @return void
	 */
	public final function tick_handler(): void {

		// get backtrace
		$backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, self::debug_backtrace_limit );

		// remove first item
		array_shift( $backtrace );

		// not enough data
		if ( count( $backtrace ) < 1 ) {
			return;
		}

		// check if we're in the same tick
		$tick = Tick::from_backtrace( $backtrace[0] );
		if ( $this->current_tick != null && $this->current_tick->equals( $tick ) ) {
			// @todo increase timer whatever ,,...,,..
			return;
		}

		// check if there's a file
		if ( $tick->file === null ) {
			return;
		}

		// identify origin
		$file_origin = Utils::identify_file_origin( $tick->file );


		// new tick, set current tick
		$this->current_tick = $tick;

		// add stack to tick stack
//		$this->stack[] = $stack; // todo, check if the last item on the stick is already this
//		error_log( print_r( $backtrace, true ), 0 );
		error_log( sprintf( "%s is %s (%s)", $tick->function ?? 'NOTHING', $file_origin['type'], $file_origin['name'] ) );
	}

	/**
	 * Runs on shutdown of PHP script
	 *
	 * @return void
	 */
	public final function shutdown(): void {
		unregister_tick_function( [ $this, 'tick_handler' ] );
		//error_log( print_r( $this->stack, true ), 0 );
	}

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