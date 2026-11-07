<?php
/**
 * The Manage controller class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

use Scanfully\API\ManageJobsRequest;
use Scanfully\Connect;
use Scanfully\Cron;
use Scanfully\Options;
use Scanfully\Sync;

/**
 * The plugin's side of Scanfully Manage's command channel. Scanfully never
 * sends a job to the site: it may poke the plugin with a signed request that
 * carries nothing (POST /wp-json/scanfully/v1/manage), and the plugin pulls
 * its jobs from the API with its own site token, on every poke and every 5
 * minutes while its connection allows management actions (wordpress:manage).
 * A host that blocks the poke still gets its jobs from that clock.
 *
 * Every job runs in an Action Scheduler action of its own, never in the
 * request that pulled it, so a short max_execution_time on shared hosting
 * cannot cut it in half. The Ledger keeps each job id to one run.
 */
class Controller {

	/**
	 * REST namespace for Scanfully routes.
	 */
	private const REST_NAMESPACE = 'scanfully/v1';

	/**
	 * REST route of the poke.
	 */
	private const REST_ROUTE = '/manage';

	/**
	 * The action a signed poke names (sub).
	 */
	public const SIGNED_ACTION = 'manage';

	/**
	 * Transient storing the time of the last accepted poke.
	 */
	private const RATE_LIMIT_TRANSIENT = 'scanfully_manage_poke_last';

	/**
	 * Minimum seconds between two accepted pokes.
	 */
	private const RATE_LIMIT_WINDOW = 30;

	/**
	 * Hook: pull the jobs, on the recurring 5-minute clock.
	 */
	public const ACTION_PULL = 'scanfully_manage_pull';

	/**
	 * Hook: pull the jobs now, after a poke. A hook of its own: a unique
	 * action is skipped while another with the same hook is pending, and the
	 * clock's always is.
	 */
	public const ACTION_PULL_NOW = 'scanfully_manage_pull_now';

	/**
	 * Hook: run one job.
	 */
	public const ACTION_RUN_JOB = 'scanfully_manage_run_job';

