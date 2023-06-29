<?php

namespace Watchfully\Events;


use Watchfully\API\EventRequest;

abstract class Event {

	private $name;
	private $action;
	private $endpoint;
	private $priority = 10;
	private $accepted_args = 1;

	public function __construct(
		string $name,
		string $action,
		string $endpoint,
		int $priority = 10,
		int $accepted_args = 1
	) {
		$this->name          = $name;
		$this->action        = $action;
		$this->endpoint      = $endpoint;
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
		$request->send( $this->endpoint, $this->get_post_body( $args ) );
	}

	abstract protected function get_post_body( array $data ): array;

}