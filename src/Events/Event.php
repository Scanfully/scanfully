<?php

namespace Watchfully\Events;


use Watchfully\API\EventRequest;

abstract class Event {

	private $name;
	private $action;
	private $endpoint;

	public function __construct( string $name, string $action, $endpoint ) {
		$this->name     = $name;
		$this->action   = $action;
		$this->endpoint = $endpoint;

		$this->add_listener();
	}

	private function add_listener() {
		add_action( $this->action, [ $this, 'listener_callback' ] );
	}

	public function listener_callback( ...$args ) {
		error_log( 'event fired' );
		error_log( print_r( $args, true ) );

		$request = new EventRequest();
		$request->send( $this->endpoint, $this->get_post_body( $args ) );
	}

	abstract protected function get_post_body( array $data ): array;

}