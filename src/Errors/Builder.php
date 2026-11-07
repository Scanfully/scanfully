<?php
/**
 * The PHP error builder class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Errors;

/**
 * Class Builder
 *
 * Turns an error as PHP reported it (type, message, file, line and stack
 * frames) into the error as it is stored and sent: its level, source,
 * cleaned message, relative file, function, trace without arguments,
 * context and fingerprint. An uncaught exception, which PHP reports as a
 * fatal error with the trace in the message, gets its message and trace
 * split apart.
 *
 * @package Scanfully\Errors
 */
final class Builder {

	/**
	 * The error types that are fatal: they end the request.
	 */
	public const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

	/**
	 * The error types that are warnings.
	 */
	public const WARNING = E_WARNING | E_USER_WARNING;

	/**
	 * The most stack frames sent.
	 */
	public const MAX_FRAMES = 10;

	/**
	 * The longest function name or file name kept.
	 */
	private const MAX_NAME = 200;

	/**
	 * The level of an error type: `fatal`, `warning`, or '' when it isn't
	 * monitored.
	 *
	 * @param int $type The PHP error type.
	 *
	 * @return string
	 */
	public static function level( int $type ): string {
		if ( 0 !== ( $type & self::FATAL ) ) {
			return 'fatal';
		}
		if ( 0 !== ( $type & self::WARNING ) ) {
			return 'warning';
		}

		return '';
	}

	/**
	 * The error as it is stored and sent; null when its type isn't
	 * monitored.
	 *
	 * @param array<string, mixed>                                                             $raw         The error as PHP reported it: `type`, `message`, `file`, `line` and `trace` (frames as `debug_backtrace()` gives them, without the handler's own frame).
	 * @param Attribution                                                                      $attribution The attribution.
	 * @param array{request: string, path: string, php: string, wp: string, maintenance: bool} $context The request context.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function build( array $raw, Attribution $attribution, array $context ): ?array {
		$level = self::level( (int) ( $raw['type'] ?? 0 ) );
		if ( '' === $level ) {
			return null;
		}

		$message = (string) ( $raw['message'] ?? '' );
		$file    = (string) ( $raw['file'] ?? '' );
		$line    = (int) ( $raw['line'] ?? 0 );
		$frames  = is_array( $raw['trace'] ?? null ) ? $raw['trace'] : [];

		if ( 'fatal' === $level && 0 === strpos( $message, 'Uncaught ' ) ) {
			$exception = self::split_exception( $message );
			$message   = $exception['message'];
			if ( [] === $frames ) {
				$frames = $exception['trace'];
			}
		}

		$trace    = self::frames( $frames, $attribution );
		$function = Scrubber::cap( (string) ( $trace[0]['function'] ?? '' ), self::MAX_NAME );
		$relative = Scrubber::cap( $attribution->relative( $file ), self::MAX_NAME );
		$message  = Scrubber::scrub( $attribution->relative_in_text( trim( $message ) ) );

		return [
			'fingerprint' => Fingerprint::of( $level, $relative, $function, $message ),
			'level'       => $level,
			'source'      => $attribution->source( $file, $frames ),
			'message'     => $message,
			'file'        => $relative,
			'line'        => max( 0, $line ),
			'function'    => $function,
			'trace'       => $trace,
			'context'     => $context,
		];
	}

	/**
	 * The message and stack trace of an uncaught exception, which PHP
	 * reports as one fatal error message: `Uncaught RuntimeException: boom
	 * in /site/wp-content/plugins/x/x.php:12\nStack trace:\n#0 /site/...(34):
	 * x_run()\n#1 {main}\n  thrown`.
	 *
	 * @param string $message The fatal error message.
	 *
	 * @return array{message: string, trace: array<int, array{file: string, line: int, function: string}>}
	 */
	public static function split_exception( string $message ): array {
		$trace = [];
		$parts = preg_split( '/\R\s*Stack trace:\s*\R/', $message, 2 );
		$head  = is_array( $parts ) ? (string) $parts[0] : $message;
		$body  = is_array( $parts ) && isset( $parts[1] ) ? (string) $parts[1] : '';

		// The place it was thrown is already the error's file and line.
		$head = (string) preg_replace( '/ in \S+:\d+\s*$/', '', rtrim( $head ) );

		$lines = preg_split( '/\R/', $body );
		foreach ( is_array( $lines ) ? $lines : [] as $line ) {
			if ( 1 === preg_match( '/^#\d+ (?:(.+?)\((\d+)\): )?(.+)$/', trim( $line ), $match ) ) {
				if ( '{main}' === $match[3] ) {
					continue;
				}
				$trace[] = [
					'file'     => (string) $match[1],
					'line'     => (int) $match[2],
					'function' => (string) preg_replace( '/\(.*$/s', '', $match[3] ),
				];
			}
		}

		return [
			'message' => $head,
			'trace'   => $trace,
		];
	}

	/**
	 * The stack frames as they are sent: file (relative), line and
	 * function, no arguments, at most MAX_FRAMES of them.
	 *
	 * @param array<int, mixed> $frames      The frames as `debug_backtrace()` or split_exception() gives them.
	 * @param Attribution       $attribution The attribution.
	 *
	 * @return array<int, array{file: string, line: int, function: string}>
	 */
	public static function frames( array $frames, Attribution $attribution ): array {
		$trace = [];
		foreach ( $frames as $frame ) {
			if ( ! is_array( $frame ) ) {
				continue;
			}
			$trace[] = [
				'file'     => Scrubber::cap( $attribution->relative( (string) ( $frame['file'] ?? '' ) ), self::MAX_NAME ),
				'line'     => max( 0, (int) ( $frame['line'] ?? 0 ) ),
				'function' => Scrubber::cap( self::function_name( $frame ), self::MAX_NAME ),
			];
			if ( count( $trace ) >= self::MAX_FRAMES ) {
				break;
			}
		}

		return $trace;
	}

	/**
	 * The function of a frame, with its class: `WP_Hook->apply_filters`,
	 * `Plugin::boot`, `my_plugin_init`, `{closure}`.
	 *
	 * @param array<string, mixed> $frame The frame.
	 *
	 * @return string
	 */
	public static function function_name( array $frame ): string {
		$function = (string) ( $frame['function'] ?? '' );
		$class    = (string) ( $frame['class'] ?? '' );
		if ( '' === $class ) {
			return $function;
		}

		return $class . (string) ( $frame['type'] ?? '::' ) . $function;
	}
}
