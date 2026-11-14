<?php
/**
 * The PHP error fingerprint class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Errors;

/**
 * Class Fingerprint
 *
 * One fingerprint per distinct error: the level, the file relative to the
 * WordPress root, the function, and the message with numbers, quoted values
 * and identifiers replaced. The line number is left out on purpose, so the
 * same bug in the next version of a plugin keeps its fingerprint.
 *
 * @package Scanfully\Errors
 */
final class Fingerprint {

	/**
	 * The fingerprint of an error.
	 *
	 * @param string $level    `fatal` or `warning`.
	 * @param string $file     The file, relative to the WordPress root.
	 * @param string $function The function the error was raised in.
	 * @param string $message  The message.
	 *
	 * @return string A SHA-1 hex digest.
	 */
	public static function of( string $level, string $file, string $function, string $message ): string {
		return sha1( $level . "\n" . $file . "\n" . $function . "\n" . self::normalize( $message ) );
	}

	/**
	 * A message with the values that change between occurrences replaced:
	 * quoted values, hexadecimal identifiers and numbers become `?`, and
	 * whitespace is collapsed.
	 *
	 * @param string $message The message.
	 *
	 * @return string
	 */
	public static function normalize( string $message ): string {
		$patterns = [
			// Quoted values: 'foo', "bar", `baz`.
			'/\'(?:[^\'\\\\]|\\\\.)*\'/'   => "'?'",
			'/"(?:[^"\\\\]|\\\\.)*"/'       => '"?"',
			'/`[^`]*`/'                     => '`?`',
			// Hexadecimal identifiers and hashes, then any number.
			'/\b(?=[0-9a-f]*\d)[0-9a-f]{8,}\b/i' => '?',
			'/\b0x[0-9a-f]+\b/i'            => '?',
			'/(?<![A-Za-z_])\d+(?:[.,]\d+)*/' => '?',
			// Whitespace.
			'/\s+/'                         => ' ',
		];
		foreach ( $patterns as $pattern => $replacement ) {
			$normalized = preg_replace( $pattern, $replacement, $message );
			if ( is_string( $normalized ) ) {
				$message = $normalized;
			}
		}

		return trim( $message );
	}
}
