<?php

namespace Scanfully\Events;

use Scanfully\API\EventRequest;

abstract class Event {

	private $type;
	private $action;
	private $priority      = 10;
	private $accepted_args = 1;

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

	private function add_listener() {
		add_action( $this->action, [ $this, 'listener_callback' ], $this->priority, $this->accepted_args );
	}

	private function get_user(): array {
		$current_user = wp_get_current_user();

		return [
			'id'   => $current_user->ID,
			'name' => $current_user->display_name,
		];
	}

	public function listener_callback( ...$args ) {
		$request = new EventRequest();
		$request->send_event(
			[
				'type' => $this->type,
				'user' => $this->get_user(),
				'data' => $this->get_post_body( $args ),
			]
		);
	}

	abstract protected function get_post_body( array $data ): array;
}
