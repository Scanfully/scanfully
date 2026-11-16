<?php
/**
 * The Manage own update class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A JobFailed message is never printed: it is posted to the API as JSON, cleaned by Failure::clean(), and HTML escaping would garble it.

/**
 * What an update of the Scanfully plugin itself needs on top of any other
 * plugin update: the job that runs it is the code the update replaces.
 *
 * WordPress's upgrader moves the plugin's folder away and unpacks the new
 * version in its place while this request still runs the old one. The
 * classes PHP loaded already keep running from memory, but a class loaded
 * after that would come from the new files and could expect what the old
 * code in memory does not have. So every class of the plugin is loaded
 * before the upgrade starts: the rest of the job (switching the plugin on
 * again, the result, the ledger, the report to the API) runs on the old
 * version, and the next request runs the new one. The bundled libraries in
 * vendor are left as they are: the copy of Action Scheduler that runs the
 * job may be another plugin's, and it is running already.
 *
 * A plugin folder that is a symbolic link, as in a development setup, is
 * never updated: WordPress would delete the files the link points to.
 */
final class OwnUpdate {

	/**
	 * For tests: the folder of the classes to load and their namespace
	 * prefix. The plugin's src folder and Scanfully\ when null.
	 *
	 * @var array{0: string, 1: string}|null
	 */
	public static $classes = null;

	/**
	 * Refuse the update when the plugin's folder is a symbolic link.
	 *
	 * @param string $file The plugin file, relative to the plugins folder.
	 *
	 * @return void
	 * @throws JobFailed When the folder is a symbolic link.
	 */
	public static function check( string $file ): void {
		$folder = WP_PLUGIN_DIR . '/' . dirname( $file );
		clearstatcache( true, $folder );
		if ( is_link( $folder ) ) {
			throw new JobFailed( 'not_supported', 'The Scanfully plugin\'s folder is a symbolic link, as in a development setup. WordPress would delete the files the link points to, so Scanfully does not update it: update the linked copy instead.' );
		}
	}

	/**
	 * Load every class of the plugin from its files now, before the update
	 * replaces them. A file whose name does not start with a capital letter
	 * holds no class and is left alone (src/Errors/load.php), and so is a
	 * class whose parent from outside the plugin is not there (the
	 * WooCommerce payment gateway and blocks integration without
	 * WooCommerce): this request cannot use either.
	 *
	 * @return string[] What could not be loaded, for the job's log.
	 */
	public static function preload(): array {
		[ $root, $prefix ] = self::$classes ?? [ dirname( SCANFULLY_PLUGIN_FILE ) . '/src', 'Scanfully\\' ];
		$root              = rtrim( $root, '/\\' );
		if ( ! is_dir( $root ) ) {
			return [ 'Scanfully could not find its classes before updating itself.' ];
		}

		$files    = [];
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $info ) {
			if ( $info instanceof \SplFileInfo && $info->isFile() && 1 === preg_match( '/^[A-Z][A-Za-z0-9_]*\.php$/', $info->getFilename() ) ) {
				$files[] = $info->getPathname();
			}
		}
		sort( $files );

		$problems = [];
		foreach ( $files as $path ) {
			$class = $prefix . str_replace( [ '/', '\\' ], '\\', substr( $path, strlen( $root ) + 1, -4 ) );
			if ( self::exists( $class ) ) {
				continue;
			}
			$parent = self::outside_parent( $path, $prefix );
			if ( null !== $parent && ! class_exists( $parent ) ) {
				continue;
			}
			try {
				// Autoload once: the autoloaders include the file again on
				// every call that asks them, a redeclaration for a file that
				// declares another name.
				if ( ! class_exists( $class ) && ! self::exists( $class ) ) {
					$problems[] = sprintf( 'Scanfully could not load %s before updating itself: the file does not declare it.', $class );
				}
			} catch ( \Throwable $e ) {
				$problems[] = sprintf( 'Scanfully could not load %s before updating itself: %s', $class, $e->getMessage() );
			}
		}

		return $problems;
	}

	/**
	 * Whether a class, an interface or a trait of the name is loaded. The
	 * answer changes once an autoloader loaded the file.
	 *
	 * @phpstan-impure
	 *
	 * @param string $name The name.
	 *
	 * @return bool
	 */
	private static function exists( string $name ): bool {
		return class_exists( $name, false ) || interface_exists( $name, false ) || trait_exists( $name, false );
	}

	/**
	 * The parent class from outside the plugin a file's class extends, by
	 * its fully qualified name: written out with a leading backslash, or
	 * imported with a use statement at the top of the file. Null when it
	 * extends none, or a class of its own namespace.
	 *
	 * @param string $path   The file.
	 * @param string $prefix The plugin's namespace prefix.
	 *
	 * @return string|null
	 */
	private static function outside_parent( string $path, string $prefix ): ?string {
		$code = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of the plugin, read before WordPress's filesystem is connected.
		if ( false === $code || 1 !== preg_match( '/^\s*(?:abstract\s+|final\s+)*class\s+\w+\s+extends\s+(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/m', $code, $match ) ) {
			return null;
		}
		$parent = $match[1];
		if ( '\\' === $parent[0] ) {
			$name = substr( $parent, 1 );
		} else {
			$first   = explode( '\\', $parent )[0];
			$imports = [];
			preg_match_all( '/^use\s+\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)(?:\s+as\s+(\w+))?\s*;/m', $code, $uses, PREG_SET_ORDER );
			foreach ( $uses as $use ) {
				$alias                         = '' !== ( $use[2] ?? '' ) ? $use[2] : substr( (string) strrchr( '\\' . $use[1], '\\' ), 1 );
				$imports[ strtolower( $alias ) ] = $use[1];
			}
			if ( ! isset( $imports[ strtolower( $first ) ] ) ) {
				return null;
			}
			$name = $imports[ strtolower( $first ) ] . substr( $parent, strlen( $first ) );
		}

		return 0 === strpos( $name, $prefix ) ? null : $name;
	}
}
