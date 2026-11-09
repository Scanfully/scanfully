<?php
/**
 * The Manage package class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

use Scanfully\Main;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A JobFailed message is never printed: it is posted to the API as JSON, cleaned by Failure::clean(), and HTML escaping would garble it.

/**
 * The download of an upload a job installs: the pull gives a short-lived,
 * single-use URL on the API's own origin next to the job's token, and the
 * job's signed parameters hold the file's SHA-256. The URL is taken only on
 * the origin that signs the jobs, the file goes to a temporary file, its
 * SHA-256 is checked against the signed one before anything is unpacked,
 * what it unpacks to is counted (inspect()), and the temporary file is removed on every way out: by the action when it
 * ends, and by the run's clean-up after a PHP fatal.
 */
final class Package {

	/**
	 * The largest download taken: Scanfully takes uploads of up to 50 MB.
	 */
	public const MAX_BYTES = 50 * 1024 * 1024;

	/**
	 * The most bytes an upload may unpack to.
	 */
	public const MAX_UNPACKED = 256 * 1024 * 1024;

	/**
	 * The most entries (files and folders) an upload may hold.
	 */
	public const MAX_ENTRIES = 20000;

	/**
	 * Bytes read at a time while counting what an entry unpacks to.
	 */
	private const CHUNK = 1024 * 1024;

	/**
	 * What a download that is not the file Scanfully checked fails with.
	 */
	private const MISMATCH = 'The downloaded upload does not match the SHA-256 checksum Scanfully gave for it, so nothing was unpacked or installed.';

	/**
	 * Seconds the download may take.
	 */
	private const TIMEOUT = 300;

	/**
	 * Download the job's upload to a temporary file and check its SHA-256.
	 *
	 * @param JobRun $run    The run, which gave the download and removes the file on the way out.
	 * @param string $sha256 The SHA-256 the job's signed parameters hold.
	 *
	 * @return array{file: string, source: string} The temporary file, and the host it came from.
	 * @throws JobFailed When the job has no download, it failed, or the file is not the one Scanfully checked.
	 */
	public static function fetch( JobRun $run, string $sha256 ): array {
		$package = $run->package();
		if ( null === $package ) {
			throw new JobFailed( 'package', 'The job came without the download of its upload, so nothing was installed.' );
		}
		if ( ! hash_equals( strtolower( $sha256 ), $package['sha256'] ) ) {
			throw new JobFailed( 'package', 'The download Scanfully gave for the upload names another checksum than the job Scanfully signed, so nothing was installed.' );
		}
		if ( ! self::on_api_origin( $package['url'] ) ) {
			throw new JobFailed( 'package', 'The download of the upload is not on Scanfully\'s own address, so the plugin did not fetch it and nothing was installed.' );
		}
		$source = strtolower( (string) wp_parse_url( $package['url'], PHP_URL_HOST ) );

		$file = function_exists( 'wp_tempnam' ) ? (string) wp_tempnam( 'scanfully-upload.zip' ) : '';
		if ( '' === $file ) {
			throw new JobFailed( 'filesystem', 'WordPress could not make a temporary file for the upload, so nothing was installed.' );
		}
		// Removed on every way out, a PHP fatal included.
		$run->on_exit(
			static function () use ( $file ): ?array {
				self::remove( $file );
				return null;
			}
		);

		try {
			self::download( $package['url'], $file, $run, $package['size'] ?? null );
			self::verify( $file, $sha256 );
			self::inspect( $file, $run );
		} catch ( JobFailed $e ) {
			self::remove( $file );
			throw $e;
		}
		$run->log( sprintf( 'Scanfully downloaded the upload from %s and checked its SHA-256.', $source ) );

		return [
			'file'   => $file,
			'source' => $source,
		];
	}

	/**
	 * Whether a URL is on the origin that signs the jobs (the job token's
	 * iss): its scheme, host and port.
	 *
	 * @param string $url The URL.
	 *
	 * @return bool
	 */
	private static function on_api_origin( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$origin = strtolower( $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );

		return strtolower( rtrim( Main::get_oauth_url(), '/' ) ) === $origin;
	}

