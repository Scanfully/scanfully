<?php
/**
 * The media changed event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class MediaChanged
 *
 * A file in the media library changed: its title, alt text, caption,
 * description or slug, its file (renamed, converted, or replaced through
 * Enable Media Replace), an edit in the image editor, the post it is
 * attached to, or moved to the trash and back (with MEDIA_TRASH on).
 * WordPress's own follow-up files of an upload (`-scaled`, `-rotated`) and
 * new thumbnails or compression are not changes.
 *
 * Data: `id`, `name`, `file`, `title`, `mime_type`, `filesize`, `width`,
 * `height`, `length`, and `changes`, each optional: `title`, `alt`,
 * `caption`, `description`, `slug`, `status` (`old`/`new`), `file`
 * (`old`/`new` file name, `replaced` for a replace), `image` (`edits` and
 * `target`, or `restored`), `parent` (`old`/`new` as `id`, `title`,
 * `type_label`).
 *
 * @package Scanfully\Events
 */
class MediaChanged extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'MediaChanged' );
	}
}
