<?php
/**
 * The post bulk action event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class PostBulkAction
 *
 * Many posts of one type got the same action in one request (more than the
 * `scanfully_activity_bulk_threshold`, 10 by default), or while the
 * per-minute cap was reached: one row for all of them instead of one each.
 *
 * Data: `action` (`created`, `updated`, `published`, `drafted`, `privated`,
 * `trashed`, `restored`, `deleted`), `post_type`, `type_label` (the type's
 * plural name on the site), `count`, `posts` (every post: `id`, `title`),
 * optional `changed` (per part, in how many posts it changed), `terms`
 * (terms added and removed, by name, per taxonomy), `expired` and
 * `trash_days` (WordPress emptied the trash itself), `overflow` (over the
 * per-minute cap), and `part`, `parts`, `total` when one action had more
 * posts than one row holds (Content\Summary::MAX_ITEMS_PER_ROW).
 *
 * @package Scanfully\Events
 */
class PostBulkAction extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'PostBulkAction' );
	}
}
