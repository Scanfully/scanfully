<?php
/**
 * The MenuChanged event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class MenuChanged
 *
 * A navigation menu changed: renamed, items added, removed, renamed, relinked or moved, other item details, new top-level pages added by itself or not, shown in other theme locations, or a block menu trashed or restored. Saving a menu without changes is not reported.
 *
 * Data: `id`, `name`, `kind`, `items` and `changes` (see MenuChanges\\Builder).
 *
 * @package Scanfully\Events
 */
class MenuChanged extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'MenuChanged' );
	}
}
