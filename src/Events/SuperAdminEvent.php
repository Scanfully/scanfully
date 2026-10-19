<?php
/**
 * The super admin event base class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class SuperAdminEvent
 *
 * Base for super admin rights granted or revoked (multisite). A super admin
 * has elevated rights by definition, before a revoke or after a grant, so
 * these are always sent, with the account's email address.
 *
 * @package Scanfully\Events
 */
abstract class SuperAdminEvent extends UserEvent {

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send: the user ID.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$user = get_userdata( (int) ( $data[0] ?? 0 ) );

		return $user instanceof \WP_User ? self::describe_user( $user, true ) : [];
	}

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The event data: the user ID.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		if ( ! get_userdata( (int) ( $data[0] ?? 0 ) ) instanceof \WP_User ) {
			return false;
		}

		return ! self::is_rate_limited();
	}
}
