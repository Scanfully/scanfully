<?php
/**
 * The media bulk action event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class MediaBulkAction
 *
 * Many files got the same action from one person within a minute (more
 * than the `scanfully_activity_bulk_threshold`, 10 by default), or the
 * minute had more media events than the per-minute cap: one row for all of
 * them instead of one each.
 *
 * Data: `action` (`uploaded`, `changed`, `attached`, `detached`, `trashed`,
 * `restored`, `deleted`), `count`, `files` (every file: `id`, `name`,
 * `title`), optional `changed` (per part, in how many files it changed),
 * `parent` (`id`, `title`, `type_label`) when all were attached to one
 * post, `expired` and `trash_days` (WordPress emptied the trash itself),
 * `overflow` (over the per-minute cap), and `part`, `parts`, `total` when
 * one action had more files than one row holds.
 *
 * @package Scanfully\Events
 */
class MediaBulkAction extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'MediaBulkAction' );
	}
}
