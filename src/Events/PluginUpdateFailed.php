<?php
/**
 * The PluginUpdateFailed event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class PluginUpdateFailed
 *
 * Sent by the Controller through the scanfully_plugin_update_failed action.
 *
 * @package Scanfully\Events
 */
class PluginUpdateFailed extends UpdateFailedEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'PluginUpdateFailed', 'scanfully_plugin_update_failed' );
	}
}
