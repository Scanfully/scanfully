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
	 * Constructor.
	 *
	 * @param callable      $send  Sends one heartbeat to the API.
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
	 * Send a heartbeat when the last one is an interval ago.
	 *
	 * @return void
	 */
	public function beat(): void {
		if ( null === $this->last || (int) call_user_func( $this->now ) - $this->last >= $this->every ) {
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

		return call_user_func( $this->send );
	}
}
