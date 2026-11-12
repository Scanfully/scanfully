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
	 * The site options of the network connection that a monitored subsite's
	 * requests read (see Target): the network's Scanfully site id, its access
	 * token and the address it was connected from.
	 */
	public const SITE_ID_OPTION = 'scanfully_connect_site_id';
	public const TOKEN_OPTION   = 'scanfully_connect_access_token';
	public const ADDRESS_OPTION = 'scanfully_connect_address';

	/**
	 * The main site's home option, by network, read once per request.
	 *
	 * @var array<int, string>
	 */
	private static array $main_homes = [];

	/**
	 * The plugin's basename as the must-use plugin of the PHP error monitor
	 * knows it, for a request that stopped before the plugin's main file
	 * loaded.
	 *
	 * @var string
	 */
	private static string $basename = '';

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
		$basename = self::basename();
		if ( ! self::multisite() || '' === $basename ) {
			return false;
		}
		$active = get_site_option( 'active_sitewide_plugins', [] );

		return is_array( $active ) && isset( $active[ $basename ] );
	}

	/**
	 * The plugin's basename, or '' when it is not known yet.
	 *
	 * @return string
	 */
	private static function basename(): string {
		return defined( 'SCANFULLY_PLUGIN_FILE' ) ? plugin_basename( SCANFULLY_PLUGIN_FILE ) : self::$basename;
	}

	/**
	 * Remember the plugin's basename from the must-use plugin.
	 *
	 * @param string $basename The basename.
	 *
	 * @return void
	 */
	public static function use_basename( string $basename ): void {
		self::$basename = $basename;
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
	 * Whether the network connection applies on the main site of a given
	 * network, asked from any site of the install (applies() answers it for
	 * this request): the plugin is network-activated in that network, and
	 * that network is connected.
	 *
	 * @param int $network_id The network's id.
	 *
	 * @return bool
	 */
	public static function applies_on( int $network_id ): bool {
		$basename = self::basename();
		if ( $network_id <= 0 || ! self::multisite() || '' === $basename ) {
			return false;
		}
		$active = get_network_option( $network_id, 'active_sitewide_plugins', [] );

		return is_array( $active )
			&& isset( $active[ $basename ] )
			&& 'yes' === get_network_option( $network_id, self::CONNECTED_OPTION );
	}

	/**
	 * The network connection's access token, read from the network's options
	 * of this request's network, from any site. Only the main site refreshes
	 * it (Cron\Controller, under the network scope); a monitored subsite only
	 * reads it, and reads it again from the database when a request was
	 * refused (`$fresh`), as the main site may have replaced it meanwhile.
	 * Never Options\Controller::with_scope( true ) on a subsite: that would
	 * make the subsite work under the network connection (Manage, sign-ins).
	 *
	 * @param bool $fresh Whether to skip the options cache.
	 *
	 * @return string The token, or '' without one.
	 */
	public static function access_token( bool $fresh = false ): string {
		return self::network_option( self::TOKEN_OPTION, $fresh );
	}

	/**
	 * The network connection's Scanfully site id, read from any site.
	 *
	 * @return string The site id, or '' without one.
	 */
	public static function site_id(): string {
		return self::network_option( self::SITE_ID_OPTION );
	}

	/**
	 * A string option of the network connection, from this request's
	 * network.
	 *
	 * @param string $name  The option's full name.
	 * @param bool   $fresh Whether to skip the options cache.
	 *
	 * @return string
	 */
	private static function network_option( string $name, bool $fresh = false ): string {
		if ( ! self::multisite() ) {
			return '';
		}
		$network = (int) get_current_network_id();
		if ( $fresh ) {
			wp_cache_delete( $network . ':notoptions', 'site-options' );
			wp_cache_delete( $network . ':' . $name, 'site-options' );
		}
		$value = get_network_option( $network, $name, '' );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Whether the network runs at another address than the one its
	 * connection was made from: a copy of the whole network (a staging copy,
	 * a restored backup). The network's stored address is compared with the
	 * main site's home option, from any site of the network, as Address does
	 * for a site's own connection: a subsite's own address never matches
	 * the network's. A monitored subsite sends nothing to its child site on a
	 * copy (see Targets).
	 *
	 * @return bool
	 */
	public static function moved(): bool {
		$stored = self::network_option( self::ADDRESS_OPTION );
		if ( '' === $stored ) {
			return false;
		}
		// An address stored before 2.0 is the address itself, already in
		// the normalized form.
		$stored_hash = 1 === preg_match( '/^[0-9a-f]{64}$/D', $stored ) ? $stored : Address::hash( $stored );

		return ! hash_equals( $stored_hash, Address::hash( Address::normalize( self::main_home() ) ) );
	}

	/**
	 * The main site's home option as stored, read straight from its options
	 * table on another site (never switch_to_blog()), once per request.
	 *
	 * @return string
	 */
	public static function main_home(): string {
		$network = (int) get_current_network_id();
		if ( isset( self::$main_homes[ $network ] ) ) {
			return self::$main_homes[ $network ];
		}
		if ( is_main_site() ) {
			$home = get_option( 'home' );
		} else {
			global $wpdb;
			$table = $wpdb->get_blog_prefix( (int) get_main_site_id() ) . 'options';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One option of the main site, without loading its options; the table name comes from WordPress's prefix and an integer.
			$home = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$table}` WHERE option_name = %s", 'home' ) );
		}
		self::$main_homes[ $network ] = is_string( $home ) ? $home : '';

		return self::$main_homes[ $network ];
	}

	/**
	 * Forget what this request read (tests, and a long-running process).
	 *
	 * @return void
	 */
	public static function forget(): void {
		self::$main_homes = [];
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
