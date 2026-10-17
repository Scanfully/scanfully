<?php
/**
 * The email changed event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class EmailChanged
 *
 * The email address of an account with elevated rights (after the change)
 * changed. `email` is the new address, `old_email` the old one.
 *
 * @package Scanfully\Events
 */
class EmailChanged extends UserEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// profile_update: user ID, the user before the update.
		parent::__construct( 'EmailChanged', 'profile_update', 10, 2 );
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send: user ID, the user before the update.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$user = get_userdata( (int) ( $data[0] ?? 0 ) );
		if ( ! $user instanceof \WP_User ) {
			return [];
		}

		return array_merge(
			self::describe_user( $user, true ),
			[ 'old_email' => self::old_email( $data[1] ?? null ) ]
		);
	}

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The event data: user ID, the user before the update.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		$user = get_userdata( (int) ( $data[0] ?? 0 ) );
		if ( ! $user instanceof \WP_User || ! is_object( $data[1] ?? null ) || ! isset( $data[1]->user_email ) ) {
			return false;
		}

		if ( self::old_email( $data[1] ) === (string) $user->user_email ) {
			return false;
		}

		if ( ! self::has_elevated_capabilities( $user ) ) {
			return false;
		}

		return ! self::is_rate_limited();
	}

	/**
	 * The email address before the update. WordPress slashes it before it
	 * passes the old user data to `profile_update`.
	 *
	 * @param mixed $before The user before the update.
	 *
	 * @return string
	 */
	private static function old_email( $before ): string {
		return is_object( $before ) && isset( $before->user_email ) ? (string) wp_unslash( $before->user_email ) : '';
	}
}
