<?php
/**
 * The PHP error monitoring controller class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Errors;

use Scanfully\MustUse\MuPlugin;

/**
 * Class Controller
 *
 * The WordPress side of PHP error monitoring: the send job, a send right
 * after an update, the must-use plugin's upkeep, and what the site data
 * says about it. It always runs while the site is connected.
 *
 * @package Scanfully\Errors
 */
final class Controller {

	/**
	 * Add the hooks.
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_action( 'upgrader_process_complete', [ self::class, 'send_after_update' ], 10, 0 );
		add_action( 'admin_init', [ MuPlugin::class, 'maybe_install' ] );
		add_action( 'scanfully_options_cleared', [ self::class, 'disconnected' ] );
	}

	/**
	 * `upgrader_process_complete`: send what is waiting right away, so the
	 * errors from before the update are apart from the ones after it.
	 *
	 * @return void
	 */
	public static function send_after_update(): void {
		if ( Monitor::should_run() ) {
			Reporter::schedule( Reporter::TRIGGER_UPDATE );
		}
	}

	/**
	 * The site disconnected: forget the errors and, on a single site, remove
	 * the must-use plugin (on a network other sites may use it; it does
	 * nothing for a site that isn't connected or monitored).
	 *
	 * @return void
	 */
	public static function disconnected(): void {
		// A subsite that a network still monitors keeps its errors for the
		// network's child site; only what the own connection got goes.
		if ( \Scanfully\Connect\Targets::reachable() ) {
			Store::forget_target( \Scanfully\Connect\Target::own( '' ) );
			return;
		}
		Store::delete_all();
		if ( ! is_multisite() ) {
			MuPlugin::remove();
		}
	}

	/**
	 * What the site data says about PHP error monitoring: whether it runs,
	 * and whether it catches errors from before plugins load (`full`, the
	 * must-use plugin) or only from the Scanfully plugin on (`partial`).
	 *
	 * @return array{enabled: bool, coverage: string, mu_plugin: bool}
	 */
	public static function status(): array {
		$enabled = Monitor::should_run();

		return [
			'enabled'   => $enabled,
			'coverage'  => ! $enabled ? 'off' : ( 'mu' === Monitor::origin() ? 'full' : 'partial' ),
			'mu_plugin' => MuPlugin::is_installed(),
		];
	}
}
