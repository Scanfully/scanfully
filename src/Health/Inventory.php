<?php
/**
 * The inventory class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Health;

/**
 * Class Inventory
 *
 * The software inventory Scanfully Manage reads, sent with the site data
 * sync: for every plugin and theme its available update, whether WordPress
 * has a package to download for it, the host the package comes from, its
 * requirements and whether WordPress updates it by itself; WordPress core
 * with its latest release and the latest release of its own major line; and
 * how many translation updates wait.
 *
 * Everything comes from WordPress's own update transients and the plugin
 * and theme headers, as the Updates screen shows them: no request of our
 * own. An update the transient announces without a package is reported as
 * package false, which is the site telling Scanfully it cannot retrieve the
 * update (a license or sign-in it does not have). The package URL itself is
 * never sent, only its host: a premium package URL can carry a license key.
 *
 * Nothing here needs a Manage scope: a site connected with read access only
 * reports the inventory too.
 *
 * @package Scanfully\Health
 */
final class Inventory {

	/**
	 * The update fields of a plugin, from its entry in the update_plugins
	 * transient and its headers.
	 *
	 * @param mixed                $updates     The update_plugins site transient.
	 * @param string               $plugin_path The plugin's file, relative to the plugins folder.
	 * @param array<string, mixed> $headers     The plugin's headers, as get_plugins() returns them.
	 *
	 * @return array{package: bool|null, source: string|null, requires_php: string|null, requires_wp: string|null, update_requires_php: string|null, update_requires_wp: string|null, auto_update: bool|null}
	 */
	public static function plugin_fields( $updates, string $plugin_path, array $headers ): array {
		$update = self::update_entry( $updates, $plugin_path );

		return array_merge(
			self::update_fields( $update ),
			[
				'requires_php' => self::text( $headers['RequiresPHP'] ?? null ),
				'requires_wp' => self::text( $headers['RequiresWP'] ?? null ),
				'auto_update' => self::auto_update( 'plugin', $plugin_path, self::plugin_payload( $updates, $plugin_path, $headers ) ),
			]
		);
	}

	/**
	 * The installed themes, as WordPress sees them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function themes(): array {
		if ( ! function_exists( 'wp_get_themes' ) ) {
			return [];
		}

		$updates = get_site_transient( 'update_themes' );
		$active  = get_stylesheet();
		$map     = [];

		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$stylesheet = (string) $stylesheet;
			$template   = (string) $theme->get_template();
			$update     = self::update_entry( $updates, $stylesheet );

			$map[] = array_merge(
				[
					'stylesheet' => $stylesheet,
					'name' => (string) $theme->get( 'Name' ),
					'version' => (string) $theme->get( 'Version' ),
					'latest_version' => self::latest_version( $updates, $stylesheet ),
					'active' => $stylesheet === $active,
					'parent' => '' !== $template && $template !== $stylesheet ? $template : null,
					'requires_php' => self::text( $theme->get( 'RequiresPHP' ) ),
					'requires_wp' => self::text( $theme->get( 'RequiresWP' ) ),
					'auto_update' => self::auto_update( 'theme', $stylesheet, self::theme_payload( $updates, $stylesheet, $theme ) ),
				],
				self::update_fields( $update )
			);
		}

		return $map;
	}

	/**
	 * WordPress core: the installed version, the latest release the update
	 * check offers, the latest release of the installed major line (a
	 * security release of 6.4 for a site on 6.4.2), whether those have a
	 * package, and how WordPress updates itself.
	 *
	 * @param string|null $installed The installed version; the running one when null.
	 *
	 * @return array{installed: string, latest: string|null, same_line_latest: string|null, package: bool|null, same_line_package: bool|null, auto_update: string}
	 */
	public static function core( ?string $installed = null ): array {
		$installed = null === $installed ? (string) get_bloginfo( 'version' ) : $installed;
		$line      = self::major_line( $installed );
		$updates   = get_site_transient( 'update_core' );
		$offers    = is_object( $updates ) && isset( $updates->updates ) && is_array( $updates->updates ) ? $updates->updates : [];

		$latest    = null;
		$same_line = null;
		$packages  = [];
		foreach ( $offers as $offer ) {
			$response = self::field( $offer, 'response' );
			$version  = self::field( $offer, 'current' );
			if ( ! in_array( $response, [ 'upgrade', 'latest', 'autoupdate' ], true ) || ! is_string( $version ) || '' === $version ) {
				continue;
			}
			// An offer can come once per locale: any with a package will do.
			$packages[ $version ] = ( $packages[ $version ] ?? false ) || self::core_package( $offer );
			if ( null === $latest || version_compare( $version, $latest, '>' ) ) {
				$latest = $version;
			}
			if ( self::major_line( $version ) === $line && ( null === $same_line || version_compare( $version, $same_line, '>' ) ) ) {
				$same_line = $version;
			}
		}

		return [
			'installed' => $installed,
			'latest' => $latest,
			'same_line_latest' => $same_line,
			'package' => null === $latest ? null : $packages[ $latest ],
			'same_line_package' => null === $same_line ? null : $packages[ $same_line ],
			'auto_update' => self::core_auto_update( $installed ),
		];
	}

