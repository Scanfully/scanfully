<?php
/**
 * The PHP error monitor must-use plugin class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Errors;

/**
 * Class MuPlugin
 *
 * Installs and removes the one-file must-use plugin that starts the error
 * monitor before any plugin loads, so a fatal error while a plugin loads
 * (the classic broken update) is caught too. It is only written when the
 * `mu-plugins` folder is writable (or can be made in a writable
 * `wp-content`); without it the Scanfully plugin starts the monitor itself
 * while plugins load, and coverage is partial.
 *
 * The file only loads the monitor from the Scanfully plugin's folder, and
 * only while the plugin is active, so a copy left behind does nothing.
 *
 * @package Scanfully\Errors
 */
final class MuPlugin {

	/**
	 * The file name in `mu-plugins`.
	 */
	public const FILE = 'scanfully-php-errors.php';

	/**
	 * The version of the file's content; a new version is written over an
	 * older one.
	 */
	public const VERSION = '1';

	/**
	 * Text in the file that marks it as Scanfully's, so only Scanfully's own
	 * file is ever removed.
	 */
	public const MARKER = 'Scanfully PHP error monitor';

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
 * Description: Catches PHP fatal errors and warnings from before other plugins load and hands them to the Scanfully plugin. Installed and removed by the Scanfully plugin; it does nothing while that plugin is inactive.
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
	$scanfully_errors_loader = WP_PLUGIN_DIR . \'/\' . ' . $export( $folder ) . ' . \'/src/Errors/load.php\';
	if ( is_readable( $scanfully_errors_loader ) ) {
		require_once $scanfully_errors_loader;
		\\Scanfully\\Errors\\Monitor::boot( \'mu\', ' . $export( $basename ) . ' );
	}
	unset( $scanfully_errors_loader );
} catch ( \\Throwable $scanfully_errors_error ) {
	// Never break the site: the Scanfully plugin catches errors itself.
	unset( $scanfully_errors_error );
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
	 * Write the current file, when capture runs and the folder is writable.
	 * Leaves a file of the same name that isn't Scanfully's alone. Written
	 * to a temporary file first and moved into place, so a request never
	 * loads half a file.
	 *
	 * @return bool Whether the current file is in place.
	 */
	public static function install(): bool {
		if ( ! Monitor::should_run() ) {
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
			if ( Monitor::should_run() && ! self::is_current() ) {
				self::install();
			}
		} catch ( \Throwable $e ) {
			// Never break the admin; the Scanfully plugin catches errors itself.
			return;
		}
	}
}
