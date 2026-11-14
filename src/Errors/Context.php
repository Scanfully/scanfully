<?php
/**
 * The PHP error context class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Errors;

/**
 * Class Context
 *
 * What was going on when an error was raised: the kind of request (front
 * end, admin, AJAX, REST, cron or WP-CLI), the URL path without its query
 * string, the PHP and WordPress versions, and whether WordPress was in
 * maintenance mode, as it is while an update replaces files.
 *
 * @package Scanfully\Errors
 */
final class Context {

	/**
	 * The kinds of request, as the API knows them.
	 */
	public const FRONT = 'front';
	public const ADMIN = 'admin';
	public const AJAX  = 'ajax';
	public const REST  = 'rest';
	public const CRON  = 'cron';
	public const CLI   = 'cli';

	/**
	 * The longest path kept.
	 */
	private const MAX_PATH = 200;

	/**
	 * The context of the current request.
	 *
	 * @return array{request: string, path: string, php: string, wp: string, maintenance: bool}
	 */
	public static function current(): array {
		$server = $_SERVER; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only the path of the request URI is kept, sanitized in path().

		// wp_doing_cron(), wp_doing_ajax() and is_admin() read the constants
		// an entry point defines before WordPress loads, so they work for an
		// error raised while plugins load too.
		return [
			'request'     => self::kind(
				$server,
				[
					'cli'   => defined( 'WP_CLI' ) && WP_CLI,
					'cron'  => function_exists( 'wp_doing_cron' ) && wp_doing_cron(),
					'ajax'  => function_exists( 'wp_doing_ajax' ) && wp_doing_ajax(),
					'rest'  => defined( 'REST_REQUEST' ) && REST_REQUEST,
					'admin' => function_exists( 'is_admin' ) && is_admin(),
				]
			),
			'path'        => self::path( $server ),
			'php'         => PHP_VERSION,
			'wp'          => (string) ( $GLOBALS['wp_version'] ?? '' ),
			'maintenance' => self::maintenance(),
		];
	}

	/**
	 * The kind of request. A REST request is also known by its path, as
	 * WordPress only sets `REST_REQUEST` once it dispatches the request,
	 * which is after an error raised while plugins load.
	 *
	 * @param array<string, mixed> $server The server variables.
	 * @param array<string, bool>  $flags  What WordPress knows: `cli`, `cron`, `ajax`, `rest` and `admin`.
	 *
	 * @return string
	 */
	public static function kind( array $server, array $flags ): string {
		if ( ! empty( $flags['cli'] ) || ( PHP_SAPI === 'cli' && ! isset( $server['REQUEST_METHOD'] ) ) ) {
			return self::CLI;
		}
		if ( ! empty( $flags['cron'] ) ) {
			return self::CRON;
		}
		if ( ! empty( $flags['ajax'] ) ) {
			return self::AJAX;
		}
		$path = self::path( $server );
		$uri  = (string) ( $server['REQUEST_URI'] ?? '' );
		if ( ! empty( $flags['rest'] ) || 1 === preg_match( '#^/(?:index\.php/)?wp-json(?:/|$)#', $path ) || 1 === preg_match( '/[?&]rest_route=/', $uri ) ) {
			return self::REST;
		}
		if ( ! empty( $flags['admin'] ) ) {
			return self::ADMIN;
		}

		return self::FRONT;
	}

	/**
	 * The path of the request without its query string, cut to the longest
	 * kept; empty on the command line.
	 *
	 * @param array<string, mixed> $server The server variables.
	 *
	 * @return string
	 */
	public static function path( array $server ): string {
		$uri = (string) ( $server['REQUEST_URI'] ?? '' );
		if ( '' === $uri ) {
			return '';
		}
		$cut = strpos( $uri, '?' );
		if ( false !== $cut ) {
			$uri = substr( $uri, 0, $cut );
		}
		$cut = strpos( $uri, '#' );
		if ( false !== $cut ) {
			$uri = substr( $uri, 0, $cut );
		}
		$uri = (string) preg_replace( '/[\x00-\x1f\x7f]/', '', $uri );

		return Scrubber::cap( $uri, self::MAX_PATH );
	}

	/**
	 * Whether WordPress is in maintenance mode: an update is replacing
	 * files, so errors are expected.
	 *
	 * @return bool
	 */
	public static function maintenance(): bool {
		try {
			if ( function_exists( 'wp_is_maintenance_mode' ) ) {
				return (bool) wp_is_maintenance_mode();
			}

			return defined( 'ABSPATH' ) && file_exists( ABSPATH . '.maintenance' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
