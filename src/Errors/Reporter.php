<?php
/**
 * The PHP error reporter class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Errors;

use Scanfully\API\ErrorsRequest;
use Scanfully\Options;

/**
 * Class Reporter
 *
 * Sends the errors in the Store to the API: errors the API doesn't know yet
 * within a minute, the counts of known ones once an hour, and everything
 * right after an update. At most MAX_PER_REQUEST errors per request, fatal
 * errors and errors the API doesn't know first; the rest go in the next
 * request a minute later. Counts the API can't take now (no connection, a
 * server error) stay for the next run, for at most a week.
 *
 * @package Scanfully\Errors
 */
final class Reporter {

	/**
	 * Action Scheduler hook of the sends, in the `scanfully` group.
	 */
	public const ACTION = 'scanfully_send_php_errors';

	/**
	 * Why a single send is planned: a new error, or an update finished.
	 */
	public const TRIGGER_NEW    = 'new';
	public const TRIGGER_UPDATE = 'update';

	/**
	 * The most errors per request.
	 */
	public const MAX_PER_REQUEST = 50;

	/**
	 * How long an error that can't be sent is kept.
	 */
	private const KEEP = WEEK_IN_SECONDS;

	/**
	 * Plan a send: in a minute for a new error, at once after an update.
	 * Only one of each is waiting at a time. Needs Action Scheduler, which
	 * isn't there when a fatal error stopped the request before plugins
	 * loaded; the next request or the hourly send picks the error up.
	 *
	 * @param string $trigger TRIGGER_NEW or TRIGGER_UPDATE.
	 *
	 * @return void
	 */
	public static function schedule( string $trigger ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! class_exists( \ActionScheduler::class, false ) ) {
			return;
		}
		// Another plugin can bring an older Action Scheduler.
		if ( is_callable( [ \ActionScheduler::class, 'is_initialized' ] ) && ! \ActionScheduler::is_initialized() ) {
			return;
		}

