<?php
/**
 * Scanfully WordPress plugin
 *
 * @package   Scanfully\Main
 * @copyright Copyright (C) 2023-2026, Scanfully BV - support@scanfully.com
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License, version 3 or higher
 *
 * @wordpress-plugin
 * Plugin Name: Scanfully
 * Version:     1.10.0
 * Plugin URI:  https://scanfully.com/wp-plugin
 * Description: Scanfully is your favorite WordPress performance and health monitoring tool.
 * Author:      Scanfully
 * Author URI:  https://scanfully.com/
 * Text Domain: scanfully
 * Domain Path: /languages/
 * License:     GPL v3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

// Load Action Scheduler before plugins_loaded priority 0 so it can register its version.
require __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

// Catch PHP errors from here on. The must-use plugin, when it could be
// installed, already does from before any plugin loads.
require_once __DIR__ . '/src/Errors/load.php';
\Scanfully\Errors\Monitor::boot( 'plugin' );

// Declare compatibility with WooCommerce's HPOS order storage and the
// Cart/Checkout blocks. WooCommerce only accepts this inside
// before_woocommerce_init.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Get the main plugin instance.
 *
 * @return \Scanfully\Main
 */
function Scanfully(): \Scanfully\Main { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Public API; renaming would break backwards compatibility.
	return \Scanfully\Main::get();
}

// boot.
add_action(
	'plugins_loaded',
	function () {
		// meta.
		define( 'SCANFULLY_PLUGIN_FILE', __FILE__ );
		define( 'SCANFULLY_VERSION', '1.10.0' );

		// boot.
		require __DIR__ . '/vendor/autoload.php';
		Scanfully()->setup();
	},
	20
);

// register activation hook.
register_activation_hook(
	__FILE__,
	function () {
		// A connection that stayed while the plugin was inactive catches PHP
		// errors again from before other plugins load.
		\Scanfully\Errors\MuPlugin::maybe_install();

		// The connection was last used before the deactivation, so its first
		// report needs a moment before anything calls it stale.
		set_transient( 'scanfully_recently_activated', 1, 5 * MINUTE_IN_SECONDS );
	}
);

// register deactivation hook.
register_deactivation_hook(
	__FILE__,
	function ( $network_wide = false ) {
		// Tell Scanfully first, so the site's owner learns why the plugin
		// stopped reporting.
		if ( class_exists( '\\Scanfully\\Connect\\Controller' ) ) {
			\Scanfully\Connect\Controller::report_deactivated();
		}
		\Scanfully\Cron\Controller::clear_scheduled_events();

		// The must-use plugin serves the whole network: remove it when the
		// plugin is deactivated on a single site or for the whole network.
		// On a network site it does nothing while the plugin is inactive there.
		if ( ! is_multisite() || $network_wide ) {
			\Scanfully\Errors\MuPlugin::remove();
		}
	}
);
