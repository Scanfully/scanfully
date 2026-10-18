<?php
/**
 * The MenuDeleted event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class MenuDeleted
 *
 * A navigation menu was deleted permanently.
 *
 * Data: `id`, `name`, `kind`, `items` (how many it had) and `locations` (`slug`, `name`) where it showed.
 *
 * @package Scanfully\Events
 */
class MenuDeleted extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'MenuDeleted' );
	}
}
