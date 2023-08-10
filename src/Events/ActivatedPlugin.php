<?php

namespace Scanfully\Events;

class ActivatedPlugin extends Event {

	public function __construct() {
		parent::__construct( 'PluginActivated', 'activated_plugin' );
	}

	public function get_post_body( array $data ): array {
		return [
			'plugin' => $data[0]
		];
	}
}