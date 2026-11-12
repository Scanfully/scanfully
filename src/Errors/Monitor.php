<?php
/**
 * The PHP error monitor class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Errors;

use Scanfully\API\ErrorsRequest;
use Scanfully\Connect\Children;
use Scanfully\Connect\Network;
use Scanfully\Connect\Target;
use Scanfully\Connect\Targets;
use Scanfully\Options\Controller as OptionsController;

/**
 * Class Monitor
 *
 * Catches PHP fatal errors and warnings and keeps them until shutdown:
 *
 * - An error handler for warnings. Without another handler it is registered
 *   with a level mask, so PHP never calls it for notices or deprecations.
 *   It passes every error on to the handler that was there before and
 *   returns what that one returns (false without one), so PHP's own logging,
 *   `display_errors`, Query Monitor, Sentry and the like behave as before.
 *   Errors silenced with `@` are skipped.
 * - A shutdown function that reads `error_get_last()` for a fatal error,
 *   including an uncaught exception, which PHP reports as one. WordPress's
 *   own fatal error handler runs first and does not exit, so this still runs.
 *
 * At shutdown the errors are grouped by fingerprint and added to the Store
 * with one query. A new fatal error is also sent straight away, after the
 * response is finished, at most once per fingerprint per 10 minutes, for a
 * site that fails on every request (cron included).
 *
 * Booted by the must-use plugin before any plugin loads, or else by the
 * Scanfully plugin while plugins load. Nothing in a handler can throw: a
 * recursion guard, caps on what is kept per request, and every step in a
 * try block.
 *
 * @package Scanfully\Errors
 */
final class Monitor {

	/**
	 * Option that turns capture off: `no`. On by default.
	 */
	public const ENABLED_OPTION = 'scanfully_connect_php_errors_enabled';

	/**
	 * The most distinct errors kept per request.
	 */
	public const MAX_PER_REQUEST = 20;

	/**
	 * The longest message kept in memory before it is cleaned.
	 */
	private const MAX_RAW_MESSAGE = 4000;

	/**
	 * Seconds between direct sends of the same fatal error.
	 */
	public const DIRECT_PERIOD = 10 * MINUTE_IN_SECONDS;

	/**
	 * Timeout of a direct send, in seconds.
	 */
	public const DIRECT_TIMEOUT = 2;

	/**
	 * What booted the monitor: `mu` (the must-use plugin), `plugin`, or ''
	 * when it didn't run.
	 *
	 * @var string
	 */
	private static string $origin = '';

	/**
	 * Whether the handler is registered for every error type, because
	 * another handler was there first.
	 *
	 * @var bool
	 */
	private static bool $all_types = false;

	/**
	 * The handler that was there before.
	 *
	 * @var callable|null
	 */
	private static $previous = null;

	/**
	 * Whether the monitor is handling an error or flushing, so an error in
	 * its own code is passed on, not caught.
	 *
	 * @var bool
	 */
	private static bool $busy = false;

	/**
	 * The errors of this request as PHP reported them, by type, file and
	 * line, each with `count`, `first_seen` and `last_seen`.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $errors = [];

	/**
	 * Distinct errors past the cap of this request.
	 *
	 * @var int
	 */
	private static int $dropped = 0;

	/**
	 * Start catching errors, once per request: when the site is connected,
	 * capture isn't turned off, and (from the must-use plugin) the
	 * Scanfully plugin is active.
	 *
	 * @param string $origin   `mu` or `plugin`.
	 * @param string $basename The Scanfully plugin's basename, for the must-use plugin's check.
	 *
	 * @return void
	 */
	public static function boot( string $origin, string $basename = '' ): void {
		if ( '' !== self::$origin ) {
			return;
		}

		try {
			if ( 'mu' === $origin && '' !== $basename ) {
				Network::use_basename( $basename );
			}
			if ( ! self::should_run( 'mu' === $origin ? $basename : '' ) ) {
				return;
			}
			self::$origin = $origin;

			$previous = set_error_handler( [ self::class, 'handle' ], Builder::WARNING );
			if ( null !== $previous ) {
				// Another handler was there first: take every type, so the
				// ones it takes still reach it.
				restore_error_handler();
				$previous        = set_error_handler( [ self::class, 'handle' ] );
				self::$all_types = true;
			}
			self::$previous = is_callable( $previous ) ? $previous : null;

			register_shutdown_function( [ self::class, 'shutdown' ] );
		} catch ( \Throwable $e ) {
			// Never break the site: without the handlers nothing is caught.
			self::$origin = '';
		}
	}

