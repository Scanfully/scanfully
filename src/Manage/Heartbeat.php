<?php
/**
 * The Manage heartbeat class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

/**
 * Sends a job's heartbeats: the first as the job starts, then at most one
 * per interval, as a long action calls beat() as often as it likes, for
 * example once per file it writes.
 */
class Heartbeat {

	/**
	 * Sends one heartbeat to the API.
	 *
	 * @var callable
	 */
	private $send;

	/**
	 * Seconds between two heartbeats.
	 *
	 * @var int
	 */
	private int $every;

	/**
	 * The clock.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * When the last heartbeat went, or null before the first.
	 *
	 * @var int|null
	 */
	private ?int $last = null;

	/**
	 * Seconds between two heartbeats sent at once at long steps.
	 */
	public const STEP_FLOOR = 5;

	/**
	 * Until when no heartbeat is sent: the last one got no answer, and each
	 * waits up to its timeout, so an unreachable API must not hold the run
	 * for that long at every step.
	 *
	 * @var int
	 */
	private int $quiet_until = 0;

	/**
	 * Constructor.
	 *
	 * @param callable      $send  Sends one heartbeat to the API: true when it runs, false when refused, null without an answer.
	 * @param int           $every Seconds between two heartbeats.
	 * @param callable|null $now   The clock; time() when null.
	 */
	public function __construct( callable $send, int $every, ?callable $now = null ) {
		$this->send  = $send;
		$this->every = $every;
		$this->now   = $now ?? 'time';
	}

	/**
	 * Send the first heartbeat: the job runs only when the API answered that
	 * it does.
	 *
	 * @return bool True only when the sender said the API took it: anything
	 *              else, no answer included, means the job does not start.
	 */
	public function start(): bool {
		return true === $this->send_now();
	}

	/**
	 * Send a heartbeat when the last one is an interval ago, or at once:
	 * before and after a long step (a download, an unzip, the maintenance
	 * file), so the lease never passes while the step runs.
	 *
	 * @param bool $now Whether to send it now, whenever the last one went.
	 *
	 * @return void
	 */
	public function beat( bool $now = false ): void {
		$time = (int) call_user_func( $this->now );
		if ( $time < $this->quiet_until ) {
			return;
		}
		$since = null === $this->last ? PHP_INT_MAX : $time - $this->last;
		if ( $since >= $this->every || ( $now && $since >= self::STEP_FLOOR ) ) {
			$this->send_now();
		}
	}

	/**
	 * Send a heartbeat now.
	 *
	 * @return mixed What the sender returned.
	 */
	private function send_now() {
		$this->last = (int) call_user_func( $this->now );
		$answer     = call_user_func( $this->send );
		if ( null === $answer ) {
			$this->quiet_until = $this->last + $this->every;
		}

		return $answer;
	}
}
