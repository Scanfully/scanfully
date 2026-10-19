<?php
/**
 * The MU plugins class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Health;

/**
 * Class MuPlugins
 *
 * The must-use plugins and drop-ins of the site, for the site data sync:
 * every PHP file in the root of `mu-plugins` (WordPress loads those on every
 * request), every folder there (loaders include code from them) and every
 * drop-in in `wp-content`. Each comes with its size, modification time and a
 * SHA-256 hash of its content, or for a folder a fingerprint over its files,
 * so Scanfully can tell when one is added, changed or removed. Never the
 * content itself.
 *
 * Runs in the background sync job. Hashes are kept in an option by path,
 * size and modification time, so a file is only read again when it changed
 * on disk.
 *
 * @package Scanfully\Health
 */
final class MuPlugins {

	/**
	 * Option with the content hashes, by path relative to `wp-content`.
	 */
	public const HASH_OPTION = 'scanfully_mu_plugin_hashes';

	/**
	 * Option with the quick fingerprint of the files as the last sync saw them.
	 */
	public const STAT_OPTION = 'scanfully_mu_plugins_stat';

	/**
	 * The most files of a folder that go into its fingerprint.
	 */
	private const MAX_FOLDER_FILES = 5000;

	/**
	 * The most bytes of a folder that are read for its fingerprint.
	 */
	private const MAX_FOLDER_BYTES = 50 * 1024 * 1024;

	/**
	 * The most file names listed per folder before giving up on sorting them.
	 */
	private const MAX_FOLDER_LISTING = 50000;

	/**
	 * A file larger than this is fingerprinted by size and time, not read.
	 */
	private const MAX_HASH_BYTES = 50 * 1024 * 1024;

	/**
	 * The longest header text kept.
	 */
	private const MAX_TEXT = 500;

	/**
	 * The MU plugins, MU plugin folders and drop-ins of the site, for the site
	 * data sync. Also remembers what it saw for the hourly check.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function scan(): array {
		if ( ! function_exists( 'get_mu_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$mu_dir      = defined( 'WPMU_PLUGIN_DIR' ) ? (string) WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		$mu_plugins  = is_dir( $mu_dir ) ? get_mu_plugins() : [];
		$dropins     = get_dropins();
		$cache       = get_option( self::HASH_OPTION, [] );
		$cache       = is_array( $cache ) ? $cache : [];
		$used        = [];
		$entries     = self::entries( $mu_dir, $mu_plugins, WP_CONTENT_DIR, $dropins, $cache, $used );
		$stat        = self::stat_fingerprint( $mu_dir, WP_CONTENT_DIR, array_keys( $dropins ) );

		// Keep only the hashes of files that are still there.
		update_option( self::HASH_OPTION, array_intersect_key( $cache, $used ), false );
		update_option( self::STAT_OPTION, $stat, false );

		/**
		 * Filters the MU plugins and drop-ins sent to Scanfully.
		 *
		 * @param array $entries The entries.
		 */
		$entries = apply_filters( 'scanfully_mu_plugins_data', $entries );

