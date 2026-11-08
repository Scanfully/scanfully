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
 * and the temporary file is removed on every way out: by the action when it
 * ends, and by the run's clean-up after a PHP fatal.
 */
final class Package {

	/**
	 * The largest download taken: Scanfully takes uploads of up to 50 MB.
	 */
	public const MAX_BYTES = 60 * 1024 * 1024;

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
			self::download( $package['url'], $file, $run );
			self::verify( $file, $sha256 );
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
	 * serves the file itself: no redirect is followed.
	 *
	 * @param string $url  The URL.
	 * @param string $file The file.
	 * @param JobRun $run  The run.
	 *
	 * @return void
	 * @throws JobFailed When the download failed.
	 */
	private static function download( string $url, string $file, JobRun $run ): void {
		$run->beat( true );
		$response = wp_remote_get(
			$url,
			[
				'timeout'             => self::TIMEOUT,
				'stream'              => true,
				'filename'            => $file,
				'limit_response_size' => self::MAX_BYTES,
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
		$size = is_file( $file ) ? (int) filesize( $file ) : 0;
		if ( 0 === $size ) {
			throw new JobFailed( 'package', 'The upload could not be downloaded: the download was empty.' );
		}
		if ( $size >= self::MAX_BYTES ) {
			throw new JobFailed( 'package', sprintf( 'The upload is larger than the %d MB Scanfully takes, so it was not installed.', (int) ( self::MAX_BYTES / 1024 / 1024 ) ) );
		}
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
			throw new JobFailed( 'package', 'The downloaded upload does not match the SHA-256 checksum Scanfully gave for it, so nothing was unpacked or installed.' );
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
