<?php

namespace Watchfully\Events;

class ActivatedPlugin extends Event {

	public function __construct() {
		parent::__construct( 'Plugin Activated', 'activated_plugin', 'PluginActivated' );
	}

	public function get_post_body( array $data ): array {
		return [
			'plugin' => $data[0]
		];
	}
}