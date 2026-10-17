<?php
/**
 * The term changed event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class TermChanged
 *
 * The name, slug, parent or description of a term changed. Saving a term
 * without changing any of them is not reported.
 *
 * Data: `id`, `name`, `slug`, `taxonomy`, `label`, and `changes`: `name`,
 * `slug` and `description` (each `old` and `new`) and `parent` (`old` and
 * `new`, each `id` and `name`).
 *
 * @package Scanfully\Events
 */
class TermChanged extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'TermChanged' );
	}
}