		return is_array( $entries ) ? array_values( $entries ) : [];
	}

	/**
	 * Whether the MU plugins or drop-ins look different from what the last
	 * sync saw: a quick check of names, sizes and times, without reading any
	 * file. False when no sync has run yet.
	 *
	 * @return bool
	 */
	public static function changed_since_last_sync(): bool {
		$last = get_option( self::STAT_OPTION, '' );
		if ( ! is_string( $last ) || '' === $last ) {
			return false;
		}

		if ( ! function_exists( 'get_dropins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? (string) WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';

		return self::stat_fingerprint( $mu_dir, WP_CONTENT_DIR, array_keys( get_dropins() ) ) !== $last;
	}

	/**
	 * The entries for the MU plugin files, the folders next to them and the
	 * drop-ins.
	 *
	 * @param string                              $mu_dir      The MU plugins folder.
	 * @param array<string, array<string, mixed>> $mu_plugins  Header data of the MU plugin files, by file name.
	 * @param string                              $content_dir The `wp-content` folder.
	 * @param array<string, array<string, mixed>> $dropins     Header data of the drop-ins, by file name.
	 * @param array<string, string>               $cache       Content hashes by path; updated.
	 * @param array<string, bool>                 $used        Paths whose hash was used; updated.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function entries( string $mu_dir, array $mu_plugins, string $content_dir, array $dropins, array &$cache, array &$used ): array {
		$entries = [];
		$mu_dir  = rtrim( $mu_dir, '/\\' );

		ksort( $mu_plugins, SORT_STRING );
		foreach ( $mu_plugins as $file => $header ) {
			$entry = self::file_entry( 'mu', (string) $file, $mu_dir . '/' . $file, is_array( $header ) ? $header : [], $content_dir, $cache, $used );
			if ( null !== $entry ) {
				$entries[] = $entry;
			}
		}

		foreach ( self::folders( $mu_dir ) as $folder ) {
			$entries[] = self::folder_entry( $folder, $mu_dir . '/' . $folder, $content_dir, $cache, $used );
		}

		ksort( $dropins, SORT_STRING );
		foreach ( $dropins as $file => $header ) {
			$entry = self::file_entry( 'dropin', (string) $file, rtrim( $content_dir, '/\\' ) . '/' . $file, is_array( $header ) ? $header : [], $content_dir, $cache, $used );
			if ( null !== $entry ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * A MU plugin file or drop-in: its header, size, time and hash.
	 *
	 * @param string                $kind        `mu` or `dropin`.
	 * @param string                $file        The file name.
	 * @param string                $path        The full path.
	 * @param array<string, mixed>  $header      The plugin header.
	 * @param string                $content_dir The `wp-content` folder.
	 * @param array<string, string> $cache       Content hashes by path; updated.
	 * @param array<string, bool>   $used        Paths whose hash was used; updated.
	 *
	 * @return array<string, mixed>|null Null when the file is gone.
	 */
	private static function file_entry( string $kind, string $file, string $path, array $header, string $content_dir, array &$cache, array &$used ): ?array {
		$stat = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A file can disappear between the listing and here.
		if ( false === $stat || ! is_file( $path ) ) {
			return null;
		}

		$name = self::text( $header['Name'] ?? '' );

		return [
			'kind'        => $kind,
			'file'        => $file,
			'name'        => '' !== $name ? $name : $file,
			'version'     => self::text( $header['Version'] ?? '' ),
			'author'      => self::text( $header['Author'] ?? '' ),
			'description' => self::text( $header['Description'] ?? '' ),
			'size'        => (int) $stat['size'],
			'modified'    => (int) $stat['mtime'],
			'hash'        => self::hash( $path, (int) $stat['size'], (int) $stat['mtime'], $content_dir, $cache, $used ),
		];
	}

	/**
	 * A folder in `mu-plugins`: its file count, size, newest time and a
	 * fingerprint over the relative paths and content hashes of its files,
	 * in name order. Past the caps the fingerprint covers the first files
	 * only, and `partial` says so.
	 *
	 * @param string                $folder      The folder name.
	 * @param string                $path        The full path.
	 * @param string                $content_dir The `wp-content` folder.
	 * @param array<string, string> $cache       Content hashes by path; updated.
	 * @param array<string, bool>   $used        Paths whose hash was used; updated.
	 *
	 * @return array<string, mixed>
	 */
	private static function folder_entry( string $folder, string $path, string $content_dir, array &$cache, array &$used ): array {
		$files   = self::folder_files( $path );
		$partial = count( $files ) > self::MAX_FOLDER_LISTING;
		if ( $partial ) {
			$files = array_slice( $files, 0, self::MAX_FOLDER_LISTING );
		}
		sort( $files, SORT_STRING );

		$size     = 0;
		$modified = 0;
		$read     = 0;
		$lines    = [];
		foreach ( $files as $index => $relative ) {
			$stat = @stat( $path . '/' . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A file can disappear between the listing and here.
			if ( false === $stat ) {
				continue;
			}
			$size    += (int) $stat['size'];
			$modified = max( $modified, (int) $stat['mtime'] );

			if ( $index >= self::MAX_FOLDER_FILES || $read + (int) $stat['size'] > self::MAX_FOLDER_BYTES ) {
				$partial = true;
				continue;
			}
			$read   += (int) $stat['size'];
			$lines[] = $relative . "\0" . self::hash( $path . '/' . $relative, (int) $stat['size'], (int) $stat['mtime'], $content_dir, $cache, $used );
		}

		return [
			'kind'     => 'mu_folder',
			'file'     => $folder,
			'name'     => $folder,
			'files'    => count( $files ),
			'size'     => $size,
			'modified' => $modified,
			'hash'     => 'sha256:' . hash( 'sha256', implode( "\n", $lines ) ),
			'partial'  => $partial,
		];
	}

	/**
	 * The folders in the root of the MU plugins folder, by name.
	 *
	 * @param string $mu_dir The MU plugins folder.
	 *
	 * @return string[]
	 */
	private static function folders( string $mu_dir ): array {
		$names = @scandir( $mu_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The folder may not exist.
		if ( false === $names ) {
			return [];
		}

		$folders = [];
		foreach ( $names as $name ) {
			if ( '.' !== $name[0] && is_dir( $mu_dir . '/' . $name ) ) {
				$folders[] = $name;
			}
		}
		sort( $folders, SORT_STRING );

		return $folders;
	}

	/**
	 * The files of a folder and its subfolders, relative to it. Symbolic
	 * links to folders are not followed. Stops one past the listing cap.
	 *
	 * @param string $path The folder.
	 *
	 * @return string[]
	 */
	private static function folder_files( string $path ): array {
		$files = [];
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS ),
				\RecursiveIteratorIterator::LEAVES_ONLY,
				\RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			$prefix   = strlen( rtrim( str_replace( '\\', '/', $path ), '/' ) ) + 1;
			foreach ( $iterator as $item ) {
				if ( $item instanceof \SplFileInfo && $item->isFile() ) {
					$files[] = substr( str_replace( '\\', '/', $item->getPathname() ), $prefix );
					if ( count( $files ) > self::MAX_FOLDER_LISTING ) {
						break;
					}
				}
			}
		} catch ( \Throwable $e ) {
			return $files;
		}

		return $files;
	}

	/**
	 * The content hash of a file, from the cache when its size and time are
	 * the same as when it was hashed. A file too large to read gets a
	 * fingerprint of its size and time instead.
	 *
	 * @param string                $path        The full path.
	 * @param int                   $size        Its size.
	 * @param int                   $modified    Its modification time.
	 * @param string                $content_dir The `wp-content` folder.
	 * @param array<string, string> $cache       Content hashes by path; updated.
	 * @param array<string, bool>   $used        Paths whose hash was used; updated.
	 *
	 * @return string
	 */
	private static function hash( string $path, int $size, int $modified, string $content_dir, array &$cache, array &$used ): string {
		if ( $size > self::MAX_HASH_BYTES ) {
			return 'stat:' . $size . ':' . $modified;
		}

		$key          = self::cache_key( $path, $content_dir );
		$used[ $key ] = true;
		$stamp        = $size . ':' . $modified . ':';
		if ( isset( $cache[ $key ] ) && is_string( $cache[ $key ] ) && 0 === strpos( $cache[ $key ], $stamp ) ) {
			return substr( $cache[ $key ], strlen( $stamp ) );
		}

		$hash = @hash_file( 'sha256', $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unreadable file gets no hash.
		if ( false === $hash ) {
			return '';
		}

		$cache[ $key ] = $stamp . 'sha256:' . $hash;

		return 'sha256:' . $hash;
	}

	/**
	 * The key of a file in the hash cache: its path relative to `wp-content`.
	 *
	 * @param string $path        The full path.
	 * @param string $content_dir The `wp-content` folder.
	 *
	 * @return string
	 */
	private static function cache_key( string $path, string $content_dir ): string {
		$content_dir = rtrim( str_replace( '\\', '/', $content_dir ), '/' ) . '/';
		$path        = str_replace( '\\', '/', $path );

		return 0 === strpos( $path, $content_dir ) ? substr( $path, strlen( $content_dir ) ) : $path;
	}

	/**
	 * A quick fingerprint of the MU plugins folder and the drop-ins: the
	 * names, sizes and times of the files and folders in the root of
	 * `mu-plugins`, and of the drop-ins. Reads no file.
	 *
	 * @param string   $mu_dir       The MU plugins folder.
	 * @param string   $content_dir  The `wp-content` folder.
	 * @param string[] $dropin_files The drop-in file names.
	 *
	 * @return string
	 */
	public static function stat_fingerprint( string $mu_dir, string $content_dir, array $dropin_files ): string {
		$lines = [];
		$names = @scandir( $mu_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The folder may not exist.
		foreach ( false !== $names ? $names : [] as $name ) {
			if ( '.' === $name[0] ) {
				continue;
			}
			$stat = @stat( $mu_dir . '/' . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A file can disappear between the listing and here.
			if ( false !== $stat ) {
				$lines[] = 'mu/' . $name . ':' . $stat['size'] . ':' . $stat['mtime'];
			}
		}

		sort( $dropin_files, SORT_STRING );
		foreach ( $dropin_files as $file ) {
			$stat = @stat( rtrim( $content_dir, '/\\' ) . '/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A file can disappear between the listing and here.
			if ( false !== $stat ) {
				$lines[] = 'dropin/' . $file . ':' . $stat['size'] . ':' . $stat['mtime'];
			}
		}
		sort( $lines, SORT_STRING );

		return hash( 'sha256', implode( "\n", $lines ) );
	}

	/**
	 * Header text as plain text, cut to the longest kept.
	 *
	 * @param mixed $value The header value.
	 *
	 * @return string
	 */
	private static function text( $value ): string {
		$text = trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' ) );

		return mb_strlen( $text ) > self::MAX_TEXT ? mb_substr( $text, 0, self::MAX_TEXT ) : $text;
	}
}
