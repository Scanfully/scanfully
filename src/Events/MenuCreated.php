<?php
/**
 * The MenuCreated event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class MenuCreated
 *
 * A navigation menu was made: a classic menu (Appearance, Menus, the Customizer, REST, WP-CLI) or a block theme menu (a wp_navigation post). Fallback menus the block editor makes by itself are not reported.
 *
 * Data: `id`, `name`, `kind` (`classic` or `block`), `items` (how many) and `locations` (`slug`, `name`) where it shows.
 *
 * @package Scanfully\Events
 */
class MenuCreated extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'MenuCreated' );
	}
}
