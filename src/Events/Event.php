<?php
/**
 * The events class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

use Scanfully\API\EventRequest;

/**
 * Class Event
 */
abstract class Event {

	/**
	 * The type of event
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Action to listen to
	 *
	 * @var string
	 */
	private string $action;

	/**
	 * Priority of the action
	 *
	 * @var int
	 */
	private int $priority = 10;

	/**
	 * Accepted arguments
	 *
	 * @var int
	 */
	private int $accepted_args = 1;

	/**
	 * Constructor
	 *
	 * @param  string $event The type of event.
	 * @param  string $action The action to listen to.
	 * @param  int $priority The priority of the action.
	 * @param  int $accepted_args The accepted arguments.
	 */
	public function __construct(
		string $event,
		string $action,
		int $priority = 10,
		int $accepted_args = 1
	) {
		$this->type          = $event;
		$this->action        = $action;
		$this->priority      = $priority;
		$this->accepted_args = $accepted_args;

		$this->add_listener();
	}

	/**
	 * Add the listener to the action
	 *
	 * @return void
	 */
	private function add_listener(): void {
		add_action( $this->action, [ $this, 'listener_callback' ], $this->priority, $this->accepted_args );
	}

	/**
	 * Get the current user
	 *
	 * @return array
	 */
	private function get_user(): array {
		$current_user = wp_get_current_user();

		return [
			'id'   => $current_user->ID,
			'name' => $current_user->display_name,
		];
	}


	/**
	 * The callback for the action
	 *
	 * @param  any ...$args The arguments passed to the action.
	 *
	 * @return void
	 */
	public function listener_callback( ...$args ): void {

		// todo remove
		error_log( "listener_callback: " . $this->action );

		// check if we should fire the event.
		if ( ! $this->should_fire( $args ) ) {
			return;
		}

		// build the request and send it.
		$request = new EventRequest();
		$request->send(
			[
				'type' => $this->type,
				'user' => $this->get_user(),
				'data' => $this->get_post_body( $args ),
			]
		);
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data passed to the action.
	 *
	 * @return array
	 */
	abstract public function get_post_body( array $data ): array;

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data
	 *
	 * @return bool
	 */
	abstract public function should_fire( array $data ): bool;
}