		$args = [ 'trigger' => $trigger ];
		if ( as_has_scheduled_action( self::ACTION, $args, 'scanfully' ) ) {
			return;
		}
		as_schedule_single_action( time() + ( self::TRIGGER_UPDATE === $trigger ? 0 : MINUTE_IN_SECONDS ), self::ACTION, $args, 'scanfully' );
	}

	/**
	 * Send what is waiting.
	 *
	 * @return void
	 */
	public static function send(): void {
		if ( ! Options\Controller::get_options()->is_connected ) {
			return;
		}
		if ( ! Monitor::should_run() ) {
			Store::delete_all();
			return;
		}

		$now     = time();
		$stored  = Store::read();
		$known   = Store::known();
		$entries = [];
		$counts  = [];
		$order   = [];
		foreach ( $stored['errors'] as $key => $error ) {
			// Nothing left to send, or kept too long.
			if ( $error['count'] < 1 || ( $error['last_seen'] > 0 && $error['last_seen'] < $now - self::KEEP ) ) {
				Store::delete( $key );
				continue;
			}
			// Counted after its sample went with the last send: the next
			// occurrence brings a sample.
			if ( null === $error['sample'] ) {
				continue;
			}
			$sample          = $error['sample'];
			$first_seen      = (int) ( $sample['first_seen'] ?? $now );
			$entries[ $key ] = self::entry( $sample, $error['count'], $first_seen, max( $first_seen, $error['last_seen'] ) );
			$counts[ $key ]  = $error['count'];
			$order[ $key ]   = [ 'fatal' === ( $sample['level'] ?? '' ) ? 0 : 1, isset( $known[ $key ] ) ? 1 : 0, -$error['count'] ];
		}

		$current  = Store::hour( $now );
		$overflow = array_filter( $stored['overflow'], static fn( int $count ): bool => $count > 0 );
		if ( [] === $entries && [] === $overflow ) {
			Store::take_hours( [], $current );
			Store::delete_old_locks( Monitor::DIRECT_PERIOD, $now );
			return;
		}

		uksort(
			$entries,
			static fn( string $a, string $b ): int => $order[ $a ] <=> $order[ $b ]
		);
		$batch = array_slice( $entries, 0, self::MAX_PER_REQUEST, true );

		$status = ( new ErrorsRequest() )->send( self::payload( array_values( $batch ), array_sum( $overflow ) ) );

		// Sent, or refused as data the API will never take: either way done.
		// Anything else (no answer, 401 while a token refreshes, 429, a server
		// error, an API that doesn't know the endpoint yet) is tried again on
		// the next run.
		$done = null !== $status && ( ( $status >= 200 && $status < 300 ) || 400 === $status || 413 === $status || 422 === $status );
		if ( ! $done ) {
			return;
		}

		Store::take( array_intersect_key( $counts, $batch ) );
		Store::take_hours( $overflow, $current );
		Store::delete_old_locks( Monitor::DIRECT_PERIOD, $now );
		Store::mark_known( array_keys( $batch ), $now );

		if ( count( $entries ) > count( $batch ) ) {
			self::schedule( self::TRIGGER_NEW );
		}
	}

	/**
	 * An error as the API takes it.
	 *
	 * @param array<string, mixed> $error      The built error or stored sample.
	 * @param int                  $count      How often it happened.
	 * @param int                  $first_seen When it first happened (Unix time).
	 * @param int                  $last_seen  When it last happened (Unix time).
	 *
	 * @return array<string, mixed>
	 */
	public static function entry( array $error, int $count, int $first_seen, int $last_seen ): array {
		$source  = is_array( $error['source'] ?? null ) ? $error['source'] : [];
		$context = is_array( $error['context'] ?? null ) ? $error['context'] : [];
		$trace   = [];
		foreach ( is_array( $error['trace'] ?? null ) ? $error['trace'] : [] as $frame ) {
			$trace[] = [
				'file'     => (string) ( $frame['file'] ?? '' ),
				'line'     => (int) ( $frame['line'] ?? 0 ),
				'function' => (string) ( $frame['function'] ?? '' ),
			];
		}

		return [
			'fingerprint' => (string) ( $error['fingerprint'] ?? '' ),
			'level'       => (string) ( $error['level'] ?? '' ),
			'source'      => [
				'kind'    => (string) ( $source['kind'] ?? Attribution::UNKNOWN ),
				'slug'    => (string) ( $source['slug'] ?? '' ),
				'name'    => (string) ( $source['name'] ?? '' ),
				'version' => (string) ( $source['version'] ?? '' ),
			],
			'message'     => (string) ( $error['message'] ?? '' ),
			'file'        => (string) ( $error['file'] ?? '' ),
			'line'        => (int) ( $error['line'] ?? 0 ),
			'function'    => (string) ( $error['function'] ?? '' ),
			'trace'       => $trace,
			'context'     => [
				'request'     => (string) ( $context['request'] ?? Context::FRONT ),
				'path'        => (string) ( $context['path'] ?? '' ),
				'php'         => (string) ( $context['php'] ?? '' ),
				'wp'          => (string) ( $context['wp'] ?? '' ),
				'maintenance' => (bool) ( $context['maintenance'] ?? false ),
			],
			'count'       => max( 1, $count ),
			'first_seen'  => gmdate( 'Y-m-d\TH:i:s\Z', $first_seen ),
			'last_seen'   => gmdate( 'Y-m-d\TH:i:s\Z', $last_seen ),
		];
	}

	/**
	 * The body of a request.
	 *
	 * @param array<int, array<string, mixed>> $errors   The errors.
	 * @param int                              $overflow Distinct errors dropped.
	 *
	 * @return array{errors: array<int, array<string, mixed>>, overflow: int}
	 */
	public static function payload( array $errors, int $overflow ): array {
		return [
			'errors'   => $errors,
			'overflow' => max( 0, $overflow ),
		];
	}
}
