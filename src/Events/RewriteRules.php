<?php

namespace Scanfully\Events;

class RewriteRules extends Event {

	public function __construct() {
		parent::__construct( 'RewriteRules', 'update_option_rewrite_rules', 10, 3 );
	}

	// @todo: don't log event whent rewrite rules are empty

	public function get_post_body( array $data ): array {
		return [
			'rewrite_rules' => $data[1]
		];
	}
}