	/**
	 * Download a URL to a file, with a heartbeat before and after. The API
	 * serves the file itself: no redirect is followed. No more is read than
	 * the size the pull named (when it named one) and 50 MB, plus one byte
	 * to tell a larger file.
	 *
	 * @param string   $url  The URL.
	 * @param string   $file The file.
	 * @param JobRun   $run  The run.
	 * @param int|null $size The file's size in bytes the pull named, if any.
	 *
	 * @return void
	 * @throws JobFailed When the download failed, or is not the size named.
	 */
	private static function download( string $url, string $file, JobRun $run, ?int $size ): void {
		$limit = ( null === $size ? self::MAX_BYTES : min( self::MAX_BYTES, $size ) ) + 1;
		$run->beat( true );
		$response = wp_remote_get(
			$url,
			[
				'timeout'             => self::TIMEOUT,
				'stream'              => true,
				'filename'            => $file,
				'limit_response_size' => $limit,
				'redirection'         => 0,
				'headers'             => [ 'Accept' => 'application/zip, application/octet-stream' ],
			]
		);
		$run->beat( true );
		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			throw new JobFailed(
				'timeout' === Failure::class_of( (string) $response->get_error_code(), $message ) ? 'timeout' : 'package',
				'The upload could not be downloaded: ' . $message
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			throw new JobFailed( 'package', sprintf( 'The upload could not be downloaded: the download answered HTTP %d. Its link is single use and valid until the job\'s lease ends.', $status ) );
		}
		clearstatcache( true, $file );
		$got = is_file( $file ) ? (int) filesize( $file ) : 0;
		if ( 0 === $got ) {
			throw new JobFailed( 'package', 'The upload could not be downloaded: the download was empty.' );
		}
		if ( $got > self::MAX_BYTES ) {
			throw new JobFailed( 'package', sprintf( 'The upload is larger than the %d MB Scanfully takes, so it was not installed.', (int) ( self::MAX_BYTES / 1024 / 1024 ) ) );
		}
		if ( null !== $size && $got !== $size ) {
			throw new JobFailed( 'package', self::MISMATCH );
		}
	}

	/**
	 * The flags to open the upload with: read only where PHP has
	 * ZipArchive::RDONLY (PHP 7.4.3 and later), else the default, which
	 * opens an existing file without creating or truncating it. Nothing is
	 * ever written to the archive either way.
	 *
	 * @param bool $has_rdonly Whether ZipArchive::RDONLY exists.
	 *
	 * @return int
	 */
	public static function open_flags( bool $has_rdonly ): int {
		return $has_rdonly ? \ZipArchive::RDONLY : 0;
	}

	/**
	 * Check what an upload unpacks to before WordPress unpacks it: at most
	 * 20,000 entries, all inside exactly one top-level folder, none with an
	 * absolute path or "..", and at most 256 MB in all. The sizes a ZIP
	 * declares can lie (WordPress compares them only with the free disk
	 * space), so every entry is also inflated and its bytes counted, never
	 * written anywhere.
	 *
	 * @param string $file        The ZIP.
	 * @param JobRun $run         The run, for heartbeats while counting.
	 * @param int    $max_bytes   The most bytes it may unpack to.
	 * @param int    $max_entries The most entries it may hold.
	 *
	 * @return void
	 * @throws JobFailed When it is not a ZIP Scanfully installs.
	 */
	public static function inspect( string $file, JobRun $run, int $max_bytes = self::MAX_UNPACKED, int $max_entries = self::MAX_ENTRIES ): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new JobFailed( 'package', 'The site\'s PHP has no ZipArchive, so Scanfully cannot check the upload before it is unpacked, and did not install it.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file, self::open_flags( defined( 'ZipArchive::RDONLY' ) ) ) ) {
			throw new JobFailed( 'package', 'The upload is not a ZIP file the site can read, so nothing was unpacked or installed.' );
		}
		try {
			$count = (int) $zip->count();
			if ( 0 === $count || $count > $max_entries ) {
				throw new JobFailed( 'package', sprintf( 'The upload holds %d entries; Scanfully installs a ZIP of 1 to %d, so nothing was unpacked or installed.', $count, $max_entries ) );
			}
			$names    = [];
			$seen     = [];
			$declared = 0;
			for ( $i = 0; $i < $count; $i++ ) {
				$stat = $zip->statIndex( $i );
				if ( ! is_array( $stat ) ) {
					throw new JobFailed( 'package', 'The upload has an entry the site cannot read, so nothing was unpacked or installed.' );
				}
				$names[ $i ] = (string) $stat['name'];
				$declared   += (int) $stat['size'];
				// Each entry is read by its name below: two entries with one
				// name would let the first be counted twice and the second
				// never, and unpacking would put one over the other.
				if ( isset( $seen[ $names[ $i ] ] ) ) {
					throw new JobFailed( 'package', 'The upload has two entries with the same name, so nothing was unpacked or installed.' );
				}
				$seen[ $names[ $i ] ] = true;
			}
			self::check_names( $names );
			if ( $declared > $max_bytes ) {
				throw new JobFailed( 'package', self::too_large( $max_bytes ) );
			}
			$unpacked = 0;
			foreach ( $names as $name ) {
				if ( '/' === substr( $name, -1 ) ) {
					continue;
				}
				// By name: getStreamIndex() exists only from PHP 8.2. The names
				// are unique (checked above), so the name is the entry.
				$stream = $zip->getStream( $name );
				if ( ! is_resource( $stream ) ) {
					throw new JobFailed( 'package', 'The upload has an entry the site cannot unpack, so nothing was unpacked or installed.' );
				}
				try {
					while ( ! feof( $stream ) ) {
						$chunk = fread( $stream, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Counting bytes from a ZIP stream, nothing is written.
						if ( false === $chunk ) {
							throw new JobFailed( 'package', 'The upload has an entry the site cannot unpack, so nothing was unpacked or installed.' );
						}
						$unpacked += strlen( $chunk );
						if ( $unpacked > $max_bytes ) {
							throw new JobFailed( 'package', self::too_large( $max_bytes ) );
						}
						if ( '' === $chunk ) {
							break;
						}
					}
				} finally {
					fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The ZIP entry's stream.
				}
				$run->beat();
			}
		} finally {
			$zip->close();
		}
	}

