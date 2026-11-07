<?php
/**
 * The Manage failure class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

/**
 * Turns what WordPress's upgraders report into the API's error classes and
 * into text that is safe to post: an error message or a log line is site
 * data, so URLs lose their query string, fragment and credentials (a
 * premium plugin's package URL carries its license key) and absolute server
 * paths become relative to the WordPress folder.
 */
final class Failure {

	/**
	 * The longest log line posted.
	 */
	private const MAX_LINE_LENGTH = 300;

	/**
	 * WP_Error codes, or their beginnings, that mean WordPress could not
	 * write the files.
	 */
	private const FILESYSTEM_CODES = [
		'fs_',
		'mkdir_failed',
		'copy_failed',
		'remove_old_failed',
		'files_not_writable',
		'disk_full',
		'folder_exists',
		'move_dir',
		'temp_backup',
		'could_not_remove',
		'could_not_create',
		'unable_to_connect_to_filesystem',
	];

	/**
	 * WP_Error codes, or their beginnings, that mean the package itself was
	 * wrong or could not be read.
	 */
	private const PACKAGE_CODES = [
		'download_failed',
		'http_',
		'incompatible_archive',
		'empty_archive',
		'bad_request',
		'signature_verification',
		'no_package',
		'package_',
	];

	/**
	 * WP_Error codes, or their beginnings, that mean the site does not meet
	 * the new version's requirements.
	 */
	private const REQUIREMENT_CODES = [
		'incompatible_wp_required_version',
		'incompatible_php_required_version',
		'php_not_compatible',
		'mysql_not_compatible',
		'plugin_wp_php_incompatible',
		'plugin_wp_incompatible',
		'plugin_php_incompatible',
	];

	/**
	 * Download answers that mean the package needs a license or a sign-in.
	 */
	private const LICENSE_ANSWERS = [ 'unauthorized', 'forbidden', 'payment required' ];

	/**
	 * The error class and the message of a WP_Error an upgrader returned.
	 *
	 * @param \WP_Error $error The error.
	 *
	 * @return array{class: string, message: string}
	 */
	public static function of( \WP_Error $error ): array {
		$code    = (string) $error->get_error_code();
		$detail  = $error->get_error_data();
		$message = trim( $error->get_error_message() . ( is_string( $detail ) && '' !== $detail ? ' ' . $detail : '' ) );

		return [
			'class'   => self::class_of( $code, $message ),
			'message' => self::clean( $message ),
		];
	}

	/**
	 * The error class of a WP_Error code and its message.
	 *
	 * @param string $code    The code.
	 * @param string $message The message, with its detail.
	 *
	 * @return string
	 */
	public static function class_of( string $code, string $message ): string {
		$lower = strtolower( $message );
		if ( false !== strpos( $lower, 'curl error 28' ) || false !== strpos( $lower, 'timed out' ) || false !== strpos( $lower, 'maximum execution time' ) ) {
			return 'timeout';
		}
		if ( 'up_to_date' === $code ) {
			return 'not_available';
		}
		// WordPress's own core update lock is not the site's run lock
		// (class locked): another update ran in WordPress itself.
		if ( 'no_package' === $code ) {
			return 'license';
		}
		if ( self::starts( $code, self::REQUIREMENT_CODES ) ) {
			return 'not_supported';
		}
		if ( self::starts( $code, self::FILESYSTEM_CODES ) ) {
			return 'filesystem';
		}
		if ( self::starts( $code, self::PACKAGE_CODES ) ) {
			foreach ( self::LICENSE_ANSWERS as $answer ) {
				if ( false !== strpos( $lower, $answer ) ) {
					return 'license';
				}
			}
			return 'package';
		}

		return 'other';
	}

	/**
	 * Whether a code starts with one of the beginnings.
	 *
	 * @param string   $code       The code.
	 * @param string[] $beginnings The beginnings.
	 *
	 * @return bool
	 */
	private static function starts( string $code, array $beginnings ): bool {
		foreach ( $beginnings as $beginning ) {
			if ( 0 === strpos( $code, $beginning ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Text safe to post: plain text on one line, URLs without query string,
	 * fragment or credentials, server paths relative to WordPress.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	public static function clean( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		// A URL is printable ASCII: what WordPress writes right after one
		// (an ellipsis, a closing quote) is not part of it.
		$text = (string) preg_replace_callback( '#\b(?:https?|ftps?|ssh2?)://[\x21\x23-\x26\x28-\x3B\x3D\x3F-\x7E]+#i', [ self::class, 'clean_url' ], $text );
		$text = self::relative_paths( $text );

		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * The log lines of an upgrader skin, cleaned, at most a number of the
	 * last ones, each at most a few hundred characters.
	 *
	 * @param array<int, mixed> $lines The lines.
	 * @param int               $max   The most lines kept.
	 *
	 * @return string[]
	 */
	public static function log( array $lines, int $max = 60 ): array {
		$out = [];
		foreach ( $lines as $line ) {
			if ( ! is_string( $line ) ) {
				continue;
			}
			$line = self::clean( $line );
			if ( '' !== $line ) {
				$out[] = mb_substr( $line, 0, self::MAX_LINE_LENGTH );
			}
		}

		return array_slice( $out, -$max );
	}

	/**
	 * A URL as scheme, host and its last path segment only: no credentials,
	 * query string or fragment, and no folders, where some vendors put the
	 * license key.
	 *
	 * @param array<int, string> $match The URL found.
	 *
	 * @return string
	 */
	private static function clean_url( array $match ): string {
		$url   = rtrim( $match[0], '.,;:)' );
		$tail  = substr( $match[0], strlen( $url ) );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return '[url]' . $tail;
		}

		$segments = array_values( array_filter( explode( '/', (string) ( $parts['path'] ?? '' ) ), static fn( string $segment ): bool => '' !== $segment ) );
		$path     = '';
		if ( [] !== $segments ) {
			$path = ( count( $segments ) > 1 ? '/…/' : '/' ) . end( $segments );
		}

		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $path . $tail;
	}

	/**
	 * Server paths made relative to the WordPress folder.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private static function relative_paths( string $text ): string {
		$roots = [];
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$roots[ rtrim( (string) WP_CONTENT_DIR, '/\\' ) ] = 'wp-content';
		}
		if ( defined( 'ABSPATH' ) ) {
			$roots[ rtrim( (string) ABSPATH, '/\\' ) ] = '.';
		}
		uksort(
			$roots,
			static function ( string $a, string $b ): int {
				return strlen( $b ) - strlen( $a );
			}
		);
		foreach ( $roots as $root => $label ) {
			if ( strlen( $root ) > 1 ) {
				$text = str_replace( $root, $label, $text );
			}
		}

		return $text;
	}
}
