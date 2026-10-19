<?php
/**
 * The failed login reporter class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\FailedLogins;

use Scanfully\API\FailedLoginsRequest;
use Scanfully\Events\UserEvent;
use Scanfully\Options;

/**
 * Class Reporter
 *
 * The hourly job: sends the failed login counts of the hours that are over,
 * in one request, and removes them once the API took them. Counts the API
 * can't take now (no connection, a server error) stay for the next run, for
 * at most a week.
 *
 * @package Scanfully\Events\FailedLogins
 */
final class Reporter {

	/**
	 * Hours kept when they can't be sent.
	 */
	private const KEEP_HOURS = 7 * 24;

	/**
	 * The most accounts sent per hour, the most targeted first.
	 */
	public const MAX_ACCOUNTS = 50;

	/**
	 * The reasons a login fails, as the API knows them.
	 */
	private const REASONS = [ 'wrong_password', 'unknown_account', 'blocked' ];

	/**
	 * The ways a login comes in, as the API knows them.
	 */
	private const CHANNELS = [ 'login_page', 'xmlrpc', 'app_password', 'other' ];

	/**
	 * Send the counts of the hours that are over.
	 *
	 * @return void
	 */
	public static function send(): void {
		if ( ! Options\Controller::get_options()->is_connected ) {
			return;
		}

		$current = Store::hour();
		$hours   = Store::hours_before( $current );
		foreach ( array_keys( $hours ) as $hour ) {
			if ( $hour < $current - self::KEEP_HOURS ) {
				Store::delete_hour( $hour );
				unset( $hours[ $hour ] );
			}
		}
		if ( [] === $hours ) {
			return;
		}

		$payload = [];
		foreach ( $hours as $hour => $counters ) {
			$entry = self::hour_entry( $hour, $counters );
			if ( null !== $entry ) {
				$payload[] = $entry;
			}
		}

		$status = [] === $payload ? 204 : ( new FailedLoginsRequest() )->send( [ 'hours' => $payload ] );

		// Sent, or refused as data the API will never take: either way done.
		// Anything else (no answer, 401 while a token refreshes, 429, a server
		// error, an API that doesn't know the endpoint yet) is tried again in
		// an hour.
		$done = null !== $status && ( ( $status >= 200 && $status < 300 ) || 400 === $status || 413 === $status || 422 === $status );
		if ( $done ) {
			foreach ( array_keys( $hours ) as $hour ) {
				Store::delete_hour( $hour );
			}
		}
	}

	/**
	 * An hour's counts as the API takes them; null without a total.
	 *
	 * @param int                $hour     The hour.
	 * @param array<string, int> $counters The hour's counters.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function hour_entry( int $hour, array $counters ): ?array {
		$total = (int) ( $counters['total'] ?? 0 );
		if ( $total < 1 ) {
			return null;
		}

		$reasons = [];
		foreach ( self::REASONS as $reason ) {
			$reasons[ $reason ] = (int) ( $counters[ 'r_' . $reason ] ?? 0 );
		}
		$channels = [];
		foreach ( self::CHANNELS as $channel ) {
			$channels[ $channel ] = (int) ( $counters[ 'c_' . $channel ] ?? 0 );
		}

		$targeted = [];
		foreach ( $counters as $counter => $count ) {
			if ( 1 === preg_match( '/^u_(\d+)$/', $counter, $match ) && $count > 0 ) {
				$targeted[ (int) $match[1] ] = (int) $count;
			}
		}
		arsort( $targeted );

		$accounts = [];
		foreach ( array_slice( $targeted, 0, self::MAX_ACCOUNTS, true ) as $user_id => $count ) {
			$account = UserEvent::account_summary( $user_id );
			if ( null !== $account ) {
				$accounts[] = array_merge( $account, [ 'count' => $count ] );
			}
		}

		return [
			'hour'     => gmdate( 'Y-m-d\TH:00:00\Z', $hour * HOUR_IN_SECONDS ),
			'total'    => $total,
			'reasons'  => $reasons,
			'channels' => $channels,
			'accounts' => $accounts,
		];
	}
}
