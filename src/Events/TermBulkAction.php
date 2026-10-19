<?php
/**
 * The term bulk action event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class TermBulkAction
 *
 * Many terms of one taxonomy were created, changed or deleted in one
 * request (more than the `scanfully_activity_bulk_threshold`, 10 by
 * default), or while the per-minute cap was reached: one row for all of
 * them.
 *
 * Data: `action` (`created`, `changed`, `deleted`), `taxonomy`, `label`
 * (the taxonomy's plural name on the site), `count`, `terms` (every term:
 * `id`, `name`), `changed` (per part, in how many terms it changed),
 * `posts` (deletes: how many posts had the terms), `overflow`, and `part`,
 * `parts`, `total` as for PostBulkAction.
 *
 * @package Scanfully\Events
 */
class TermBulkAction extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'TermBulkAction' );
	}
}
