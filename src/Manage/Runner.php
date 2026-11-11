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
 * name the API queues, the callable that runs it, whether it changes files
 * (then it is offered only where WordPress may write them) and whether it
 * may run on a multisite network (the four updates run network-wide, as
 * WordPress's upgraders do; anything else is refused there). Every job sends
 * a first heartbeat before its action runs, and runs only when the API
 * answers that it is running, so the API sees every started job running: a
 * job whose PHP dies mid-run then ends stuck at the API and is never run
 * again, and a job that is still leased when its lease passes is one the
 * plugin never started. A long action also sends heartbeats inside its long
 * steps, so the lease never passes while it works.
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

	public const ACTION_UPDATE_PLUGIN       = 'update_plugin';
	public const ACTION_UPDATE_THEME        = 'update_theme';
	public const ACTION_UPDATE_CORE         = 'update_core';
	public const ACTION_UPDATE_TRANSLATIONS = 'update_translations';

	public const ACTION_INSTALL_PLUGIN        = 'install_plugin';
	public const ACTION_INSTALL_THEME         = 'install_theme';
	public const ACTION_INSTALL_PLUGIN_UPLOAD = 'install_plugin_upload';
	public const ACTION_INSTALL_THEME_UPLOAD  = 'install_theme_upload';
	public const ACTION_ACTIVATE_PLUGIN       = 'activate_plugin';
	public const ACTION_DEACTIVATE_PLUGIN     = 'deactivate_plugin';
	public const ACTION_SWITCH_THEME          = 'switch_theme';
	public const ACTION_DELETE_PLUGIN         = 'delete_plugin';
	public const ACTION_DELETE_THEME          = 'delete_theme';

	/**
	 * Seconds between two heartbeats of a long action.
	 */
	public const HEARTBEAT_EVERY = 30;

	/**
	 * The longest error message sent.
	 */
	private const MAX_MESSAGE_LENGTH = 1000;

	/**
	 * The most bytes of JSON a result may take: the API takes 64 KB, this
	 * leaves room for the rest of the body.
	 */
	public const MAX_RESULT_BYTES = 56 * 1024;

	/**
	 * Log lines kept when a result is too large.
	 */
	private const LOG_LINES_KEPT = 5;

	/**
	 * Result fields never dropped to fit the size.
	 */
	private const KEPT_FIELDS = [ 'previous_version', 'new_version', 'activity_event_ids', 'duration_ms', 'slug', 'stylesheet', 'source', 'active', 'previous_theme' ];

	/**
	 * The actions this plugin version runs, by name: the callable takes the
	 * job's parameters, a heartbeat callable and the JobRun, and returns the
	 * result or throws JobFailed.
	 *
	 * @return array<string, array{run: callable, long: bool, files?: bool, network?: bool}>
	 */
	private static function registry(): array {
		$update = static function ( callable $run ): array {
			return [
				'run'     => $run,
				'long'    => true,
				'files'   => true,
				'network' => true,
			];
		};

		// The M2 actions are refused on a multisite network: WordPress's
		// installs, activations, theme switches and deletes there are per
		// site or network-wide, which a job cannot choose yet.
		$change = static function ( callable $run, bool $files ): array {
			return [
				'run'     => $run,
				'long'    => $files,
				'files'   => $files,
				'network' => false,
			];
		};

		return [
			self::ACTION_PING                  => [
				'run'     => [ self::class, 'ping' ],
				'long'    => false,
				'network' => true,
			],
			self::ACTION_UPDATE_PLUGIN         => $update( [ Updates::class, 'plugin' ] ),
			self::ACTION_UPDATE_THEME          => $update( [ Updates::class, 'theme' ] ),
			self::ACTION_UPDATE_CORE           => $update( [ Updates::class, 'core' ] ),
			self::ACTION_UPDATE_TRANSLATIONS   => $update( [ Updates::class, 'translations' ] ),
			self::ACTION_INSTALL_PLUGIN        => $change( [ Installs::class, 'plugin' ], true ),
			self::ACTION_INSTALL_THEME         => $change( [ Installs::class, 'theme' ], true ),
			self::ACTION_INSTALL_PLUGIN_UPLOAD => $change( [ Installs::class, 'plugin_upload' ], true ),
			self::ACTION_INSTALL_THEME_UPLOAD  => $change( [ Installs::class, 'theme_upload' ], true ),
			// Switching on and off and a theme switch write no files: they
			// run wherever Manage does.
			self::ACTION_ACTIVATE_PLUGIN       => $change( [ Toggles::class, 'activate_plugin' ], false ),
			self::ACTION_DEACTIVATE_PLUGIN     => $change( [ Toggles::class, 'deactivate_plugin' ], false ),
			self::ACTION_SWITCH_THEME          => $change( [ Toggles::class, 'switch_theme' ], false ),
			self::ACTION_DELETE_PLUGIN         => $change( [ Deletes::class, 'plugin' ], true ),
			self::ACTION_DELETE_THEME          => $change( [ Deletes::class, 'theme' ], true ),
		];
	}

	/**
	 * The names of the actions this plugin version runs on this site, for
	 * the capabilities report: the ones that change files only when
	 * WordPress may write them without asking for credentials. On a multisite
	 * network none: a connection there is for monitoring only
	 * (Connect\Multisite). The entries marked network are the ones a
	 * connection at network level will run.
	 *
	 * @param bool $can_change_files Whether WordPress may change files and has a usable way to write them.
	 *
	 * @return string[]
	 */
	public static function actions( bool $can_change_files ): array {
		if ( \Scanfully\Connect\Multisite::monitoring_only() ) {
			return [];
		}
		$names   = [];
		$network = function_exists( 'is_multisite' ) && is_multisite();
		foreach ( self::registry() as $name => $entry ) {
			if ( $network && empty( $entry['network'] ) ) {
				continue;
			}
			if ( $can_change_files || empty( $entry['files'] ) ) {
				$names[] = $name;
			}
		}

		return $names;
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
	 * @param JobRun|null   $run       The run's guard and context; a bare one when null.
	 *
	 * @return array{state: string, started_at: string, finished_at: string, result: object, error: array{class: string, message: string}}|null The result, or null when the first heartbeat was not answered with running and nothing ran.
	 */
	public static function run( array $job, callable $send_beat, ?callable $now = null, ?array $registry = null, ?JobRun $run = null ): ?array {
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
		$run = $run ?? new JobRun( isset( $job['id'] ) && is_string( $job['id'] ) ? $job['id'] : '' );
		$run->set_heartbeat( $beat );
		if ( null === $run->package() ) {
			$run->set_package( Controller::package_of( $job['package'] ?? null ) );
		}

		$registry = $registry ?? self::registry();
		if ( ! isset( $registry[ $action ] ) ) {
			return self::failed( $started, (int) $now(), 'not_supported', sprintf( 'This version of the Scanfully plugin does not run the action "%s".', $action ) );
		}
		$entry = $registry[ $action ];
		if ( \Scanfully\Connect\Multisite::monitoring_only() ) {
			return self::failed( $started, (int) $now(), 'multisite_refused', sprintf( 'This site is part of a multisite network and connected for monitoring only, so Scanfully does not run the action "%s" here.', $action ) );
		}

		$failure = null;
		$result  = [];
		try {
			$result = call_user_func( $entry['run'], $params, [ $beat, 'beat' ], $run );
		} catch ( JobFailed $e ) {
			$failure = [ $e->error_class(), $e->getMessage(), $e->result() ];
		} catch ( \Throwable $e ) {
			$failure = [ 'fatal', $e->getMessage(), [] ];
		}
		$result = is_array( $result ) ? $result : [];

		// Leave the site clean whatever happened: the maintenance file, a
		// plugin the upgrader switched off.
		$problem = $run->clean();
		if ( null !== $problem ) {
			if ( null === $failure ) {
				$failure = [ $problem['class'], $problem['message'], $result ];
			} else {
				$failure[1] .= ' ' . $problem['message'];
				// The maintenance page outweighs what failed before it.
				$failure[0] = 'maintenance' === $problem['class'] ? 'maintenance' : $failure[0];
			}
		}
		if ( null !== $failure ) {
			return self::failed( $started, (int) $now(), $failure[0], Failure::clean( $failure[1] ), self::fit( self::with_run_log( $failure[2], $run ) ) );
		}

		return [
			'state'       => 'succeeded',
			'started_at'  => gmdate( 'c', $started ),
			'finished_at' => gmdate( 'c', (int) $now() ),
			'result'      => (object) self::fit( self::with_run_log( $result, $run ) ),
			'error'       => [
				'class'   => '',
				'message' => '',
			],
		];
	}

	/**
	 * A result made to fit the size the API takes, dropping in a fixed
	 * order until it does: the log down to its last lines, then the
	 * inventory (the API reads the next sync instead), then anything else
	 * but the versions, the duration and the activity event ids.
	 *
	 * @param array $result The result.
	 *
	 * @return array
	 */
	public static function fit( array $result ): array {
		if ( self::size( $result ) <= self::MAX_RESULT_BYTES ) {
			return $result;
		}
		if ( isset( $result['log'] ) && is_array( $result['log'] ) ) {
			$result['log'] = array_slice( $result['log'], -self::LOG_LINES_KEPT );
		}
		if ( self::size( $result ) > self::MAX_RESULT_BYTES && array_key_exists( 'inventory', $result ) ) {
			$result['inventory']         = null;
			$result['inventory_dropped'] = 'size';
		}
		if ( self::size( $result ) > self::MAX_RESULT_BYTES ) {
			$result = array_intersect_key( $result, array_flip( self::KEPT_FIELDS ) ) + [
				'inventory'         => null,
				'inventory_dropped' => 'size',
				'result_dropped'    => 'size',
			];
		}

		return $result;
	}

	/**
	 * The bytes of a value as JSON.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int
	 */
	private static function size( $value ): int {
		return strlen( (string) wp_json_encode( $value ) );
	}

	/**
	 * The smallest result of a job, for an API that refused the full one:
	 * the versions and activity event ids only.
	 *
	 * @param array $result The result as kept.
	 *
	 * @return array
	 */
	public static function minimal( array $result ): array {
		$fields                     = isset( $result['result'] ) && is_array( $result['result'] ) ? $result['result'] : [];
		$result['result']           = array_intersect_key( $fields, array_flip( self::KEPT_FIELDS ) );
		$result['result']['result_dropped'] = 'refused';

		return $result;
	}

	/**
	 * A result with the run's own log lines added to its log, when it has
	 * either.
	 *
	 * @param array  $result The result.
	 * @param JobRun $run    The run.
	 *
	 * @return array
	 */
	private static function with_run_log( array $result, JobRun $run ): array {
		$extra = Failure::log( $run->log_lines() );
		if ( [] === $extra ) {
			return $result;
		}
		$log           = isset( $result['log'] ) && is_array( $result['log'] ) ? $result['log'] : [];
		$result['log'] = array_merge( $log, $extra );

		return $result;
	}

	/**
	 * The result of a job that failed.
	 *
	 * @param int    $started  When it started.
	 * @param int    $finished When it ended.
	 * @param string $class    The error class, one of the API's list.
	 * @param string $message  What went wrong.
	 * @param array  $result   What the action still knows (log, versions, inventory).
	 *
	 * @return array{state: string, started_at: string, finished_at: string, result: object, error: array{class: string, message: string}}
	 */
	public static function failed( int $started, int $finished, string $class, string $message, array $result = [] ): array {
		return [
			'state'       => 'failed',
			'started_at'  => gmdate( 'c', $started ),
			'finished_at' => gmdate( 'c', $finished ),
			'result'      => (object) $result,
			'error'       => [
				'class'   => $class,
				'message' => mb_substr( trim( $message ), 0, self::MAX_MESSAGE_LENGTH ),
			],
		];
	}

	/**
	 * The ping action: answers at once with the plugin's version.
	 *
	 * @param array       $params The job's parameters; ping takes none.
	 * @param callable    $beat   Sends a heartbeat when one is due.
	 * @param JobRun|null $run    The run.
	 *
	 * @return array{plugin_version: string}
	 */
	public static function ping( array $params, callable $beat, ?JobRun $run = null ): array {
		unset( $params, $beat, $run );

		return [ 'plugin_version' => defined( 'SCANFULLY_VERSION' ) ? (string) SCANFULLY_VERSION : '' ];
	}
}
