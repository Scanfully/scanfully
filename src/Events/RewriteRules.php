<?php

namespace Watchfully\Events;

class RewriteRules extends Event {

	public function __construct() {
		parent::__construct( 'New rewrite rules save', 'update_option_rewrite_rules', 'RewriteRules' );
	}

	public function get_post_body( array $data ): array {

		//$old_value, $value, $option

		// @todo: rewrite rules aren't posted to the endpoint

		return [
			'rewrite_rules' => $data[1]
		];
	}
}