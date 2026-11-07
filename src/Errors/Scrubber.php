<?php
/**
 * The PHP error scrubber class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Errors;

/**
 * Class Scrubber
 *
 * Removes what looks like a secret or personal data from an error message
 * before it leaves the site: email addresses, passwords, tokens and keys
 * named in the text, query strings of URLs and long random-looking values.
 * Runs on every message that is sent, so it only uses regular expressions
 * that cannot fail on odd input.
 *
 * @package Scanfully\Errors
 */
final class Scrubber {

	/**
	 * The longest message sent.
	 */
	public const MAX_LENGTH = 1000;

	/**
	 * Patterns and their replacements, in order.
	 */
	private const PATTERNS = [
		// A query string of a URL or path: keys and values are often secrets.
		'#((?:https?://|wss?://|/)[^\s?\'"<>()]*)\?[^\s\'"<>()]+#i' => '$1?[query]',
		// An email address.
		'/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '[email]',
		// A named secret: password=..., token: ..., api_key => ....
		'/\b(password|passwd|pwd|pass|secret|token|api[_\-]?key|private[_\-]?key|client[_\-]?secret|access[_\-]?token|refresh[_\-]?token|auth|authorization|nonce|signature)\b([\'"]?\s*(?:=>|=|:)\s*)(?:(?:Bearer|Basic)\s+)?[\'"]?[^\s\'",;)]+[\'"]?/i' => '$1$2[redacted]',
		// A bearer or basic credential on its own.
		'/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]{8,}/' => '$1 [redacted]',
		// Keys with a known prefix (Stripe, AWS, GitHub, Slack, Google).
		'/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]+/' => '[key]',
		'/\bAKIA[0-9A-Z]{16}\b/' => '[key]',
		'/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{20,}\b/' => '[key]',
		'/\bxox[abprs]-[A-Za-z0-9\-]+/' => '[key]',
		'/\bAIza[0-9A-Za-z_\-]{30,}\b/' => '[key]',
		// A long hexadecimal value: a hash, a session id, a token.
		'/\b(?=[0-9a-f]*\d)[0-9a-f]{32,}\b/i' => '[key]',
		// A long value of letters and digits with no spaces: a key.
		'/(?<![A-Za-z0-9_\-+=\/.])(?=[A-Za-z0-9_\-+=]*\d)(?=[A-Za-z0-9_\-+=]*[A-Za-z])[A-Za-z0-9_\-+=]{40,}(?![A-Za-z0-9_\-+=\/.])/' => '[key]',
	];

	/**
	 * A message with secrets replaced and cut to the longest sent.
	 *
	 * @param string $text The message.
	 *
	 * @return string
	 */
	public static function scrub( string $text ): string {
		foreach ( self::PATTERNS as $pattern => $replacement ) {
			$scrubbed = preg_replace( $pattern, $replacement, $text );
			if ( is_string( $scrubbed ) ) {
				$text = $scrubbed;
			}
		}

		return self::cap( $text, self::MAX_LENGTH );
	}

	/**
	 * Text cut to a number of characters, as UTF-8 when the text is valid
	 * UTF-8 and as bytes otherwise.
	 *
	 * @param string $text   The text.
	 * @param int    $length The most characters kept.
	 *
	 * @return string
	 */
	public static function cap( string $text, int $length ): string {
		if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) && preg_match( '//u', $text ) ) {
			return mb_strlen( $text, 'UTF-8' ) > $length ? mb_substr( $text, 0, $length, 'UTF-8' ) : $text;
		}

		return strlen( $text ) > $length ? substr( $text, 0, $length ) : $text;
	}
}
