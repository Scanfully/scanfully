<?php
/**
 * The media deleted event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class MediaDeleted
 *
 * A file was deleted from the media library permanently: Delete
 * permanently, the media trash WordPress empties itself (with MEDIA_TRASH
 * on), WP-CLI or a REST force delete. A file uploaded and deleted within
 * the same minute is not reported.
 *
 * Data: `id`, `name`, `file`, `title`, `mime_type`, `filesize`, `width`,
 * `height`, `length`, `post_status` before the delete, `featured_on` (how
 * many posts had it as featured image), and `expired` with `trash_days`
 * when WordPress emptied the trash itself.
 *
 * @package Scanfully\Events
 */
class MediaDeleted extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'MediaDeleted' );
	}
}
