<?php
/**
 * The PHP error reporter class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Errors;

use Scanfully\API\ErrorsRequest;
use Scanfully\Connect\Target;
use Scanfully\Connect\Targets;

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
	 * Send what is waiting, to every target of the site (its own connection,
	 * and a monitored subsite's child site). A site with two targets keeps
	 * what one of them got while the other did not take it yet (Store's sent
	 * markers), so neither gets a count twice; the store gives up a count
	 * once both have it.
	 *
	 * @return void
	 */
	public static function send(): void {
		$targets = Targets::now();
		if ( [] === $targets ) {
			return;
		}
		if ( ! Monitor::should_run() ) {
			Store::delete_all();
			return;
		}

		$now     = time();
		$stored  = Store::read();
		$known   = Store::known_by_all( $targets );
		$entries = [];
		$counts  = [];
		$order   = [];
		foreach ( $stored['errors'] as $key => $error ) {
			// Nothing left to send, or kept too long.
			if ( $error['count'] < 1 || $error['last_seen'] < $now - self::KEEP ) {
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
		$batch  = array_slice( $entries, 0, self::MAX_PER_REQUEST, true );
		$counts = array_intersect_key( $counts, $batch );

		$all_done = true;
		$got      = [];
		foreach ( $targets as $target ) {
			$sent    = Store::sent( $target );
			$existed = [] !== $sent;
			if ( ! self::send_to( $target, $batch, $counts, $overflow, $sent, $now ) ) {
				$all_done = false;
			}
			$got[] = [ $target, $sent, $existed ];
		}
		self::forget_former_targets( $targets );

		// What every target has goes from the store.
		$take = [];
		foreach ( $counts as $key => $count ) {
			$min = min( array_map( static fn( array $one ): int => (int) ( $one[1][ $key ] ?? 0 ), $got ) );
			if ( $min > 0 ) {
				$take[ $key ] = $min;
			}
		}
		$take_hours = [];
		foreach ( $overflow as $hour => $count ) {
			$min = min( array_map( static fn( array $one ): int => (int) ( $one[1][ 'o:' . $hour ] ?? 0 ), $got ) );
			if ( $min > 0 ) {
				$take_hours[ $hour ] = $min;
			}
		}
		if ( [] !== $take ) {
			Store::take( $take );
		}
		// Hours that are over lose their counters here, so only once every
		// target has taken what it could.
		if ( $all_done ) {
			Store::take_hours( $take_hours, $current );
			Store::delete_old_locks( Monitor::DIRECT_PERIOD, $now );
		}
		foreach ( $got as [ $target, $sent, $existed ] ) {
			foreach ( $take as $key => $count ) {
				$sent[ $key ] = (int) ( $sent[ $key ] ?? 0 ) - $count;
			}
			foreach ( $take_hours as $hour => $count ) {
				$sent[ 'o:' . $hour ] = (int) ( $sent[ 'o:' . $hour ] ?? 0 ) - $count;
			}
			Store::save_sent( $target, $sent, $existed );
		}

		if ( $all_done && count( $entries ) > count( $batch ) ) {
			self::schedule( self::TRIGGER_NEW );
		}
	}

	/**
	 * Send a target what it does not have yet of a batch and the overflow.
	 * What it took is added to its sent markers.
	 *
	 * @param Target                              $target   The target.
	 * @param array<string, array<string, mixed>> $batch    The entries by key.
	 * @param array<string, int>                  $counts   The stored counts by key.
	 * @param array<int, int>                     $overflow The stored overflow by hour.
	 * @param array<string, int>                  $sent     The target's sent markers, updated.
	 * @param int                                 $now      The time.
	 *
	 * @return bool Whether the target has everything now.
	 */
	private static function send_to( Target $target, array $batch, array $counts, array $overflow, array &$sent, int $now ): bool {
		$errors = [];
		foreach ( $batch as $key => $entry ) {
			$left = $counts[ $key ] - (int) ( $sent[ $key ] ?? 0 );
			if ( $left > 0 ) {
				$entry['count'] = $left;
				$errors[ $key ] = $entry;
			}
		}
		$over = 0;
		foreach ( $overflow as $hour => $count ) {
			$over += max( 0, $count - (int) ( $sent[ 'o:' . $hour ] ?? 0 ) );
		}
		if ( [] === $errors && 0 === $over ) {
			return true;
		}

		$status = ( new ErrorsRequest() )->to( $target )->send( self::payload( array_values( $errors ), $over ) );

		// Sent, or refused as data the API will never take: either way done.
		// Anything else (no answer, 401 while a token refreshes, 429, a server
		// error, an API that doesn't know the endpoint yet) is tried again on
		// the next run.
		$done = null !== $status && ( ( $status >= 200 && $status < 300 ) || 400 === $status || 413 === $status || 422 === $status );
		if ( ! $done ) {
			return false;
		}
		foreach ( array_keys( $errors ) as $key ) {
			$sent[ $key ] = $counts[ $key ];
		}
		foreach ( $overflow as $hour => $count ) {
			$sent[ 'o:' . $hour ] = $count;
		}
		Store::mark_known( array_keys( $errors ), $now, $target );

		return true;
	}

	/**
	 * Forget the sent markers of a kind of target the site no longer has
	 * (a subsite no longer monitored, or no longer connected on its own).
	 *
	 * @param Target[] $targets The targets now.
	 *
	 * @return void
	 */
	private static function forget_former_targets( array $targets ): void {
		$kinds = array_map( static fn( Target $target ): string => $target->kind(), $targets );
		foreach ( [ Target::own( '' ), Target::child( '', 0 ) ] as $former ) {
			if ( ! in_array( $former->kind(), $kinds, true ) && false !== get_option( $former->option( Store::SENT_OPTION ) ) ) {
				Store::forget_target( $former );
			}
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