	/**
	 * Whether capture runs on this site: connected, not turned off, and the
	 * Scanfully plugin active (and not paused by recovery mode) when a
	 * basename is given.
	 *
	 * @param string $basename The Scanfully plugin's basename, or '' to skip that check.
	 *
	 * @return bool
	 */
	public static function should_run( string $basename = '' ): bool {
		if ( ! function_exists( 'get_option' ) || ! self::has_target() ) {
			return false;
		}
		/**
		 * Filters whether PHP errors are caught and sent to Scanfully. The
		 * must-use plugin reads it before regular plugins load, so add the
		 * filter in a must-use plugin or in wp-config.php.
		 *
		 * @param bool $enabled Whether PHP errors are caught.
		 */
		if ( ! apply_filters( 'scanfully_php_errors_enabled', true ) ) {
			return false;
		}
		if ( '' === $basename ) {
			return true;
		}

		$active = in_array( $basename, (array) get_option( 'active_plugins', [] ), true );
		if ( ! $active && function_exists( 'is_multisite' ) && is_multisite() ) {
			$active = isset( ( (array) get_site_option( 'active_sitewide_plugins', [] ) )[ $basename ] );
		}
		if ( ! $active ) {
			return false;
		}

		// In recovery mode, a paused Scanfully plugin stays off here too.
		return ! function_exists( 'wp_paused_plugins' ) || ! wp_paused_plugins()->get( dirname( $basename ) );
	}

	/**
	 * Whether the site has somewhere to send its errors, from what is cheap
	 * to read on every request: its own connection, on the main site of a
	 * network the network's connection, or on a subsite the marker of a
	 * network that monitors it (see Connect\Children). The sender checks
	 * each target again (Connect\Targets::now()).
	 *
	 * @return bool
	 */
	private static function has_target(): bool {
		$off = self::switched_off();
		if ( ! $off && 'yes' === get_option( 'scanfully_connect_is_connected' ) ) {
			return true;
		}
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return false;
		}
		if ( is_main_site() ) {
			return ! $off && 'yes' === get_site_option( Network::CONNECTED_OPTION );
		}

