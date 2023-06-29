<?php

namespace Watchfully\Events;

class RewriteRules extends Event {

	public function __construct() {
		parent::__construct( 'New rewrite rules save', 'update_option_rewrite_rules', 'RewriteRules', 10, 3 );
	}

	public function get_post_body( array $data ): array {
		return [
			'rewrite_rules' => $data[1]
		];
	}
}