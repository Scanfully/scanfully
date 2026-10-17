<?php
/**
 * The super admin revoked event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class SuperAdminRevoked
 *
 * Super admin rights revoked on a multisite network.
 *
 * @package Scanfully\Events
 */
class SuperAdminRevoked extends SuperAdminEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'SuperAdminRevoked', 'revoked_super_admin', 10, 1 );
	}
}
