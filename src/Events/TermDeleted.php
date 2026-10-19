<?php
/**
 * The term deleted event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class TermDeleted
 *
 * A term was deleted.
 *
 * Data: `id`, `name`, `slug`, `taxonomy`, `label`, and `posts`: how many
 * posts (or other objects) had the term.
 *
 * @package Scanfully\Events
 */
class TermDeleted extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'TermDeleted' );
	}
}
