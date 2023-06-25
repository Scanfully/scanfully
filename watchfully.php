<?php
/*
 * Watchfully WordPress plugin
 *
 * @package   Watchfully\Main
 * @copyright Copyright (C) 2023, Watchfully BV - support@watchfully.com
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License, version 3 or higher
 *
 * @wordpress-plugin
 * Plugin Name: Watchfully
 * Version:     1.0.0
 * Plugin URI:  https://www.watchfully.com
 * Description: Watchfully is your favorite WordPress performance and health monitoring tool.
 * Author:      Watchfully
 * Author URI:  https://www.watchfully.com
 * Text Domain: watchfully
 * Domain Path: /languages/
 * License:     GPL v3
 * Requires at least: 6.0
 * Requires PHP: 7.2.5
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

/** @TODO Add PHP version check */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly


function Watchfully(): \Watchfully\Main {
	return \Watchfully\Main::get();
}

add_action( 'plugins_loaded', function () {
	// meta
	define( "WATCHFULLY_PLUGIN_FILE", __FILE__ );
	define( "WATCHFULLY_VERSION", "1.0.0" );

	// boot
	require 'vendor/autoload.php';
	Watchfully()->setup();
}, 20 );