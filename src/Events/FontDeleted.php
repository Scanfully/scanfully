<?php
/**
 * The FontDeleted event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class FontDeleted
 *
 * A font family was removed from the Font Library, or variants were
 * removed from one (SiteEditor\Fonts).
 *
 * Data: `name`, `slug`, `family` (true when the whole family was removed)
 * and `variants` (each `style` and `weight`).
 *
 * @package Scanfully\Events
 */
class FontDeleted extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'FontDeleted' );
	}
}
