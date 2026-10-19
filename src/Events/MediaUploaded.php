<?php
/**
 * The media uploaded event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class MediaUploaded
 *
 * A file was added to the media library: uploaded in the media library or
 * the editor, through the REST API or WP-CLI, downloaded by a plugin, or
 * the copy the block editor makes when an image is cropped or rotated. Not
 * during an import. Reported a minute later with the file as it is then
 * (MediaChanges\Batch), so details filled in right after the upload are in
 * it.
 *
 * Data: `id`, `name` (file name), `file` (path in the uploads folder),
 * `title`, `mime_type`, `filesize`, `width`, `height` and `length` as far
 * as WordPress knows them, `parent` (`id`, `title`, `type_label`) when
 * uploaded to a post, and `edited_from` (`id`, `name`) with `edits` (`crop`,
 * `rotate`, `flip`) for an edited copy.
 *
 * @package Scanfully\Events
 */
class MediaUploaded extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'MediaUploaded' );
	}
}