	/**
	 * How many translation updates WordPress offers for core, plugins and
	 * themes together.
	 *
	 * @return array{available: int|null}
	 */
	public static function translations(): array {
		// wp-includes/update.php, which wp-settings.php loads; loaded here
		// when something runs the sync without it.
		if ( ! function_exists( 'wp_get_translation_updates' ) && defined( 'ABSPATH' ) && defined( 'WPINC' ) && file_exists( ABSPATH . WPINC . '/update.php' ) ) {
			require_once ABSPATH . WPINC . '/update.php';
		}
		if ( ! function_exists( 'wp_get_translation_updates' ) ) {
			return [ 'available' => null ];
		}

		return [ 'available' => count( wp_get_translation_updates() ) ];
	}

	/**
	 * The latest version of a plugin or theme as WordPress's own update check
	 * knows it, which covers premium plugins with their own updater too.
	 *
	 * @param mixed  $updates The update_plugins or update_themes site transient.
	 * @param string $key     The plugin's file or the theme's stylesheet.
	 *
	 * @return string|null The latest version, or null when WordPress does not know it.
	 */
	public static function latest_version( $updates, string $key ): ?string {
		if ( ! is_object( $updates ) ) {
			return null;
		}

		// "response" holds the items with an update, "no_update" the ones
		// that are up to date.
		foreach ( [ 'response', 'no_update' ] as $list ) {
			$items   = $updates->{$list} ?? null;
			$item    = is_array( $items ) ? ( $items[ $key ] ?? null ) : null;
			$version = self::field( $item, 'new_version' );
			if ( is_scalar( $version ) && '' !== (string) $version ) {
				return (string) $version;
			}
		}

		return null;
	}

	/**
	 * The entry of an item with an update in an update transient: the
	 * "response" list only. Some third-party updaters add an array instead of
	 * an object; either is returned as it is.
	 *
	 * @param mixed  $updates The update_plugins or update_themes site transient.
	 * @param string $key     The plugin's file or the theme's stylesheet.
	 *
	 * @return array<string, mixed>|object|null
	 */
	private static function update_entry( $updates, string $key ) {
		if ( ! is_object( $updates ) || ! isset( $updates->response ) || ! is_array( $updates->response ) ) {
			return null;
		}
		$item = $updates->response[ $key ] ?? null;

		return is_array( $item ) || is_object( $item ) ? $item : null;
	}

	/**
	 * The entry of an item in an update transient, with an update
	 * ("response") or without ("no_update"); null when it has none.
	 *
	 * @param mixed  $updates The update_plugins or update_themes site transient.
	 * @param string $key     The plugin's file or the theme's stylesheet.
	 *
	 * @return array<string, mixed>|object|null
	 */
	private static function transient_entry( $updates, string $key ) {
		$entry = self::update_entry( $updates, $key );
		if ( null !== $entry || ! is_object( $updates ) || ! isset( $updates->no_update ) || ! is_array( $updates->no_update ) ) {
			return $entry;
		}
		$item = $updates->no_update[ $key ] ?? null;

		return is_array( $item ) || is_object( $item ) ? $item : null;
	}

