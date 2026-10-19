<?php
/**
 * The ThemeUpdateFailed event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class ThemeUpdateFailed
 *
 * Sent by the Controller through the scanfully_theme_update_failed action.
 *
 * @package Scanfully\Events
 */
class ThemeUpdateFailed extends UpdateFailedEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'ThemeUpdateFailed', 'scanfully_theme_update_failed' );
	}
}
