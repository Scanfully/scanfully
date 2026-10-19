<?php
/**
 * The super admin granted event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class SuperAdminGranted
 *
 * Super admin rights granted on a multisite network.
 *
 * @package Scanfully\Events
 */
class SuperAdminGranted extends SuperAdminEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'SuperAdminGranted', 'granted_super_admin', 10, 1 );
	}
}