	/**
	 * Check the entry names of an upload: all inside exactly one top-level
	 * folder, none absolute or with "..".
	 *
	 * @param string[] $names The entry names.
	 *
	 * @return void
	 * @throws JobFailed When they are not.
	 */
	private static function check_names( array $names ): void {
		$top = null;
		foreach ( $names as $name ) {
			$path = str_replace( '\\', '/', $name );
			if ( '' === $path || '/' === $path[0] || 1 === preg_match( '#(^|/)\.\.(/|$)#', $path ) || 1 === preg_match( '#^[A-Za-z]:#', $path ) ) {
				throw new JobFailed( 'package', 'The upload has an entry outside its folder (an absolute path or ".."), so nothing was unpacked or installed.' );
			}
			$slash = strpos( $path, '/' );
			if ( false === $slash ) {
				throw new JobFailed( 'package', 'The upload has a file outside a folder: Scanfully installs a ZIP with exactly one top-level folder, so nothing was unpacked or installed.' );
			}
			$folder = substr( $path, 0, $slash );
			if ( null !== $top && $folder !== $top ) {
				throw new JobFailed( 'package', 'The upload has more than one top-level folder: Scanfully installs a ZIP with exactly one, so nothing was unpacked or installed.' );
			}
			$top = $folder;
		}
	}

	/**
	 * The message of an upload that unpacks to too much.
	 *
	 * @param int $max_bytes The most bytes it may unpack to.
	 *
	 * @return string
	 */
	private static function too_large( int $max_bytes ): string {
		return sprintf( 'The upload unpacks to more than %d MB, so nothing was unpacked or installed.', (int) ceil( $max_bytes / 1024 / 1024 ) );
	}

	/**
	 * Check a file against the SHA-256 Scanfully gave for it.
	 *
	 * @param string $file   The file.
	 * @param string $sha256 The signed SHA-256, as hex.
	 *
	 * @return void
	 * @throws JobFailed When it is another file.
	 */
	private static function verify( string $file, string $sha256 ): void {
		$actual = (string) hash_file( 'sha256', $file );
		if ( '' === $actual || ! hash_equals( strtolower( $sha256 ), $actual ) ) {
			throw new JobFailed( 'package', self::MISMATCH );
		}
	}

	/**
	 * Remove a temporary file, when it is still there.
	 *
	 * @param string $file The file.
	 *
	 * @return void
	 */
	public static function remove( string $file ): void {
		clearstatcache( true, $file );
		if ( '' !== $file && is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
}
