<?php
/**
 * The Manage heartbeat class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

/**
 * Sends a long job's heartbeats, at most one per interval: a long action
 * calls beat() as often as it likes, for example once per file it writes.
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
	 * Send the first heartbeat: the job runs.
	 *
	 * @return void
	 */
	public function start(): void {
		$this->send_now();
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
	 * @return void
	 */
	private function send_now(): void {
		$this->last = (int) call_user_func( $this->now );
		call_user_func( $this->send );
	}
}
