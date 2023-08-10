<?php

namespace Scanfully\Events;


use Scanfully\API\EventRequest;

abstract class Event {

	private $event;
	private $action;
	private $priority = 10;
	private $accepted_args = 1;

	public function __construct(
		string $event,
		string $action,
		int $priority = 10,
		int $accepted_args = 1
	) {
		$this->event         = $event;
		$this->action        = $action;
		$this->priority      = $priority;
		$this->accepted_args = $accepted_args;

		$this->add_listener();
	}

	private function add_listener() {
		add_action( $this->action, [ $this, 'listener_callback' ], $this->priority, $this->accepted_args );
	}

	public function listener_callback( ...$args ) {
		error_log( 'event fired' );
		error_log( print_r( $args, true ) );

		$request = new EventRequest();
		$request->send_event( [ "event" => $this->event, "data" => $this->get_post_body( $args ) ] );
	}

	abstract protected function get_post_body( array $data ): array;

}