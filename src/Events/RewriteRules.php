<?php

namespace Scanfully\Events;

/**
 * Class RewriteRules
 *
 * @package Scanfully\Events
 */
class RewriteRules extends Event {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'RewriteRules', 'update_option_rewrite_rules', 10, 3 );
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		return [
			'rewrite_rules' => $data[1],
		];
	}
}