	/**
	 * Seconds between two pulls of the clock.
	 */
	public const CLOCK_INTERVAL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Seconds after a job's last sign of life (its start or its last
	 * heartbeat) after which a job that never ended counts as stopped: PHP
	 * died while it ran. Also how long the site's run lock lasts past each
	 * sign of life. Larger than the longest step of an update without a
	 * heartbeat: WordPress gives a package download 5 minutes, and an update
	 * beats before and after the download, the unzip, the copy and the
	 * maintenance file.
	 */
	public const STALE_RUN = 20 * MINUTE_IN_SECONDS;

	/**
	 * Option holding the grant's scopes when the API refused a pull for the
	 * scope or for beta access: the clock stops until they change.
	 */
	private const PAUSED_OPTION = 'scanfully_manage_paused';

	/**
	 * Transient set when the ledger was pruned, once a day.
	 */
	private const PRUNE_TRANSIENT = 'scanfully_manage_ledger_pruned';

	/**
	 * Action Scheduler group for all Scanfully jobs.
	 */
	private const AS_GROUP = 'scanfully';

	/**
	 * The states of a job the API still waits on: a result the API answered
	 * with one of them is kept and sent again on a later pull.
	 */
	private const OPEN_STATES = [ 'queued', 'leased', 'running' ];

	/**
	 * Register the route, the actions and the clock's upkeep.
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
		add_action( self::ACTION_PULL, [ self::class, 'pull' ] );
		add_action( self::ACTION_PULL_NOW, [ self::class, 'pull' ] );
		add_action( self::ACTION_RUN_JOB, [ self::class, 'run_job' ] );
		add_action( 'action_scheduler_init', [ self::class, 'maybe_sync_clock' ] );
		add_action( 'scanfully_grant_stored', [ self::class, 'sync_clock' ] );
		add_action( 'scanfully_options_cleared', [ self::class, 'clear' ] );
	}

	/**
	 * Register the poke route.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle_poke' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			]
		);
	}

	/**
	 * Whether the site's connection allows management actions, by the
	 * plugin's own copy of the grant.
	 *
	 * @return bool
	 */
	public static function allowed(): bool {
		return Options\Controller::get_options()->is_connected
			&& in_array( Connect\OAuth::SCOPE_MANAGE, Connect\Controller::granted_scopes(), true );
	}

	/**
	 * Whether the clock is paused: the API refused a pull for the scope or
	 * for beta access, with the grant as it still is. A change of the grant,
	 * or a poke (the API pokes only a site that may pull), ends the pause.
	 *
	 * @return bool
	 */
	public static function paused(): bool {
		$paused = get_option( self::PAUSED_OPTION, '' );

		return is_string( $paused ) && '' !== $paused && implode( ' ', Connect\Controller::granted_scopes() ) === $paused;
	}

	/**
	 * End a pause: the grant holds wordpress:manage again, changed in the
	 * Scanfully dashboard. The clock follows when the grant is stored.
	 *
	 * @return void
	 */
	public static function resume(): void {
		delete_option( self::PAUSED_OPTION );
	}

	/**
	 * Only a request Scanfully signed for this site and the action manage,
	 * with wordpress:manage in it and in the plugin's own copy of the grant,
	 * may poke. The site's access token is never accepted here.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return bool
	 */
	public static function check_permission( \WP_REST_Request $request ): bool {
		if ( ! self::allowed() ) {
			return false;
		}
		$token = Sync\Controller::get_request_token( $request );
		if ( '' === $token || ! Sync\SignedRequest::looks_signed( $token ) ) {
			return false;
		}

		return Sync\SignedRequest::verify( $token, Options\Controller::get_options()->site_id, self::SIGNED_ACTION, Connect\OAuth::SCOPE_MANAGE );
	}

	/**
	 * A poke: schedule a pull, start the queue runner so it runs now, and
	 * answer at once. The poke itself carries no job.
	 *
	 * @return \WP_REST_Response
	 */
	public static function handle_poke(): \WP_REST_Response {
		// A copy of the site, or the site at a new address: Scanfully Manage
		// is paused until the site's admin decides.
		if ( Connect\Address::moved() ) {
			self::sync_clock();
			return Connect\Address::moved_response();
		}
		$last = (int) get_transient( self::RATE_LIMIT_TRANSIENT );
		if ( $last > 0 && time() - $last < self::RATE_LIMIT_WINDOW ) {
			$response = new \WP_REST_Response( [ 'status' => 'rate_limited' ], 429 );
			$response->header( 'Retry-After', (string) max( 1, self::RATE_LIMIT_WINDOW - ( time() - $last ) ) );

			return $response;
		}
		set_transient( self::RATE_LIMIT_TRANSIENT, time(), self::RATE_LIMIT_WINDOW );

		// The API pokes only a site that may pull: a paused clock goes on.
		if ( false !== get_option( self::PAUSED_OPTION, false ) ) {
			delete_option( self::PAUSED_OPTION );
			self::sync_clock();
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::ACTION_PULL_NOW, [], self::AS_GROUP, true );
		}
		Sync\Controller::run_queue_now();

		return new \WP_REST_Response( [ 'status' => 'scheduled' ], 200 );
	}

	/**
	 * Keep the clock in step with the grant where checking it is cheap
	 * enough: wp-admin, cron runs and WP-CLI, as the other schedules.
	 *
	 * @return void
	 */
	public static function maybe_sync_clock(): void {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		self::sync_clock();
	}

	/**
	 * Schedule the 5-minute pull while the grant allows management actions
	 * and the site runs at the address it was connected from, and remove it
	 * when it does not.
	 *
	 * @return void
	 */
	public static function sync_clock(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! did_action( 'action_scheduler_init' ) ) {
			return;
		}
		$wanted    = self::allowed() && ! self::paused() && ! Connect\Address::moved();
		$scheduled = as_has_scheduled_action( self::ACTION_PULL, [], self::AS_GROUP );
		if ( $wanted && ! $scheduled ) {
			as_schedule_recurring_action( time(), self::CLOCK_INTERVAL, self::ACTION_PULL, [], self::AS_GROUP, true );
		} elseif ( ! $wanted && $scheduled ) {
			as_unschedule_all_actions( self::ACTION_PULL, [], self::AS_GROUP );
		}
	}

	/**
	 * Stop the clock on disconnect. The ledger stays: job ids are unique, so
	 * a row of an earlier connection never matches another job, and a job of
	 * this site that the API hands out again after a reconnect must still not
	 * run twice. Rows go after a week, and with the plugin's data on
	 * uninstall.
	 *
	 * @return void
	 */
	public static function clear(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_PULL, [], self::AS_GROUP );
			as_unschedule_all_actions( self::ACTION_PULL_NOW, [], self::AS_GROUP );
		}
		delete_option( self::PAUSED_OPTION );
	}

	/**
	 * Pull the site's jobs and schedule each one the plugin has not run yet
	 * in an action of its own; also schedule the jobs whose result the API did
	 * not take yet, or whose run stopped, so their result is sent: the API
	 * does not hand out a running or stuck job again. Runs in Action
	 * Scheduler.
	 *
	 * @return void
	 */
	public static function pull(): void {
		Connect\Address::adopt_if_missing();
		if ( Connect\Address::moved() ) {
			// A copy of the site pulls nothing, runs nothing and reports
			// nothing: the jobs are the original site's. It does not refresh
			// the tokens either, which would take them from the original.
			self::sync_clock();
			return;
		}
		Cron\Controller::refresh_access_token_if_needed();
		if ( ! self::allowed() || self::paused() ) {
			return;
		}
		$now = time();
		if ( false === get_transient( self::PRUNE_TRANSIENT ) ) {
			set_transient( self::PRUNE_TRANSIENT, 1, DAY_IN_SECONDS );
			Ledger::prune( $now );
		}

		$scheduled = false;
		foreach ( Ledger::open( $now - self::STALE_RUN ) as $id ) {
			$scheduled = self::schedule_run( $id ) || $scheduled;
		}

		$answer = ( new ManageJobsRequest() )->pull();
		$params = null !== $answer ? self::params_json( (string) ( $answer['raw'] ?? '' ) ) : [];
		if ( null !== $answer && ManageJobsRequest::is_permission_answer( (int) $answer['status'], $answer['body'] ) ) {
			// The API says this grant may not pull (no wordpress:manage on
			// the token, or no beta access): no pull every 5 minutes until
			// that changes.
			self::pause();
			return;
		}
		if ( null !== $answer && 200 === $answer['status'] && is_array( $answer['body'] ) && isset( $answer['body']['jobs'] ) && is_array( $answer['body']['jobs'] ) ) {
			$listed = [];
			foreach ( $answer['body']['jobs'] as $raw ) {
				// The parameters as the raw answer had them, never a copy the
				// answer carries under another name.
				$json = is_array( $raw ) && is_string( $raw['id'] ?? null ) && isset( $params[ $raw['id'] ] )
					? $params[ $raw['id'] ]
					: JobToken::canonical_json( is_array( $raw ) && is_array( $raw['params'] ?? null ) ? $raw['params'] : [], true );
				$job  = self::job_of( $raw, $json );
				if ( null === $job ) {
					continue;
				}
				$listed[] = $job['id'];
				if ( self::take( $job, $now ) ) {
					$scheduled = true;
				}
			}
			// A job waiting to start that the API no longer lists: its
			// first heartbeat may have reached the API (the job runs there)
			// while its answer got lost. The run sends it again and starts
			// only when the API answers that the job runs; any other answer
			// leaves it waiting, as before.
			foreach ( array_diff( Ledger::scheduled(), $listed ) as $id ) {
				if ( self::forget_if_expired( $id, $now ) ) {
					continue;
				}
				$scheduled = self::schedule_run( $id ) || $scheduled;
			}
		}
		if ( $scheduled ) {
			Sync\Controller::run_queue_now();
		}
	}

	/**
	 * A scheduled job the API no longer lists and whose token's lease has
	 * passed never starts: the API cancelled, expired or handed it out
	 * again under a new attempt. Its row is closed instead of being
	 * scheduled again on every pull for a week.
	 *
	 * @param string $id  The job id.
	 * @param int    $now The time.
	 *
	 * @return bool Whether the row was closed.
	 */
	private static function forget_if_expired( string $id, int $now ): bool {
		$row = Ledger::get( $id );
		$job = null !== $row ? self::job_of( $row['job'] ?? null ) : null;
		if ( null === $job || JobToken::EXPIRED !== JobToken::check( $job, (string) Options\Controller::get_options()->site_id, $now ) ) {
			return false;
		}

		return Ledger::move(
			$id,
			$row,
			[
				'state' => Ledger::REPORTED,
				'at'    => $now,
			]
		);
	}

	/**
	 * Pause the clock: the API refused a request for the scope or for beta
	 * access, with the grant as it still is.
	 *
	 * @return void
	 */
	private static function pause(): void {
		update_option( self::PAUSED_OPTION, implode( ' ', Connect\Controller::granted_scopes() ), false );
		self::sync_clock();
	}

	/**
	 * The canonical JSON of each pulled job's parameters, by job id, from
	 * the raw answer: decoded with its objects kept, so an empty object and
	 * an empty list read as the API signed them.
	 *
	 * @param string $raw The raw answer of the pull.
	 *
	 * @return array<string, string>
	 */
	private static function params_json( string $raw ): array {
		$answer = '' === $raw ? null : json_decode( $raw, false );
		if ( ! is_object( $answer ) || ! isset( $answer->jobs ) || ! is_array( $answer->jobs ) ) {
			return [];
		}
		$out = [];
		foreach ( $answer->jobs as $job ) {
			if ( is_object( $job ) && isset( $job->id ) && is_string( $job->id ) ) {
				$out[ $job->id ] = JobToken::canonical_json( $job->params ?? new \stdClass(), true );
			}
		}

		return $out;
	}

	/**
	 * A job as the API sent it, checked: its id, action, parameters, attempt
	 * and signed token, or null when it is not a job. The parameters are
	 * kept as their canonical JSON (params_json) and read back from it, so
	 * the bytes the token is checked against are the parameters the action
	 * gets: a job changed in the ledger after the pull does not verify.
	 *
	 * @param mixed       $raw         The job from the API's answer, or as the ledger keeps it.
	 * @param string|null $params_json The canonical JSON of its parameters from the raw answer.
	 *
	 * @return array{id: string, action: string, params: array, attempt: int, job_token: string, lease_until: string, params_json?: string}|null
	 */
	public static function job_of( $raw, ?string $params_json = null ): ?array {
		if ( ! is_array( $raw ) || ! Ledger::valid_id( $raw['id'] ?? null ) ) {
			return null;
		}
		$action = $raw['action'] ?? null;
		if ( ! is_string( $action ) || '' === $action || strlen( $action ) > 64 ) {
			return null;
		}
		$params  = $raw['params'] ?? [];
		$attempt = $raw['attempt'] ?? 0;
		$token   = $raw['job_token'] ?? '';
		$lease   = $raw['lease_until'] ?? '';

		$job = [
			'id'          => $raw['id'],
			'action'      => $action,
			'params'      => is_array( $params ) ? $params : [],
			'attempt'     => is_int( $attempt ) && $attempt > 0 ? $attempt : 0,
			'job_token'   => is_string( $token ) && strlen( $token ) <= 8192 ? $token : '',
			'lease_until' => is_string( $lease ) ? substr( $lease, 0, 64 ) : '',
		];
		$json = $params_json ?? ( isset( $raw['params_json'] ) && is_string( $raw['params_json'] ) ? $raw['params_json'] : null );
		if ( null !== $json ) {
			$decoded             = json_decode( $json, true );
			$job['params']       = is_array( $decoded ) ? $decoded : [];
			$job['params_json']  = $json;
		}

		return $job;
	}

	/**
	 * Take a pulled job: record and schedule a job the plugin has not seen,
	 * and schedule again a job whose action went missing. A job that is
	 * scheduled, running or done stays as it is: it never starts twice. A job
	 * that never started and comes back with a new attempt (its lease passed
	 * and the API queued it again) keeps the new attempt and token.
	 *
	 * @param array{id: string, action: string, params: array, attempt: int, job_token: string} $job The job.
	 * @param int                                                                               $now The time.
	 *
	 * @return bool Whether an action was scheduled.
	 */
	private static function take( array $job, int $now ): bool {
		if ( Ledger::claim( $job, $now ) ) {
			return self::schedule_run( $job['id'] );
		}
		$row = Ledger::get( $job['id'] );
		if ( null === $row || Ledger::SCHEDULED !== $row['state'] ) {
			return false;
		}
		$stored = isset( $row['job'] ) && is_array( $row['job'] ) ? $row['job'] : [];
		if ( ( $stored['attempt'] ?? null ) !== $job['attempt'] || ( $stored['job_token'] ?? null ) !== $job['job_token'] ) {
			$fresh        = $row;
			$fresh['job'] = $job;
			$fresh['at']  = $now;
			Ledger::move( $job['id'], $row, $fresh );
		}

		// The run claims the job before it starts, so a second action of a
		// scheduled job does nothing.
		return self::schedule_run( $job['id'] );
	}

	/**
	 * Schedule the run action of a job, unless one is pending already. It
	 * carries only the id: the job itself is in the ledger, as pulled.
	 *
	 * @param string $id The job id.
	 *
	 * @return bool Whether an action was scheduled.
	 */
	private static function schedule_run( string $id ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) || as_has_scheduled_action( self::ACTION_RUN_JOB, [ $id ], self::AS_GROUP ) ) {
			return false;
		}
		as_schedule_single_action( time(), self::ACTION_RUN_JOB, [ $id ], self::AS_GROUP );

		return true;
	}

	/**
	 * Run a job, in its own Action Scheduler action: a scheduled job runs
	 * once and its result is reported; a job that ran reports its result
	 * again until the API took it; a job whose last sign of life is long ago
	 * reports that it stopped. Only a job the plugin pulled itself is in the
	 * ledger, and the plugin checks its own copy of the grant before it runs
	 * anything; a paused clock, or a copy of the site, runs nothing.
	 *
	 * @param mixed $id The job id.
	 *
	 * @return void
	 */
	public static function run_job( $id ): void {
		if ( ! Ledger::valid_id( $id ) || ! self::allowed() || self::paused() || Connect\Address::moved() ) {
			return;
		}
		$row = Ledger::get( $id );
		if ( null === $row ) {
			return;
		}

		$now = time();
		switch ( $row['state'] ) {
			case Ledger::SCHEDULED:
				$job = self::job_of( $row['job'] ?? null );
				if ( null === $job ) {
					return;
				}
				$attempt = $job['attempt'];
				$check   = JobToken::check( $job, (string) Options\Controller::get_options()->site_id, $now );
				if ( JobToken::EXPIRED === $check || JobToken::UNVERIFIED === $check ) {
					// Its lease passed before it could start (the API hands
					// it out again with a new attempt and token), or the key
					// set to check it could not be fetched: it waits for the
					// next pull, and nothing runs.
					return;
				}
				if ( JobToken::INVALID !== $check && ! RunLock::take( $id, self::lock_until( $job, $now ), $now ) ) {
					// Another job runs on the site: this one waits for the
					// next pull.
					return;
				}
				$running = [
					'state'      => Ledger::RUNNING,
					'at'         => $now,
					'started_at' => $now,
					'attempt'    => $attempt,
				];
				if ( ! Ledger::move( $id, $row, $running ) ) {
					RunLock::release( $id );
					return;
				}
				if ( JobToken::INVALID === $check ) {
					// Never run: reported failed, as Scanfully did not sign it
					// for this site, job, attempt and parameters.
					self::finish( $id, $now, Runner::failed( $now, time(), 'signature', 'The job\'s signed token is missing or does not match this site, job, attempt and parameters, so the plugin did not run it.' ) );
					return;
				}
				self::run_started( $id, $job, $now );
				break;
			case Ledger::RUNNING:
				if ( $row['at'] >= $now - self::STALE_RUN ) {
					return;
				}
				$started = (int) ( $row['started_at'] ?? $row['at'] );
				$result  = Runner::failed( $started, $now, 'fatal', 'The job started on the site but never ended, for example because PHP stopped. What it changed is unknown.' );
				self::finish( $id, $started, $result );
				RunLock::release( $id );
				break;
			case Ledger::DONE:
				self::report( $id );
				break;
		}
	}

	/**
	 * Until when a starting job holds the site's run lock: the end of its
	 * lease (its token's exp), at most the stale threshold away and at
	 * least a minute; every heartbeat extends it.
	 *
	 * @param array $job The job.
	 * @param int   $now The time.
	 *
	 * @return int
	 */
	private static function lock_until( array $job, int $now ): int {
		$lease = JobToken::expiry( $job );

		return max( $now + MINUTE_IN_SECONDS, min( $lease ?? $now + self::STALE_RUN, $now + self::STALE_RUN ) );
	}

	/**
	 * Run a job that holds the site's run lock and is running in the ledger,
	 * and keep its result. The JobRun guards the way out: a PHP fatal mid-job
	 * still removes a maintenance file the job caused, switches a plugin the
	 * upgrader switched off on again, releases the lock and leaves a failed
	 * result for the next pull.
	 *
	 * @param string $id  The job id.
	 * @param array  $job The job.
	 * @param int    $now When it started.
	 *
	 * @return void
	 */
	private static function run_started( string $id, array $job, int $now ): void {
		$attempt = (int) ( $job['attempt'] ?? 0 );
		$run     = new JobRun(
			$id,
			static function ( string $class, string $message ) use ( $id, $now ): void {
				self::finish( $id, $now, Runner::failed( $now, time(), $class, $message ), false );
			}
		);
		$run->arm();
		try {
			$result = Runner::run(
				$job,
				static function () use ( $id, $attempt ): ?bool {
					return self::send_heartbeat( $id, $attempt );
				},
				null,
				null,
				$run
			);
			if ( null === $result ) {
				// Not started: the job is the next pull's again.
				$row = Ledger::get( $id );
				if ( null !== $row && Ledger::RUNNING === $row['state'] ) {
					Ledger::move(
						$id,
						$row,
						[
							'state' => Ledger::SCHEDULED,
							'at'    => time(),
							'job'   => $job,
						]
					);
				}
				return;
			}
			self::finish( $id, $now, $result );
		} finally {
			$run->release();
		}
	}

	/**
	 * Send a heartbeat of a job. The first one decides whether the job
	 * starts: only when the API answers 2xx with the job running. Every other
	 * answer, no answer included, leaves the job for the next pull: a 409
	 * (the lease passed and the job went back to the queue, or it finished
	 * there), a 404 (not this site's job), a server error or a firewall's
	 * page. A 401 has the next pull refresh the access token first; a 403
	 * for the scope or beta access (Scanfully Manage was switched off in the
	 * dashboard after the pull) pauses the clock, as a refused pull does, and
	 * leaves the job to the API, which cancels or expires it.
	 *
	 * Every heartbeat carries the job's attempt, and extends the site's run
	 * lock.
	 *
	 * @param string $id      The job id.
	 * @param int    $attempt The job's attempt.
	 *
	 * @return bool|null Whether the API answered that the job runs; null when it gave no answer.
	 */
	public static function send_heartbeat( string $id, int $attempt = 0 ): ?bool {
		$answer = ( new ManageJobsRequest() )->heartbeat( $id, $attempt );
		Ledger::touch( $id, time() );
		RunLock::extend( $id, time() + self::STALE_RUN );
		if ( null === $answer ) {
			return null;
		}
		$status = (int) $answer['status'];
		if ( $status >= 200 && $status < 300 ) {
			return is_array( $answer['body'] ) && isset( $answer['body']['state'] ) && 'running' === $answer['body']['state'];
		}
		if ( 401 === $status ) {
			Cron\Controller::refresh_access_token_soon();
		} elseif ( ManageJobsRequest::is_permission_answer( $status, $answer['body'] ) ) {
			self::pause();
		}

		return false;
	}

	/**
	 * Keep a job's result, without the job's parameters and token, and
	 * report it.
	 *
	 * @param string $id      The job id.
	 * @param int    $started When it started.
	 * @param array  $result  The result.
	 * @param bool   $report  Whether to report it now; after a PHP fatal the next pull does.
	 *
	 * @return void
	 */
	private static function finish( string $id, int $started, array $result, bool $report = true ): void {
		$row = Ledger::get( $id );
		if ( null === $row || Ledger::RUNNING !== $row['state'] ) {
			return;
		}
		$done = [
			'state'      => Ledger::DONE,
			'at'         => time(),
			'started_at' => $started,
			'attempt'    => (int) ( $row['attempt'] ?? 0 ),
			'result'     => $result,
		];
		if ( Ledger::move( $id, $row, $done ) && $report ) {
			self::report( $id );
		}
	}

	/**
	 * Send a job's kept result to the API. The API took it when it answers
	 * 2xx, when it says the job finished or is not this site's (409), or that
	 * there is no such job (404); a result it cannot take now (no answer, an
	 * error, the job queued again) is sent again on the next pull.
	 *
	 * @param string $id The job id.
	 *
	 * @return void
	 */
	private static function report( string $id ): void {
		$row = Ledger::get( $id );
		if ( null === $row || Ledger::DONE !== $row['state'] || ! isset( $row['result'] ) || ! is_array( $row['result'] ) ) {
			return;
		}
		$result            = $row['result'];
		$result['attempt'] = (int) ( $row['attempt'] ?? 0 );
		$result['result']  = (object) ( isset( $result['result'] ) && is_array( $result['result'] ) ? $result['result'] : [] );

		$answer = ( new ManageJobsRequest() )->result( $id, $result );
		if ( null === $answer ) {
			return;
		}
		$status = (int) $answer['status'];
		$state  = is_array( $answer['body'] ) && isset( $answer['body']['state'] ) ? (string) $answer['body']['state'] : '';
		$taken  = ( $status >= 200 && $status < 300 ) || 404 === $status || ( 409 === $status && ! in_array( $state, self::OPEN_STATES, true ) );
		if ( ! $taken && $status >= 400 && $status < 500 && ! in_array( $status, [ 401, 404, 409, 429 ], true ) ) {
			// The API refuses this result for good (too large, or a field
			// it does not take): it gets the smallest result once, and the
			// job is not sent again for a week.
			( new ManageJobsRequest() )->result( $id, Runner::minimal( $result ) );
			$taken = true;
		}
		if ( ! $taken ) {
			return;
		}
		Ledger::move(
			$id,
			$row,
			[
				'state' => Ledger::REPORTED,
				'at'    => time(),
			]
		);
	}
}
