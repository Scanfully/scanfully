<?php
/**
 * The FontInstalled event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class FontInstalled
 *
 * A font family was installed in the Font Library, or variants were added
 * to one. The requests of an install (one per variant) are joined in a
 * minute batch per person and family (SiteEditor\Fonts).
 *
 * Data: `name`, `slug`, `family` (true when the family itself is new) and
 * `variants` (each `style` and `weight`).
 *
 * @package Scanfully\Events
 */
class FontInstalled extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'FontInstalled' );
	}
}
