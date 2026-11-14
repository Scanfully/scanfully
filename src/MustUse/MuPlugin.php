<?php
/**
 * The Scanfully must-use plugin class file.
 *
 * @package Scanfully
 */

namespace Scanfully\MustUse;

use Scanfully\Errors\Monitor;

/**
 * Class MuPlugin
 *
 * Installs and removes Scanfully's one-file must-use plugin,
 * `scanfully-mu.php`. It starts what has to run before any other plugin
 * loads; today that is the PHP error monitor, so a fatal error while a
 * plugin loads (the classic broken update) is caught too. It is only
 * written when the `mu-plugins` folder is writable (or can be made in a
 * writable `wp-content`); without it the Scanfully plugin starts the
 * monitor itself while plugins load, and coverage is partial.
 *
 * The file only includes `src/mu.php` from the Scanfully plugin's folder,
 * which decides what starts early, so something new that has to run early
 * changes the plugin and never the file on the site. It does nothing while
 * the plugin is inactive, so a copy left behind does nothing either.
 *
 * @package Scanfully\MustUse
 */
final class MuPlugin {

	/**
	 * The file name in `mu-plugins`.
	 */
	public const FILE = 'scanfully-mu.php';

	/**
	 * The version of the file's content; a new version is written over an
	 * older one.
	 */
	public const VERSION = '1';

	/**
	 * Text in the file that marks it as Scanfully's, so only Scanfully's own
	 * file is ever removed.
	 */
	public const MARKER = 'Scanfully (must-use)';

	/**
	 * The must-use plugins folder.
	 *
	 * @return string
	 */
	public static function dir(): string {
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			return rtrim( (string) WPMU_PLUGIN_DIR, '/\\' );
		}

		return rtrim( defined( 'WP_CONTENT_DIR' ) ? (string) WP_CONTENT_DIR : ABSPATH . 'wp-content', '/\\' ) . '/mu-plugins';
	}

	/**
	 * The file's path.
	 *
	 * @return string
	 */
	public static function path(): string {
		return self::dir() . '/' . self::FILE;
	}

	/**
	 * The file's content for the Scanfully plugin in a folder.
	 *
	 * @param string $basename The Scanfully plugin's basename, such as `scanfully/scanfully.php`.
	 *
	 * @return string
	 */
	public static function contents( string $basename ): string {
		$folder = dirname( $basename );
		$export = static function ( string $value ): string {
			return "'" . addcslashes( $value, "'\\" ) . "'";
		};

		return '<?php
/**
 * Plugin Name: ' . self::MARKER . '
 * Description: Starts the parts of the Scanfully plugin that have to run before other plugins load, such as PHP error monitoring. Installed and removed by the Scanfully plugin; it does nothing while that plugin is inactive.
 * Version: ' . self::VERSION . '
 * Author: Scanfully
 * Author URI: https://scanfully.com/
 * License: GPL v3
 *
 * @package Scanfully
 */

if ( ! defined( \'ABSPATH\' ) ) {
	exit;
}

try {
	$scanfully_mu_entry = WP_PLUGIN_DIR . \'/\' . ' . $export( $folder ) . ' . \'/src/mu.php\';
	if ( is_readable( $scanfully_mu_entry ) ) {
		$scanfully_mu_boot = require $scanfully_mu_entry;
		if ( is_callable( $scanfully_mu_boot ) ) {
			$scanfully_mu_boot( ' . $export( $basename ) . ' );
		}
		unset( $scanfully_mu_boot );
	}
	unset( $scanfully_mu_entry );
} catch ( \\Throwable $scanfully_mu_error ) {
	// Never break the site: the Scanfully plugin starts these itself.
	unset( $scanfully_mu_error );
}
';
	}

	/**
	 * The Scanfully plugin's basename.
	 *
	 * @return string
	 */
	public static function basename(): string {
		$file = defined( 'SCANFULLY_PLUGIN_FILE' ) ? (string) SCANFULLY_PLUGIN_FILE : dirname( __DIR__, 2 ) . '/scanfully.php';

		return function_exists( 'plugin_basename' ) ? plugin_basename( $file ) : basename( dirname( $file ) ) . '/' . basename( $file );
	}

	/**
	 * Whether anything has to start before other plugins load on this site.
	 * Today that is the PHP error monitor; add a check here for anything
	 * else `src/mu.php` starts.
	 *
	 * @return bool
	 */
	public static function needed(): bool {
		return Monitor::should_run();
	}

	/**
	 * Whether the installed file is the current one.
	 *
	 * @return bool
	 */
	public static function is_current(): bool {
		$path = self::path();
		if ( ! is_file( $path ) ) {
			return false;
		}
		$contents = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file that may be unreadable.

		return is_string( $contents ) && self::contents( self::basename() ) === $contents;
	}

	/**
	 * Whether the file is installed (any version).
	 *
	 * @return bool
	 */
	public static function is_installed(): bool {
		return self::is_ours( self::path() );
	}

	/**
	 * Whether a file is Scanfully's must-use plugin.
	 *
	 * @param string $path The file.
	 *
	 * @return bool
	 */
	private static function is_ours( string $path ): bool {
		if ( ! is_file( $path ) ) {
			return false;
		}
		$head = @file_get_contents( $path, false, null, 0, 1024 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file that may be unreadable.

		return is_string( $head ) && false !== strpos( $head, 'Plugin Name: ' . self::MARKER );
	}

	/**
	 * Whether the file can be written: the folder is writable, or it is
	 * missing and `wp-content` is writable.
	 *
	 * @return bool
	 */
	public static function can_install(): bool {
		$dir = self::dir();
		if ( is_dir( $dir ) ) {
			return wp_is_writable( $dir ) && ( ! file_exists( self::path() ) || wp_is_writable( self::path() ) );
		}

		return wp_is_writable( dirname( $dir ) );
	}

	/**
	 * Write the current file, when something needs it and the folder is
	 * writable. Leaves a file of the same name that isn't Scanfully's alone.
	 * Written to a temporary file first and moved into place, so a request
	 * never loads half a file.
	 *
	 * @return bool Whether the current file is in place.
	 */
	public static function install(): bool {
		if ( ! self::needed() ) {
			return false;
		}
		if ( self::is_current() ) {
			return true;
		}
		$path = self::path();
		if ( file_exists( $path ) && ! self::is_ours( $path ) ) {
			return false;
		}
		if ( ! self::can_install() ) {
			return false;
		}
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$temp = $dir . '/.' . self::FILE . '.' . wp_generate_password( 8, false ) . '.tmp';
		// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- The must-use plugin is written directly, like core writes .maintenance; the folder was checked for writing above.
		if ( false === @file_put_contents( $temp, self::contents( self::basename() ) ) ) {
			@unlink( $temp );
			return false;
		}
		@chmod( $temp, 0644 );
		$moved = @rename( $temp, $path );
		if ( ! $moved ) {
			@unlink( $temp );
		}
		// phpcs:enable

		return $moved;
	}

	/**
	 * Remove the file when it is Scanfully's.
	 *
	 * @return void
	 */
	public static function remove(): void {
		$path = self::path();
		if ( self::is_ours( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Install the file when it's missing or outdated. Cheap enough for every
	 * admin page: a file check, and only when that fails, a write.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		try {
			self::install();
		} catch ( \Throwable $e ) {
			// Never break the admin; the Scanfully plugin starts these itself.
			return;
		}
	}
}
