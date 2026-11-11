<?php
/**
 * The connection of a whole multisite network.
 *
 * @package Scanfully
 */

namespace Scanfully\Connect;

use Scanfully\Options\Controller as OptionsController;

/**
 * A WordPress multisite network can be connected to Scanfully as a whole,
 * from Network Admin › Settings › Scanfully, by a super admin, on a plugin
 * that is network-activated. That connection lives in the network's own
 * storage (site options), which only Network Admin writes: a subsite's
 * administrator can neither make nor change it. It is the only connection on
 * a network that runs Scanfully Manage and signs anyone in (WP admin access,
 * as a super admin into Network Admin), because those act on every site of
 * the network.
 *
 * The network connection works from the main site: its reports, jobs and
 * sign-ins run there, and it takes over the main site's own connection (made
 * from the main site's Settings › Scanfully page) when it is made. Every
 * other site of the network keeps its own connection, for monitoring only
 * (see Multisite).
 */
class Network {

	/**
	 * The kinds of connection the plugin tells Scanfully about, when it starts
	 * one and with its capabilities.
	 */
	public const KIND_SINGLE  = 'single';
	public const KIND_SUBSITE = 'subsite';
	public const KIND_NETWORK = 'network';

	/**
	 * The site option that marks a stored network connection.
	 */
	public const CONNECTED_OPTION = 'scanfully_connect_is_connected';

	/**
	 * Set up the Network Admin page.
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_action( 'network_admin_menu', [ self::class, 'register_page' ] );
	}

	/**
	 * Register Network Admin › Settings › Scanfully, for super admins, when the
	 * plugin is network-activated.
	 *
	 * @return void
	 */
	public static function register_page(): void {
		if ( ! self::activated() ) {
			return;
		}
		$page_hook = add_submenu_page(
			'settings.php',
			__( 'Scanfully', 'scanfully' ),
			__( 'Scanfully', 'scanfully' ),
			'manage_network_options',
			Page::SLUG,
			[ Page::class, 'render_network_page' ]
		);
		if ( false !== $page_hook ) {
			add_action( 'load-' . $page_hook, [ Page::class, 'enqueue_page_assets' ] );
		}
	}

	/**
	 * Whether WordPress runs as a multisite network.
	 *
	 * @return bool
	 */
	public static function multisite(): bool {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	/**
	 * Whether the plugin is network-activated (active on every site of the
	 * network), which a network connection needs.
	 *
	 * @return bool
	 */
	public static function activated(): bool {
		if ( ! self::multisite() || ! defined( 'SCANFULLY_PLUGIN_FILE' ) ) {
			return false;
		}
		$active = get_site_option( 'active_sitewide_plugins', [] );

		return is_array( $active ) && isset( $active[ plugin_basename( SCANFULLY_PLUGIN_FILE ) ] );
	}

	/**
	 * Whether a network connection is stored.
	 *
	 * @return bool
	 */
	public static function connected(): bool {
		return self::multisite() && 'yes' === get_site_option( self::CONNECTED_OPTION );
	}

	/**
	 * Whether the plugin works under the network connection here: on the
	 * network's main site, with the plugin network-activated and the network
	 * connected. Everywhere else a site works under its own connection.
	 *
	 * @return bool
	 */
	public static function applies(): bool {
		return self::multisite() && is_main_site() && self::activated() && self::connected();
	}

	/**
	 * Whether this request is a Network Admin screen.
	 *
	 * @return bool
	 */
	public static function in_network_admin(): bool {
		return function_exists( 'is_network_admin' ) && is_network_admin();
	}

	/**
	 * Whether the current user may connect, change or disconnect the network
	 * connection: a super admin (manage_network_options), in Network Admin, on
	 * a network-activated plugin.
	 *
	 * @return bool
	 */
	public static function user_may_manage(): bool {
		return self::in_network_admin() && self::activated() && current_user_can( 'manage_network_options' );
	}

	/**
	 * The kind of connection a connection request started here asks for: the
	 * network's from Network Admin, a subsite's on any other site of a
	 * network, a single site's otherwise.
	 *
	 * @return string
	 */
	public static function request_kind(): string {
		if ( self::in_network_admin() ) {
			return self::KIND_NETWORK;
		}

		return self::multisite() ? self::KIND_SUBSITE : self::KIND_SINGLE;
	}

	/**
	 * The kind of connection the plugin works under here, as it reports it
	 * with its capabilities.
	 *
	 * @return string
	 */
	public static function kind(): string {
		if ( ! self::multisite() ) {
			return self::KIND_SINGLE;
		}

		return OptionsController::network_scope() ? self::KIND_NETWORK : self::KIND_SUBSITE;
	}

	/**
	 * The capability that may change the connection that applies here: a
	 * super admin's for the network connection, a site administrator's for
	 * the site's own.
	 *
	 * @return string
	 */
	public static function manage_capability(): string {
		return OptionsController::network_scope() || self::in_network_admin() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * The Network Admin page of the connection.
	 *
	 * @return string
	 */
	public static function page_url(): string {
		return network_admin_url( 'settings.php?page=' . Page::SLUG );
	}

	/**
	 * What the Network Admin page says about the network connection.
	 *
	 * @return string
	 */
	public static function notice(): string {
		return __( 'This connects the whole multisite network to Scanfully. The main site is monitored through it, and Scanfully Manage and WP admin access act on every site of the network. A connection the main site already has is replaced.', 'scanfully' );
	}

	/**
	 * What the main site's own settings page says while the network
	 * connection applies.
	 *
	 * @return string
	 */
	public static function main_site_note(): string {
		return __( 'This site is connected to Scanfully through the network connection, which a network administrator manages in Network Admin › Settings › Scanfully.', 'scanfully' );
	}
}
