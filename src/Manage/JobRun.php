<?php
/**
 * The Manage job run class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

/**
 * One job while it runs: what its action may call on (heartbeats inside long
 * steps, clean-up tasks, the ids of the activity events it caused), and the
 * guard that leaves the site clean on every way out, a PHP fatal included.
 *
 * On the way out the run removes the WordPress maintenance file when the job
 * put it there, runs the clean-up tasks its action registered (re-enabling a
 * plugin the upgrader switched off, for example), and releases the site's
 * run lock. When PHP stops in the middle of the job (a fatal error, a plugin
 * calling exit, the time limit), a shutdown handler does the same and leaves
 * the job's ledger row with a failed result that the next pull reports.
 */
final class JobRun {

	/**
	 * The activity event types the plugin sends for what a job changes
	 * (updates, installs, activation and deactivation, deletes and a theme
	 * switch): while a job runs, each one carries the job id, so Scanfully
	 * shows the change once.
	 */
	public const TAGGED_EVENTS = [
		'PluginUpdate',
		'ThemeUpdate',
		'CoreUpdate',
		'PluginUpdateFailed',
		'ThemeUpdateFailed',
		'CoreUpdateFailed',
		'PluginInstalled',
		'ThemeInstalled',
		'PluginActivate',
		'PluginDeactivate',
		'PluginDeleted',
		'ThemeDeleted',
		'ThemeSwitched',
	];

	/**
	 * PHP errors that stop the request.
	 */
	private const FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

	/**
	 * The job id.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * The heartbeat, once the job started.
	 *
	 * @var Heartbeat|null
	 */
	private ?Heartbeat $heartbeat = null;

	/**
	 * Clean-up tasks, run once on the way out, in the order added. Each
	 * returns null, or a problem as error class and message.
	 *
	 * @var array<int, callable>
	 */
	private array $exit_tasks = [];

	/**
	 * What to do with the ledger when PHP stopped mid-job: takes the error
	 * class and the message.
	 *
	 * @var callable|null
	 */
	private $on_stop;

	/**
	 * The maintenance file WordPress writes.
	 *
	 * @var string
	 */
	private string $maintenance_file;

	/**
	 * Whether the maintenance file was there before the job started: then it
	 * is someone else's, and the job leaves it.
	 *
	 * @var bool
	 */
	private bool $maintenance_before;

	/**
	 * Whether the clean-up ran.
	 *
	 * @var bool
	 */
	private bool $cleaned = false;

	/**
	 * Whether the run is over (released): the shutdown handler does nothing.
	 *
	 * @var bool
	 */
	private bool $released = false;

	/**
	 * The ids of the activity events the job caused.
	 *
	 * @var string[]
	 */
	private array $activity_ids = [];

	/**
	 * Lines for the result's log, from the run itself.
	 *
	 * @var string[]
	 */
	private array $log = [];

	/**
	 * The download of a job that installs an upload, as the pull gave it.
	 *
	 * @var array{url: string, sha256: string, size?: int}|null
	 */
	private ?array $package = null;

	/**
	 * Constructor.
	 *
	 * @param string        $id               The job id.
	 * @param callable|null $on_stop          Takes the error class and message when PHP stopped mid-job.
	 * @param string|null   $maintenance_file The maintenance file; ABSPATH . '.maintenance' when null.
	 */
	public function __construct( string $id, ?callable $on_stop = null, ?string $maintenance_file = null ) {
		$this->id                 = $id;
		$this->on_stop            = $on_stop;
		$this->maintenance_file   = $maintenance_file ?? ( defined( 'ABSPATH' ) ? ABSPATH . '.maintenance' : '' );
		$this->maintenance_before = '' !== $this->maintenance_file && file_exists( $this->maintenance_file );
	}

	/**
	 * The job id.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Watch for PHP stopping mid-job, and tag the activity events the job
	 * causes with its id.
	 *
	 * @return void
	 */
	public function arm(): void {
		register_shutdown_function( [ $this, 'shutdown' ] );
		add_filter( 'scanfully_event_data', [ $this, 'tag_event' ], 10, 2 );
	}

	/**
	 * Use the download the pull gave for the job.
	 *
	 * @param array{url: string, sha256: string, size?: int}|null $package The download.
	 *
	 * @return void
	 */
	public function set_package( ?array $package ): void {
		$this->package = $package;
	}

	/**
	 * The download the pull gave for the job: its URL and SHA-256.
	 *
	 * @return array{url: string, sha256: string, size?: int}|null
	 */
	public function package(): ?array {
		return $this->package;
	}

	/**
	 * Use the job's heartbeat.
	 *
	 * @param Heartbeat $heartbeat The heartbeat.
	 *
	 * @return void
	 */
	public function set_heartbeat( Heartbeat $heartbeat ): void {
		$this->heartbeat = $heartbeat;
	}

	/**
	 * Send a heartbeat: at once before or after a long step, else when one
	 * is due.
	 *
	 * @param bool $now Whether to send it now, whenever the last one went.
	 *
	 * @return void
	 */
	public function beat( bool $now = false ): void {
		if ( null !== $this->heartbeat ) {
			$this->heartbeat->beat( $now );
		}
	}

