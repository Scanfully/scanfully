<?php
/**
 * The CoreUpdateFailed event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class CoreUpdateFailed
 *
 * Sent by the Controller through the scanfully_core_update_failed action.
 *
 * @package Scanfully\Events
 */
class CoreUpdateFailed extends UpdateFailedEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'CoreUpdateFailed', 'scanfully_core_update_failed' );
	}
}