	/**
	 * The fields of an available update: whether it has a package, the
	 * package's host, and what the update requires. All null without an
	 * update.
	 *
	 * @param array<string, mixed>|object|null $update The update's transient entry.
	 *
	 * @return array{package: bool|null, source: string|null, update_requires_php: string|null, update_requires_wp: string|null}
	 */
	private static function update_fields( $update ): array {
		if ( null === $update ) {
			return [
				'package' => null,
				'source' => null,
				'update_requires_php' => null,
				'update_requires_wp' => null,
			];
		}

		$package = self::field( $update, 'package' );
		$package = is_string( $package ) ? trim( $package ) : '';

		return [
			'package' => '' !== $package,
			'source' => '' !== $package ? self::host( $package ) : null,
			'update_requires_php' => self::text( self::field( $update, 'requires_php' ) ),
			'update_requires_wp' => self::text( self::field( $update, 'requires' ) ),
		];
	}

	/**
	 * The host of a package URL, lowercased, without user, port, path or
	 * query; null when it has none.
	 *
	 * @param string $url The package URL.
	 *
	 * @return string|null
	 */
	public static function host( string $url ): ?string {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) && '' !== $host ? strtolower( $host ) : null;
	}

	/**
	 * The item the auto_update_plugin filter gets, padded as the Plugins
	 * screen pads it (WP_Plugins_List_Table::prepare_items): the defaults a
	 * filter may read, such as slug and new_version, merged with the
	 * plugin's headers and its update check entry. Filters written for that
	 * screen read these fields without checking them first.
	 *
	 * @param mixed                $updates     The update_plugins site transient.
	 * @param string               $plugin_path The plugin's file.
	 * @param array<string, mixed> $headers     The plugin's headers.
	 *
	 * @return object
	 */
	private static function plugin_payload( $updates, string $plugin_path, array $headers ) {
		$defaults = [
			'id' => $plugin_path,
			'slug' => '',
			'plugin' => $plugin_path,
			'new_version' => '',
			'url' => '',
			'package' => '',
			'icons' => [],
			'banners' => [],
			'banners_rtl' => [],
			'tested' => '',
			'requires_php' => '',
			'compatibility' => new \stdClass(),
		];
		$entry    = self::transient_entry( $updates, $plugin_path );

		return (object) wp_parse_args( array_merge( null === $entry ? [] : (array) $entry, $headers ), $defaults );
	}

	/**
	 * The item the auto_update_theme filter gets, as the Themes screen builds
	 * it (wp_prepare_themes_for_js): the theme's update check entry, or for a
	 * theme the check does not know, the defaults built from its headers.
	 *
	 * @param mixed     $updates    The update_themes site transient.
	 * @param string    $stylesheet The theme's stylesheet.
	 * @param \WP_Theme $theme      The theme.
	 *
	 * @return object
	 */
	private static function theme_payload( $updates, string $stylesheet, $theme ) {
		$defaults = [
			'theme' => $stylesheet,
			'new_version' => $theme->get( 'Version' ),
			'url' => '',
			'package' => '',
			'requires' => $theme->get( 'RequiresWP' ),
			'requires_php' => $theme->get( 'RequiresPHP' ),
		];
		$entry    = self::transient_entry( $updates, $stylesheet );

		return (object) wp_parse_args( null === $entry ? [] : (array) $entry, $defaults );
	}

	/**
	 * Whether WordPress updates a plugin or theme by itself, decided as
	 * WP_Automatic_Updater::should_update() decides it: the item's
	 * autoupdate flag, else the auto_update_plugins or auto_update_themes
	 * option when auto-updates of the type are on, no update when the item
	 * says disable_autoupdate, and the auto_update_{type} filter last, which
	 * may force it either way. The filesystem and version control checks of
	 * a real background update are left out. Null when WordPress cannot say.
	 *
	 * @param string $type    plugin or theme.
	 * @param string $key     The plugin's file or the theme's stylesheet.
	 * @param object $payload The item, padded as WordPress pads it.
	 *
	 * @return bool|null
	 */
	private static function auto_update( string $type, string $key, $payload ): ?bool {
		if ( ! function_exists( 'wp_is_auto_update_enabled_for_type' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/update.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! function_exists( 'wp_is_auto_update_enabled_for_type' ) ) {
			return null;
		}

		$update = ! empty( $payload->autoupdate );
		if ( ! $update && wp_is_auto_update_enabled_for_type( $type ) ) {
			$enabled = get_site_option( 'auto_update_' . $type . 's', [] );
			$update  = in_array( $key, (array) $enabled, true );
		}
		if ( ! empty( $payload->disable_autoupdate ) ) {
			$update = false;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter.
		return (bool) apply_filters( 'auto_update_' . $type, $update, $payload );
	}

	/**
	 * How WordPress updates itself: major (to new major releases too), minor
	 * (releases of its own line only) or none, as
	 * Core_Upgrader::should_update_to_version() reads it: the
	 * auto_update_core_dev, auto_update_core_minor and auto_update_core_major
	 * options of the Updates screen, WP_AUTO_UPDATE_CORE over them, then the
	 * allow_*_auto_core_updates filters; a development build updates only
	 * when development updates are allowed. Left out, as a site report
	 * cannot know them: the version control checkout rule
	 * (WP_Automatic_Updater::is_vcs_checkout(), which needs the folder that
	 * would be updated) and a failed earlier core update.
	 *
	 * @param string $installed The installed version.
	 *
	 * @return string
	 */
	private static function core_auto_update( string $installed ): string {
		$disabled = ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED )
			|| ! wp_is_file_mod_allowed( 'automatic_updater' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter.
		if ( apply_filters( 'automatic_updater_disabled', $disabled ) ) {
			return 'none';
		}

		$dev   = 'enabled' === get_site_option( 'auto_update_core_dev', 'enabled' );
		$minor = 'enabled' === get_site_option( 'auto_update_core_minor', 'enabled' );
		$major = 'enabled' === get_site_option( 'auto_update_core_major', 'unset' );
		if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
			$setting = WP_AUTO_UPDATE_CORE;
			if ( false === $setting ) {
				$dev   = false;
				$minor = false;
				$major = false;
			} elseif ( true === $setting || in_array( $setting, [ 'beta', 'rc', 'development', 'branch-development' ], true ) ) {
				$dev   = true;
				$minor = true;
				$major = true;
			} elseif ( 'minor' === $setting ) {
				$dev   = false;
				$minor = true;
				$major = false;
			}
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter.
		if ( false !== strpos( $installed, '-' ) && ! apply_filters( 'allow_dev_auto_core_updates', $dev ) ) {
			return 'none';
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter.
		$major = (bool) apply_filters( 'allow_major_auto_core_updates', $major );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter.
		$minor = (bool) apply_filters( 'allow_minor_auto_core_updates', $minor );

		if ( $major ) {
			return 'major';
		}

		return $minor ? 'minor' : 'none';
	}

	/**
	 * Whether a core offer has a package to download.
	 *
	 * @param mixed $offer An offer of the update_core transient.
	 *
	 * @return bool
	 */
	private static function core_package( $offer ): bool {
		$packages = self::field( $offer, 'packages' );
		$full     = self::field( $packages, 'full' );
		$package  = self::field( $offer, 'package' );

		return ( is_string( $full ) && '' !== trim( $full ) ) || ( is_string( $package ) && '' !== trim( $package ) );
	}

	/**
	 * The major line of a WordPress version: its first two numbers (6.4 for
	 * 6.4.2 and 6.4-beta1), split as Core_Upgrader splits it.
	 *
	 * @param string $version The version.
	 *
	 * @return string
	 */
	private static function major_line( string $version ): string {
		$parts = preg_split( '/[.-]/', $version );
		$parts = false === $parts ? [] : $parts;

		return ( $parts[0] ?? '' ) . '.' . ( $parts[1] ?? '0' );
	}

	/**
	 * A field of a transient entry that may be an object or an array.
	 *
	 * @param mixed  $item The entry.
	 * @param string $key  The field.
	 *
	 * @return mixed
	 */
	private static function field( $item, string $key ) {
		if ( is_array( $item ) ) {
			return $item[ $key ] ?? null;
		}

		return is_object( $item ) ? ( $item->{$key} ?? null ) : null;
	}

	/**
	 * A header or transient text, trimmed; null when empty or not a text.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function text( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );

		return '' === $value ? null : $value;
	}
}
