<?php

namespace Scanfully\Profiler;

class LogCollection {

	public array $stages;

	public array $plugins;
	public array $themes;

	public array $hooks;
	public array $hook_stack;

	/**
	 * LogCollection constructor.
	 */
	public function __construct() {

		// stages
		$this->stages = [
			'bootstrap'  => new Log( 'stage_bootstrap' ),
			'main_query' => new Log( 'stage_main_query' ),
			'template'   => new Log( 'stage_template' ),
		];

		// plugins
		$this->plugins = [];

		// themes
		$this->themes = [];

		// hooks
		$this->hooks      = [];
		$this->hook_stack = [];
	}

	/**
	 * Start the log of given stage
	 *
	 * @param  string $stage
	 *
	 * @return void
	 */
	public function start_stage( string $stage ): void {
		if ( ! isset( $this->stages[ $stage ] ) ) {
			error_log( 'Stage not found' );
		}
		$this->stages[ $stage ]->start();
	}

	/**
	 * Stop the log of given stage
	 *
	 * @param  string $stage
	 *
	 * @return void
	 */
	public function stop_stage( string $stage ): void {
		if ( ! isset( $this->stages[ $stage ] ) ) {
			error_log( 'Stage not found' );
		}
		$this->stages[ $stage ]->stop();
	}

	/**
	 * Start the timer of given hook (filter/action)
	 *
	 * @param  string $hook_name
	 *
	 * @return void
	 */
	public function start_hook( string $hook_name ): void {
		$hook = new Log( $hook_name );

		if ( empty( $this->hook_stack ) ) {
			// If the stack is empty, this is a root event
			$this->hooks[] = $hook;
		} else {
			// Otherwise, it's a child event of the last event on the stack
			$parent_hook = end( $this->hook_stack );
			$parent_hook->addChild( $hook );
		}

		// Push the current event onto the stack
		$this->hooks[] = $hook;

		// start the hook
		$hook->start();
	}

	/**
	 * Stop the timer of given hook (filter/action)
	 *
	 * @param  string $hook_name
	 *
	 * @return void
	 */
	public function stop_hook( string $hook_name ): void {

		// ge the hook from the stack
		$hook = array_pop( $this->hook_stack );

		// stop the hook
		$hook->stop();
	}

	/**
	 * Start the timer of given plugin
	 *
	 * @param  string $plugin
	 *
	 * @return void
	 */
	public function start_plugin( string $plugin ): void {
		if ( ! isset( $this->plugins[ $plugin ] ) ) {
			$this->plugins[ $plugin ] = new Log( $plugin );
		}
		$this->plugins[ $plugin ]->start();
	}

	/**
	 * Stop the timer of given plugin
	 *
	 * @param  string $plugin
	 *
	 * @return void
	 */
	public function stop_plugin( string $plugin ): void {
		if ( ! isset( $this->plugins[ $plugin ] ) ) {
			error_log( 'Plugin not started' );
		}
		$this->plugins[ $plugin ]->stop();
	}

	/**
	 * Start the timer of given theme
	 *
	 * @param  string $theme
	 *
	 * @return void
	 */
	public function start_theme( string $theme ): void {
		if ( ! isset( $this->themes[ $theme ] ) ) {
			$this->themes[ $theme ] = new Log( $theme );
		}
		$this->themes[ $theme ]->start();
	}

	/**
	 * Stop the timer of given theme
	 *
	 * @param  string $theme
	 *
	 * @return void
	 */
	public function stop_theme( string $theme ): void {
		if ( ! isset( $this->themes[ $theme ] ) ) {
			error_log( 'Theme not started' );
		}
		$this->themes[ $theme ]->stop();
	}
}
