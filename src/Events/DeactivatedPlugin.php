<?php

namespace Scanfully\Events;

class DeactivatedPlugin extends Event {

	public function __construct() {
		parent::__construct( 'Plugin Deactivated', 'deactivated_plugin', 'PluginDeactivated' );
	}

	public function get_post_body( array $data ): array {
		return [
			'plugin' => $data[0]
		];
	}
}