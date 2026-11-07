<?php
/**
 * The Manage inventory class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

use Scanfully\Health;

/**
 * The plugin's view of the site's software right after a job changed it, in
 * the shape of the site data report (plugins, themes, core, translations),
 * so Scanfully can compare it with the job's baseline without waiting for
 * the next sync.
 *
 * INVENTORY SEAM (M1.1): the software lists come from the Health collector,
 * Health\Controller::get_site_data(). When M1.1's extended collector adds
 * `themes`, `core` and `translations` to the site data, they are taken from
 * there as they are (from_site_data()); until then this class fills in a
 * minimal view of its own for each missing key (fallback_themes(),
 * fallback_core(), fallback_translations()). At the merge, keep
 * from_site_data() and drop the fallbacks once the collector always sends
 * the keys.
 */
final class Inventory {

	/**
	 * Fields of a plugin or theme left out of the result: long text that a
	 * comparison does not need, and that would push a large site's result
	 * past the size the API takes.
	 */
	private const LEFT_OUT = [ 'description' ];

	/**
	 * For tests: gives the site data. Health\Controller::get_site_data()
	 * when null.
	 *
	 * @var callable|null
	 */
	public static $source = null;

	/**
	 * The inventory now, read fresh: the caches WordPress keeps of the
	 * plugin and theme lists are cleared first.
	 *
	 * @return array{plugins: array, themes: array, core: array, translations: array}
	 */
	public static function collect(): array {
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( false );
		}
		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			wp_clean_themes_cache( false );
		}

		return self::from_site_data( null !== self::$source ? (array) call_user_func( self::$source ) : Health\Controller::get_site_data() );
	}

	/**
	 * The inventory from the site data report.
	 *
	 * @param array $data The site data, as Health\Controller::get_site_data() makes it.
	 *
	 * @return array{plugins: array, themes: array, core: array, translations: array}
	 */
	public static function from_site_data( array $data ): array {
		return [
			'plugins'      => self::slim( isset( $data['plugins'] ) && is_array( $data['plugins'] ) ? $data['plugins'] : [] ),
			'themes'       => isset( $data['themes'] ) && is_array( $data['themes'] ) ? self::slim( $data['themes'] ) : self::fallback_themes(),
			'core'         => isset( $data['core'] ) && is_array( $data['core'] ) ? $data['core'] : self::fallback_core( $data ),
			'translations' => isset( $data['translations'] ) && is_array( $data['translations'] ) ? $data['translations'] : self::fallback_translations(),
		];
	}

	/**
	 * Items without the fields left out.
	 *
	 * @param array $items The plugins or themes.
	 *
	 * @return array
	 */
	private static function slim( array $items ): array {
		$out = [];
		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				foreach ( self::LEFT_OUT as $field ) {
					unset( $item[ $field ] );
				}
			}
			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Fallback until M1.1: the installed themes, by stylesheet, with their
	 * version, the version WordPress offers and whether each is the active
	 * theme or its parent.
	 *
	 * @return array<int, array{stylesheet: string, name: string, version: string, latest_version: string|null, active: bool, parent: bool}>
	 */
	private static function fallback_themes(): array {
		if ( ! function_exists( 'wp_get_themes' ) ) {
			return [];
		}
		$offers     = function_exists( 'get_site_transient' ) ? get_site_transient( 'update_themes' ) : false;
		$stylesheet = get_stylesheet();
		$template   = get_template();
		$out        = [];
		foreach ( wp_get_themes() as $slug => $theme ) {
			$offer = is_object( $offers ) && isset( $offers->response[ $slug ] ) ? (array) $offers->response[ $slug ] : [];
			$out[] = [
				'stylesheet'     => (string) $slug,
				'name'           => (string) $theme->get( 'Name' ),
				'version'        => (string) $theme->get( 'Version' ),
				'latest_version' => isset( $offer['new_version'] ) ? (string) $offer['new_version'] : null,
				'active'         => $slug === $stylesheet,
				'parent'         => $slug === $template && $template !== $stylesheet,
			];
		}

		return $out;
	}

	/**
	 * Fallback until M1.1: WordPress's version, read from its version file so
	 * a core update made in this request shows the new one.
	 *
	 * @param array $data The site data.
	 *
	 * @return array{version: string}
	 */
	private static function fallback_core( array $data ): array {
		$version = Updates::core_version();

		return [ 'version' => '' !== $version ? $version : (string) ( $data['data']['wp_version'] ?? '' ) ];
	}

	/**
	 * Fallback until M1.1: the language packs WordPress still offers.
	 *
	 * @return array{pending: array<int, array{type: string, slug: string, language: string, version: string}>}
	 */
	private static function fallback_translations(): array {
		$pending = [];
		if ( function_exists( 'wp_get_translation_updates' ) ) {
			foreach ( wp_get_translation_updates() as $update ) {
				$pending[] = Updates::translation_of( $update );
			}
		}

		return [ 'pending' => $pending ];
	}
}