	/**
	 * Add a clean-up task, run once on the way out, also after a fatal.
	 *
	 * @param callable $task Returns null, or a problem: array{class: string, message: string}.
	 *
	 * @return void
	 */
	public function on_exit( callable $task ): void {
		$this->exit_tasks[] = $task;
	}

	/**
	 * Add a line to the result's log.
	 *
	 * @param string $line The line.
	 *
	 * @return void
	 */
	public function log( string $line ): void {
		$this->log[] = $line;
	}

	/**
	 * The run's own log lines.
	 *
	 * @return string[]
	 */
	public function log_lines(): array {
		return $this->log;
	}

	/**
	 * The ids of the activity events the job caused.
	 *
	 * @return string[]
	 */
	public function activity_ids(): array {
		return $this->activity_ids;
	}

	/**
	 * Tag an update activity event the job caused with the job id and an id
	 * of its own, which the result lists.
	 *
	 * @param mixed  $data The event data.
	 * @param string $type The event type.
	 *
	 * @return mixed
	 */
	public function tag_event( $data, $type ) {
		if ( $this->released || ! is_array( $data ) || ! in_array( $type, self::TAGGED_EVENTS, true ) ) {
			return $data;
		}
		$activity_id          = wp_generate_uuid4();
		$data['manage_job_id'] = $this->id;
		$data['activity_id']   = $activity_id;
		$this->activity_ids[]  = $activity_id;

		return $data;
	}

	/**
	 * Clean up, once: the clean-up tasks, then the maintenance file when the
	 * job put it there. Returns the first problem.
	 *
	 * @return array{class: string, message: string}|null
	 */
	public function clean(): ?array {
		if ( $this->cleaned ) {
			return null;
		}
		$this->cleaned = true;

		$problem = null;
		foreach ( $this->exit_tasks as $task ) {
			try {
				$found = $task();
			} catch ( \Throwable $e ) {
				$found = [
					'class'   => 'other',
					'message' => $e->getMessage(),
				];
			}
			if ( null === $problem && is_array( $found ) && isset( $found['class'], $found['message'] ) ) {
				$problem = $found;
			}
		}

		$maintenance = $this->remove_maintenance();

		return $maintenance ?? $problem;
	}

	/**
	 * Remove the maintenance file when it was not there before the job.
	 *
	 * @return array{class: string, message: string}|null A problem when it stays.
	 */
	private function remove_maintenance(): ?array {
		if ( '' === $this->maintenance_file || $this->maintenance_before ) {
			return null;
		}
		clearstatcache( true, $this->maintenance_file );
		if ( ! file_exists( $this->maintenance_file ) ) {
			return null;
		}
		global $wp_filesystem;
		if ( is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'delete' ) ) {
			$wp_filesystem->delete( $this->maintenance_file );
		}
		clearstatcache( true, $this->maintenance_file );
		if ( file_exists( $this->maintenance_file ) ) {
			wp_delete_file( $this->maintenance_file );
			clearstatcache( true, $this->maintenance_file );
		}
		if ( file_exists( $this->maintenance_file ) ) {
			return [
				'class'   => 'maintenance',
				'message' => 'WordPress\'s maintenance file (.maintenance) could not be removed, so the site may show its maintenance page. WordPress stops showing it 10 minutes after the file was written; remove the file to end it sooner.',
			];
		}
		$this->log( 'Scanfully removed the maintenance file (.maintenance) the update left behind.' );

		return null;
	}

	/**
	 * End the run: release the site's run lock and stop watching. Cleans up
	 * first when that did not happen yet.
	 *
	 * @return void
	 */
	public function release(): void {
		if ( $this->released ) {
			return;
		}
		$this->clean();
		$this->released = true;
		remove_filter( 'scanfully_event_data', [ $this, 'tag_event' ], 10 );
		RunLock::release( $this->id );
	}

	/**
	 * The shutdown handler: PHP stopped while the job ran. Clean up, leave a
	 * failed result in the ledger for the next pull, and release the lock.
	 *
	 * @return void
	 */
	public function shutdown(): void {
		if ( $this->released ) {
			return;
		}
		$error   = error_get_last();
		$message = is_array( $error ) && ( (int) $error['type'] & self::FATAL_ERRORS ) ? (string) $error['message'] : '';
		$class   = false !== stripos( $message, 'maximum execution time' ) ? 'timeout' : 'fatal';
		$problem = $this->clean();
		$text    = '' !== $message
			? 'PHP stopped while the job ran: ' . $message
			: 'PHP stopped while the job ran, without an error (something called exit or the request was cut off).';
		if ( null !== $problem ) {
			$text .= ' ' . $problem['message'];
		}
		if ( null !== $this->on_stop ) {
			try {
				call_user_func( $this->on_stop, $class, Failure::clean( $text ) );
			} catch ( \Throwable $e ) {
				unset( $e ); // The next pull reports the job as stopped after all.
			}
		}
		$this->release();
	}
}