		// The switch on a subsite's own settings page is about its own
		// connection: the network that monitors it keeps catching its errors.
		return null !== Children::marker_here();
	}

	/**
	 * Whether the site's own switch (Settings > Scanfully) turned PHP error
	 * monitoring off for its own connection. Read from the autoloaded
	 * options: a site that never touched the setting has no row, and
	 * get_option() would look for it on every request.
	 *
	 * @return bool
	 */
	private static function switched_off(): bool {
		$options = function_exists( 'wp_load_alloptions' ) ? wp_load_alloptions() : [];

		return 'no' === ( $options[ self::ENABLED_OPTION ] ?? '' );
	}

	/**
	 * Whether a target gets this site's PHP errors: a network's child always
	 * (while capture runs), the own connection unless its switch is off.
	 *
	 * @param Target $target The target.
	 *
	 * @return bool
	 */
	public static function sends_to( Target $target ): bool {
		return $target->is_child() || ! self::switched_off();
	}

	/**
	 * What booted the monitor: `mu`, `plugin`, or '' when it isn't running.
	 *
	 * @return string
	 */
	public static function origin(): string {
		return self::$origin;
	}

	/**
	 * The error handler: keep a warning, then pass the error on.
	 *
	 * @param int    $type    The error type.
	 * @param string $message The message.
	 * @param string $file    The file.
	 * @param int    $line    The line.
	 *
	 * @return bool What the previous handler returned; false without one, so PHP handles the error as usual.
	 */
	public static function handle( $type, $message = '', $file = '', $line = 0 ) {
		if ( ! self::$busy ) {
			self::$busy = true;
			try {
				// error_reporting() leaves out the type for an error silenced
				// with @ (and for types the site doesn't report).
				if ( 0 !== ( (int) $type & Builder::WARNING ) && 0 !== ( error_reporting() & (int) $type ) ) {
					self::record( (int) $type, (string) $message, (string) $file, (int) $line, self::backtrace( (string) $file, (int) $line ) );
				}
			} catch ( \Throwable $e ) {
				// Never break the site; the error is still passed on below.
				self::$busy = false;
			}
			self::$busy = false;
		}

		if ( null !== self::$previous ) {
			return call_user_func( self::$previous, $type, $message, $file, $line );
		}

		return false;
	}

	/**
	 * The stack frames of the warning being handled: without the handler's
	 * own frame and the internal call that raised the warning, without
	 * arguments.
	 *
	 * @param string $file The file of the warning.
	 * @param int    $line The line of the warning.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function backtrace( string $file, int $line ): array {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, Builder::MAX_FRAMES + 4 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- The stack frames of the error, without arguments.
		// The handler's own frame, and the engine calling it (no file).
		while ( [] !== $frames ) {
			$frame = $frames[0];
			if ( self::class !== ( $frame['class'] ?? '' ) && array_key_exists( 'file', $frame ) ) {
				break;
			}
			array_shift( $frames );
		}
		// A warning raised by an internal function (file_get_contents()):
		// its frame is where the warning is, and the function it was called
		// in is the next one.
		if ( [] !== $frames && isset( $frames[0]['file'], $frames[0]['line'] ) && $frames[0]['file'] === $file && (int) $frames[0]['line'] === $line ) {
			array_shift( $frames );
		}

		return $frames;
	}

	/**
	 * Keep an error for shutdown: the first occurrence of each type, file
	 * and line with its frames, and a count for the rest. Past the cap,
	 * only counted as dropped.
	 *
	 * @param int                              $type    The error type.
	 * @param string                           $message The message.
	 * @param string                           $file    The file.
	 * @param int                              $line    The line.
	 * @param array<int, array<string, mixed>> $trace   The stack frames.
	 *
	 * @return void
	 */
	public static function record( int $type, string $message, string $file, int $line, array $trace = [] ): void {
		$id  = $type . ':' . $file . ':' . $line;
		$now = time();
		if ( isset( self::$errors[ $id ] ) ) {
			++self::$errors[ $id ]['count'];
			self::$errors[ $id ]['last_seen'] = $now;
			return;
		}
		// Past the cap only warnings are dropped: the fatal error that ends
		// the request is the one that matters most.
		if ( count( self::$errors ) >= self::MAX_PER_REQUEST && 0 === ( $type & Builder::FATAL ) ) {
			++self::$dropped;
			return;
		}

		self::$errors[ $id ] = [
			'type'       => $type,
			'message'    => strlen( $message ) > self::MAX_RAW_MESSAGE ? substr( $message, 0, self::MAX_RAW_MESSAGE ) : $message,
			'file'       => $file,
			'line'       => $line,
			'trace'      => array_slice( $trace, 0, Builder::MAX_FRAMES + 1 ),
			'count'      => 1,
			'first_seen' => $now,
			'last_seen'  => $now,
		];
	}

	/**
	 * The shutdown function: keep a fatal error, then store and send.
	 *
	 * @return void
	 */
	public static function shutdown(): void {
		if ( self::$busy ) {
			return;
		}
		self::$busy = true;

		try {
			$last = error_get_last();
			if ( is_array( $last ) && 0 !== ( (int) $last['type'] & Builder::FATAL ) ) {
				self::make_room( (string) $last['message'] );
				self::record( (int) $last['type'], (string) $last['message'], (string) $last['file'], (int) $last['line'] );
			}

			if ( [] !== self::$errors || self::$dropped > 0 ) {
				self::flush();
			}
		} catch ( \Throwable $e ) {
			// Never break the site: what this request saw is dropped.
			self::$errors = [];
		}

		self::$errors  = [];
		self::$dropped = 0;
		self::$busy    = false;
	}

	/**
	 * After a fatal error for running out of memory, raise the limit a
	 * little, so the shutdown work can still run.
	 *
	 * @param string $message The fatal error's message.
	 *
	 * @return void
	 */
	private static function make_room( string $message ): void {
		if ( false === strpos( $message, 'Allowed memory size' ) ) {
			return;
		}
		$limit = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : 0;
		if ( $limit > 0 ) {
			@ini_set( 'memory_limit', (string) ( $limit + 16 * 1024 * 1024 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed -- A host may refuse it; then the work runs without.
		}
	}

	/**
	 * Group what this request saw by fingerprint, send a new fatal error
	 * straight away, add the rest to the store with one query and plan a
	 * send for errors the API doesn't know yet.
	 *
	 * @return void
	 */
	public static function flush(): void {
		$built         = self::build( Attribution::from_wordpress(), Context::current() );
		$overflow      = self::$dropped;
		self::$errors  = [];
		self::$dropped = 0;
		if ( ! Store::database_ready() ) {
			// No database (yet): nothing can be kept.
			return;
		}

		// The plugin may not have booted (a fatal error while plugins load).
		OptionsController::ensure_scope();
		$now     = time();
		$hour    = Store::hour( $now );
		$errors  = $built;
		$known   = Store::known_by_all( array_filter( Targets::here(), [ self::class, 'sends_to' ] ) );
		$unknown = array_diff_key( $errors, array_filter( $known, static fn( int $sent ): bool => $sent > $now - Store::KNOWN_FOR ) );

		// A new distinct error counts against the hour's cap; one already
		// waiting to be sent doesn't.
		$new = array_diff( array_keys( $unknown ), Store::pending( array_keys( $unknown ) ) );
		if ( [] !== $new ) {
			$room = max( 0, Store::MAX_NEW_PER_HOUR - Store::hour_count( 'h', $hour ) );
			// Fatal errors first.
			usort(
				$new,
				static fn( string $a, string $b ): int => ( 'fatal' === $errors[ $b ]['level'] ) <=> ( 'fatal' === $errors[ $a ]['level'] )
			);
			foreach ( array_slice( $new, $room ) as $key ) {
				unset( $errors[ $key ], $unknown[ $key ] );
				++$overflow;
			}
			$new = array_slice( $new, 0, $room );
		}

		foreach ( $errors as $key => $error ) {
			if ( 'fatal' === $error['level'] && self::send_directly( (string) $key, $error, $now ) ) {
				unset( $errors[ $key ], $unknown[ $key ] );
			}
		}

		Store::add( $errors, $hour, count( $new ), $overflow );

		if ( [] !== $unknown ) {
			Reporter::schedule( Reporter::TRIGGER_NEW );
		}
	}

	/**
	 * This request's errors as they are stored, grouped by key, with their
	 * count and times. Errors that differ only in the line (the same bug
	 * twice in a function) share a fingerprint and are added up.
	 *
	 * @param Attribution                                                                      $attribution The attribution.
	 * @param array{request: string, path: string, php: string, wp: string, maintenance: bool} $context     The request context.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function build( Attribution $attribution, array $context ): array {
		$built = [];
		foreach ( self::$errors as $raw ) {
			$error = Builder::build( $raw, $attribution, $context );
			if ( null === $error ) {
				continue;
			}
			$key = Store::key( $error );
			if ( isset( $built[ $key ] ) ) {
				$built[ $key ]['count']     += (int) $raw['count'];
				$built[ $key ]['first_seen'] = min( $built[ $key ]['first_seen'], (int) $raw['first_seen'] );
				$built[ $key ]['last_seen']  = max( $built[ $key ]['last_seen'], (int) $raw['last_seen'] );
				continue;
			}
			$built[ $key ] = $error + [
				'count'      => (int) $raw['count'],
				'first_seen' => (int) $raw['first_seen'],
				'last_seen'  => (int) $raw['last_seen'],
			];
		}

		return $built;
	}

	/**
	 * Send a fatal error straight from the shutdown function, after the
	 * response is finished, with a short timeout: a site that fails on every
	 * request, cron included, can't send it any other way. At most once per
	 * fingerprint per DIRECT_PERIOD. Sent to every target of the site; when
	 * only some took it, it is stored as usual, and those that took it don't
	 * get this occurrence again (Store's sent markers).
	 *
	 * @param string               $key   The error's store key.
	 * @param array<string, mixed> $error The built error, with its count and times.
	 * @param int                  $now   The time.
	 *
	 * @return bool Whether every target took it.
	 */
	private static function send_directly( string $key, array $error, int $now ): bool {
		if ( ! Store::lock( (string) $error['fingerprint'], self::DIRECT_PERIOD, $now ) ) {
			return false;
		}
		$targets = array_values( array_filter( Targets::now(), [ self::class, 'sends_to' ] ) );
		if ( [] === $targets ) {
			return false;
		}

		// The visitor gets the response first.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}

		$payload = Reporter::payload( [ Reporter::entry( $error, (int) $error['count'], (int) $error['first_seen'], (int) $error['last_seen'] ) ], 0 );
		$took    = [];
		foreach ( $targets as $target ) {
			$status = ( new ErrorsRequest() )->to( $target )->send( $payload, self::DIRECT_TIMEOUT );
			if ( null !== $status && $status >= 200 && $status < 300 ) {
				$took[] = $target;
			}
		}
		if ( count( $took ) === count( $targets ) ) {
			return true;
		}
		foreach ( $took as $target ) {
			$sent         = Store::sent( $target );
			$sent[ $key ] = (int) ( $sent[ $key ] ?? 0 ) + (int) $error['count'];
			Store::save_sent( $target, $sent );
		}

		return false;
	}

	/**
	 * Forget this request's errors and the boot state (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		if ( '' !== self::$origin ) {
			restore_error_handler();
		}
		self::$origin    = '';
		self::$previous  = null;
		self::$all_types = false;
		self::$busy      = false;
		self::$errors    = [];
		self::$dropped   = 0;
	}

	/**
	 * The errors this request kept so far (tests).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function recorded(): array {
		return self::$errors;
	}

	/**
	 * Whether the handler takes every error type (tests).
	 *
	 * @return bool
	 */
	public static function takes_all_types(): bool {
		return self::$all_types;
	}
}
