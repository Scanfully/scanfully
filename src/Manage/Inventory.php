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
 * It is the site data report's own inventory, from Health\Controller and
 * Health\Inventory, read without the capabilities check (the API keeps the
 * stored row, and the check writes a test file in wp-content) and without
 * the plugins' and themes' descriptions (long text a comparison does not
 * need, which would push a large site's result past the size the API takes).
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
		self::refill_update_data();

		// The capabilities stay out: the API keeps the stored row, and the
		// check writes a test file in wp-content each time.
		$data = null !== self::$source
			? (array) call_user_func( self::$source )
			: (array) Health\Capabilities::skip( [ Health\Controller::class, 'get_site_data' ] );

		return self::from_site_data( $data );
	}

	/**
	 * Fetch WordPress's update data again where an upgrader cleared it:
	 * WordPress's own translation update after a plugin, theme or core
	 * update empties all three caches, and the inventory would then say no
	 * update is known for anything.
	 *
	 * @return void
	 */
	private static function refill_update_data(): void {
		if ( ! function_exists( 'get_site_transient' ) ) {
			return;
		}
		if ( function_exists( 'wp_version_check' ) && false === get_site_transient( 'update_core' ) ) {
			wp_version_check();
		}
		if ( function_exists( 'wp_update_plugins' ) && false === get_site_transient( 'update_plugins' ) ) {
			wp_update_plugins();
		}
		if ( function_exists( 'wp_update_themes' ) && false === get_site_transient( 'update_themes' ) ) {
			wp_update_themes();
		}
	}

	/**
	 * The inventory from the site data report. WordPress's version is read
	 * from its version file, so a core update made in this request shows the
	 * new one even while the running code still knows the old.
	 *
	 * @param array $data The site data, as Health\Controller::get_site_data() makes it.
	 *
	 * @return array{plugins: array, themes: array|null, core: array|null, translations: array|null}
	 */
	public static function from_site_data( array $data ): array {
		$core      = isset( $data['core'] ) && is_array( $data['core'] ) ? $data['core'] : null;
		$installed = Updates::core_version_on_disk();
		if ( null !== $core && null !== $installed && ( $core['installed'] ?? null ) !== $installed ) {
			$core = Health\Inventory::core( $installed );
		}

		return [
			'plugins'      => self::slim( isset( $data['plugins'] ) && is_array( $data['plugins'] ) ? $data['plugins'] : [] ),
			'themes'       => isset( $data['themes'] ) && is_array( $data['themes'] ) ? self::slim( $data['themes'] ) : null,
			'core'         => $core,
			'translations' => isset( $data['translations'] ) && is_array( $data['translations'] ) ? $data['translations'] : null,
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
}
