<?php
/**
 * The term created event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class TermCreated
 *
 * A category, tag or term of another taxonomy with an admin screen was
 * created. A term created while a post is saved, and added to that post,
 * is not reported on its own: the post's row says it was added.
 *
 * Data: `id`, `name`, `slug`, `taxonomy`, `label` (the taxonomy's singular
 * name on the site), and `parent` (`id`, `name`) for a term under another.
 *
 * @package Scanfully\Events
 */
class TermCreated extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'TermCreated' );
	}
}
