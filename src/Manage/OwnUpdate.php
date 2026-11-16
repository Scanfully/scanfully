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
 *
 * After the update the site is loaded once with the new version, the way
 * WordPress checks its own automatic updates (since 6.6): a request to the
 * home page with WordPress's scrape key, which prints the fatal error of
 * that request, if any. When the new version stops with a PHP error that the
 * version before it did not, WordPress's backup of the version before it,
 * which WordPress keeps until the end of this request, goes back in place,
 * and so it does when every page load ends in a server error where the site
 * loaded before (an error before WordPress's check starts has no name). A
 * check without an answer is tried once more; a site that still could not
 * be checked keeps the new version: nothing is known then.
 *
 * What PHP stopping halfway leaves behind is for the update guard (see
 * UpdateGuard): nothing after that point runs here.
 */
final class OwnUpdate {

	/**
	 * Seconds a check of the site may take.
	 */
	private const SCRAPE_TIMEOUT = 30;

	/**
	 * For tests: the folder of the classes to load and their namespace
	 * prefix. The plugin's src folder and Scanfully\ when null.
	 *
	 * @var array{0: string, 1: string}|null
	 */
	public static $classes = null;

	/**
	 * For tests: loads the site and answers as scrape() does.
	 *
	 * @var callable|null
	 */
	public static $scraper = null;

	/**
	 * For tests: puts the backup back as roll_back() does, taking the plugin
	 * file and answering null or what went wrong.
	 *
	 * @var callable|null
	 */
	public static $restorer = null;

	/**
	 * Load the site's home page once, with WordPress's scrape key, and say
	 * whether it ran to the end, stopped with a fatal PHP error, or could not
	 * be checked.
	 *
	 * @return array{state: string, error?: array{message: string, file: string, line: int}, code?: int, reason?: string}
	 */
	public static function scrape(): array {
		if ( null !== self::$scraper ) {
			return call_user_func( self::$scraper );
		}
		$key   = bin2hex( random_bytes( 16 ) );
		$nonce = bin2hex( random_bytes( 16 ) );
		set_transient( 'scrape_key_' . $key, $nonce, MINUTE_IN_SECONDS );
		$url      = add_query_arg(
			[
				'wp_scrape_key'   => $key,
				'wp_scrape_nonce' => $nonce,
			],
			home_url( '/' )
		);
		$response = wp_remote_get(
			$url,
			[
				'timeout'   => self::SCRAPE_TIMEOUT,
				'headers'   => [ 'Cache-Control' => 'no-cache' ],
				'sslverify' => apply_filters( 'https_local_ssl_verify', false, $url ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter for requests to the site itself.
			]
		);
		delete_transient( 'scrape_key_' . $key );
		if ( is_wp_error( $response ) ) {
			return [
				'state'  => 'unknown',
				'reason' => 'the request to the site failed: ' . $response->get_error_message(),
			];
		}

		$body  = (string) wp_remote_retrieve_body( $response );
		$start = "###### wp_scraping_result_start:$key ######";
		$at    = strpos( $body, $start );
		if ( false === $at ) {
			$code = (int) wp_remote_retrieve_response_code( $response );
			return [
				'state'  => 'unknown',
				'code'   => $code,
				'reason' => sprintf( 'the site answered %d without WordPress\'s check, for example from a page cache', $code ),
			];
		}
		$json = substr( $body, $at + strlen( $start ) );
		$end  = strpos( $json, "###### wp_scraping_result_end:$key ######" );
		$data = json_decode( trim( false === $end ? $json : substr( $json, 0, $end ) ), true );
		if ( true === $data ) {
			return [ 'state' => 'ok' ];
		}
		if ( is_array( $data ) && isset( $data['type'] ) ) {
			return [
				'state' => 'fatal',
				'error' => [
					'message' => (string) ( $data['message'] ?? '' ),
					'file'    => (string) ( $data['file'] ?? '' ),
					'line'    => (int) ( $data['line'] ?? 0 ),
				],
			];
		}

		return [
			'state'  => 'unknown',
			'reason' => 'WordPress did not run its check',
		];
	}

	/**
	 * Check the site with the new version, after an update that WordPress
	 * reported done: when it stops with a PHP error, or answers a server
	 * error, where it loaded before the update, put the version before it
	 * back.
	 *
	 * @param array  $before   The check before the update, as scrape() answers.
	 * @param string $file     The plugin file, relative to the plugins folder.
	 * @param string $previous The version before the update.
	 * @param string $new      The version the update installed.
	 * @param JobRun $run      The run.
	 *
	 * @return array{class: string, message: string, settled: bool}|null The failure, with whether the plugin is known to be back in order; null when the update stands.
	 */
	public static function check_after( array $before, string $file, string $previous, string $new, JobRun $run ): ?array {
		if ( 'ok' !== ( $before['state'] ?? '' ) ) {
			$run->log(
				'fatal' === ( $before['state'] ?? '' )
					? 'Scanfully did not check the site with the new version: the site stopped with a PHP error before the update too.'
					: sprintf( 'Scanfully did not check the site with the new version: it could not check it before the update (%s).', (string) ( $before['reason'] ?? '' ) )
			);
			return null;
		}
		$after = self::scrape();
		$run->beat();
		if ( 'unknown' === $after['state'] ) {
			// Once more: one page load can fail on its own.
			$after = self::scrape();
			$run->beat();
		}
		if ( 'ok' === $after['state'] ) {
			$run->log( 'The site loads with the new version of Scanfully.' );
			return null;
		}
		$code = (int) ( $after['code'] ?? 0 );
		if ( 'fatal' !== $after['state'] && $code < 500 ) {
			$run->log( sprintf( 'Scanfully could not check the site with the new version: %s.', (string) ( $after['reason'] ?? '' ) ) );
			return null;
		}

		// A PHP error WordPress's check names, or a server error on every
		// page load where the site loaded before the update: an error before
		// WordPress's check starts (in a must-use plugin of the new version,
		// say) gives no name.
		$stopped = 'fatal' === $after['state']
			? sprintf( 'Version %1$s of the Scanfully plugin stopped with a PHP error when the site loaded it: %2$s', $new, self::describe( $after['error'] ?? [] ) )
			: sprintf( 'With version %1$s of the Scanfully plugin the site answered %2$d (a server error) to every page load, where it loaded before the update.', $new, $code );
		$problem = null !== self::$restorer ? call_user_func( self::$restorer, $file ) : self::roll_back( $file );
		if ( null !== $problem ) {
			return [
				'class'   => 'fatal',
				'message' => sprintf( '%1$s Scanfully could not put version %2$s back (%3$s); its update guard tries again on the site within a few minutes.', $stopped, $previous, $problem ),
				'settled' => false,
			];
		}
		$again = self::scrape();
		$run->beat();
		$message = sprintf( '%1$s Scanfully put version %2$s back.', $stopped, $previous );
		if ( 'fatal' === $again['state'] ) {
			$message .= ' The site still stops with a PHP error: ' . self::describe( $again['error'] ?? [] );
		} elseif ( 'ok' === $again['state'] ) {
			$message .= ' The site loads again.';
		}

		return [
			'class'   => 'fatal',
			'message' => $message,
			'settled' => 'fatal' !== $again['state'],
		];
	}

	/**
	 * Whether the plugin is as before an update that failed: on, at the
	 * version before it, with no backup of WordPress waiting to go back.
	 *
	 * @param string $file     The plugin file, relative to the plugins folder.
	 * @param string $previous The version before the update.
	 * @param bool   $network  Whether it was active for the whole network.
	 *
	 * @return bool
	 */
	public static function intact( string $file, string $previous, bool $network ): bool {
		$version = WP_PLUGIN_DIR . '/' . $file;
		clearstatcache( true, $version );
		if ( ! is_file( $version ) || ( get_file_data( $version, [ 'Version' => 'Version' ] )['Version'] ?? '' ) !== $previous ) {
			return false;
		}
		if ( is_dir( self::backup( $file ) ) ) {
			return false;
		}

		return $network ? is_plugin_active_for_network( $file ) : is_plugin_active( $file );
	}

	/**
	 * WordPress's backup of a plugin while it updates (since WordPress 6.3).
	 *
	 * @param string $file The plugin file, relative to the plugins folder.
	 *
	 * @return string
	 */
	private static function backup( string $file ): string {
		return rtrim( (string) WP_CONTENT_DIR, '/\\' ) . '/upgrade-temp-backup/plugins/' . dirname( $file );
	}

	/**
	 * Put WordPress's backup of the version before the update back in place
	 * of the new one, as WordPress does when an update fails.
	 *
	 * @param string $file The plugin file, relative to the plugins folder.
	 *
	 * @return string|null What went wrong; null when the backup is back.
	 */
	private static function roll_back( string $file ): ?string {
		global $wp_filesystem;
		if ( ! is_object( $wp_filesystem ) || ! function_exists( 'move_dir' ) ) {
			return 'this WordPress version keeps no backup during an update';
		}
		$slug = dirname( $file );
		$src  = $wp_filesystem->wp_content_dir() . 'upgrade-temp-backup/plugins/' . $slug;
		$dest = trailingslashit( (string) $wp_filesystem->find_folder( WP_PLUGIN_DIR ) ) . $slug;
		if ( ! $wp_filesystem->is_dir( $src ) ) {
			return 'WordPress\'s backup of it is not there';
		}
		if ( $wp_filesystem->is_dir( $dest ) && ! $wp_filesystem->delete( $dest, true ) ) {
			return 'the new version\'s files could not be removed';
		}
		$moved = move_dir( $src, $dest, true );
		if ( is_wp_error( $moved ) ) {
			return 'the backup could not be moved back: ' . $moved->get_error_message();
		}
		if ( function_exists( 'wp_opcache_invalidate_directory' ) ) {
			wp_opcache_invalidate_directory( WP_PLUGIN_DIR . '/' . $slug );
		}

		return null;
	}

	/**
	 * A fatal error, in words: the message and where.
	 *
	 * @param array $error The error, as scrape() answers it.
	 *
	 * @return string
	 */
	private static function describe( array $error ): string {
		$message = rtrim( trim( (string) ( $error['message'] ?? '' ) ), '.' );
		$where   = (string) ( $error['file'] ?? '' );
		if ( '' !== $where ) {
			$where = sprintf( ' (in %s%s)', $where, ! empty( $error['line'] ) ? ':' . (int) $error['line'] : '' );
		}

		return ( '' !== $message ? $message : 'no message' ) . $where . '.';
	}

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
