<?php
/**
 * The WidgetsChanged event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class WidgetsChanged
 *
 * Widgets were added, changed, moved or removed in one save (the block
 * widget editor, the classic Widgets screen, the Customizer, WP-CLI); one
 * row lists every widget.
 *
 * Data: `name`, `area`, `widgets` and `order_changed` (see
 * WidgetChanges\Builder).
 *
 * @package Scanfully\Events
 */
class WidgetsChanged extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'WidgetsChanged' );
	}
}
