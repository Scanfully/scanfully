<?php
/**
 * The user deleted event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class UserDeleted
 *
 * Sent on `delete_user`, which runs before the user is removed, so the
 * user's name and roles can still be read.
 *
 * @package Scanfully\Events
 */
class UserDeleted extends UserEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'UserDeleted', 'delete_user', 10, 1 );
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send: the user ID.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$user = get_userdata( (int) ( $data[0] ?? 0 ) );

		return $user instanceof \WP_User ? self::describe_user( $user ) : [];
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
