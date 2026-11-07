<?php
/**
 * The Manage runner class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

/**
 * Runs one Scanfully Manage job by its action and says how it went, in the
 * shape the API takes as the job's result. The actions are a registry: the
 * name the API queues, the callable that runs it, and whether it is long.
 * Every job sends a first heartbeat before its action runs, and runs only
 * when the API answers that it is running, so the API sees every started job
 * running: a job whose PHP dies mid-run then ends stuck at the API and is
 * never run again, and a job that is still leased when its lease passes is
 * one the plugin never started. A long action also sends one every 30
 * seconds while it runs, so the API knows it still works on it; none is long
 * yet.
 *
 * The runner runs in an Action Scheduler action of its own, never in the
 * request that pulled the job.
 */
class Runner {

	/**
	 * The action that only answers: it proves the path from the API's queue
	 * to the plugin and back.
	 */
	public const ACTION_PING = 'ping';

	/**
	 * Seconds between two heartbeats of a long action.
	 */
	public const HEARTBEAT_EVERY = 30;

	/**
	 * The longest error message sent.
	 */
	private const MAX_MESSAGE_LENGTH = 1000;

	/**
	 * The actions this plugin version runs, by name: the callable takes the
	 * job's parameters and a heartbeat callable and returns the result.
	 *
	 * @return array<string, array{run: callable, long: bool}>
	 */
	private static function registry(): array {
		return [
			self::ACTION_PING => [
				'run'  => [ self::class, 'ping' ],
				'long' => false,
			],
		];
	}

	/**
	 * The names of the actions this plugin version runs, for the
	 * capabilities report.
	 *
	 * @return string[]
	 */
	public static function actions(): array {
		return array_keys( self::registry() );
	}

	/**
	 * Whether the action is one this plugin version runs.
	 *
	 * @param string $action The action.
	 *
	 * @return bool
	 */
	public static function knows( string $action ): bool {
		return isset( self::registry()[ $action ] );
	}

	/**
	 * Run a job.
	 *
	 * @param array         $job       The job as pulled: id, action and params.
	 * @param callable      $send_beat Sends a heartbeat for the job to the API; returns true only when the API answered that the job runs.
	 * @param callable|null $now       The clock, for tests; time() when null.
	 * @param array|null    $registry  The actions, for tests; this version's when null.
	 *
	 * @return array{state: string, started_at: string, finished_at: string, result: object, error: array{class: string, message: string}}|null The result, or null when the first heartbeat was not answered with running and nothing ran.
	 */
	public static function run( array $job, callable $send_beat, ?callable $now = null, ?array $registry = null ): ?array {
		$now     = $now ?? 'time';
		$started = (int) $now();
		$action  = isset( $job['action'] ) && is_string( $job['action'] ) ? $job['action'] : '';
		$params  = isset( $job['params'] ) && is_array( $job['params'] ) ? $job['params'] : [];

		// The first heartbeat, for every action: the job runs only when the
		// API says it does.
		$beat = new Heartbeat( $send_beat, self::HEARTBEAT_EVERY, $now );
		if ( ! $beat->start() ) {
			return null;
		}

		$registry = $registry ?? self::registry();
		if ( ! isset( $registry[ $action ] ) ) {
			return self::failed( $started, (int) $now(), 'not_supported', sprintf( 'This version of the Scanfully plugin does not run the action "%s".', $action ) );
		}

		$entry = $registry[ $action ];
		try {
			$result = call_user_func( $entry['run'], $params, [ $beat, 'beat' ] );
		} catch ( JobFailed $e ) {
			return self::failed( $started, (int) $now(), $e->error_class(), $e->getMessage() );
		} catch ( \Throwable $e ) {
			return self::failed( $started, (int) $now(), 'fatal', $e->getMessage() );
		}

		return [
			'state'       => 'succeeded',
			'started_at'  => gmdate( 'c', $started ),
			'finished_at' => gmdate( 'c', (int) $now() ),
			'result'      => (object) ( is_array( $result ) ? $result : [] ),
			'error'       => [
				'class'   => '',
				'message' => '',
			],
		];
	}

	/**
	 * The result of a job that failed.
	 *
	 * @param int    $started  When it started.
	 * @param int    $finished When it ended.
	 * @param string $class    The error class, one of the API's list.
	 * @param string $message  What went wrong.
	 *
	 * @return array{state: string, started_at: string, finished_at: string, result: object, error: array{class: string, message: string}}
	 */
	public static function failed( int $started, int $finished, string $class, string $message ): array {
		return [
			'state'       => 'failed',
			'started_at'  => gmdate( 'c', $started ),
			'finished_at' => gmdate( 'c', $finished ),
			'result'      => new \stdClass(),
			'error'       => [
				'class'   => $class,
				'message' => mb_substr( trim( $message ), 0, self::MAX_MESSAGE_LENGTH ),
			],
		];
	}

	/**
	 * The ping action: answers at once with the plugin's version.
	 *
	 * @param array    $params The job's parameters; ping takes none.
	 * @param callable $beat   Sends a heartbeat when one is due.
	 *
	 * @return array{plugin_version: string}
	 */
	public static function ping( array $params, callable $beat ): array {
		unset( $params, $beat );

		return [ 'plugin_version' => defined( 'SCANFULLY_VERSION' ) ? (string) SCANFULLY_VERSION : '' ];
	}
}
