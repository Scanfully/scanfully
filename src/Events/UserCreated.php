<?php
/**
 * The user created event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class UserCreated
 *
 * @package Scanfully\Events
 */
class UserCreated extends UserEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'UserCreated', 'user_register', 10, 1 );
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
		// Imports create the authors of the imported posts; they aren't
		// individual changes.
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return false;
		}

		$user = get_userdata( (int) ( $data[0] ?? 0 ) );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		/**
		 * Filters whether a new user is reported to Scanfully. By default,
		 * sign-ups (a visitor registering, a customer account created at
		 * checkout) aren't: only users created by someone signed in or with
		 * WP-CLI, and new users with any other role.
		 *
		 * @param bool     $should_fire Whether to report the new user.
		 * @param \WP_User $user        The new user.
		 */
		if ( ! apply_filters( 'scanfully_user_created_should_fire', ! self::is_sign_up( $user ), $user ) ) {
			return false;
		}

		return ! self::is_rate_limited();
	}

	/**
	 * Whether the user signed up themselves: created without someone else
	 * signed in, outside WP-CLI, with no role but the default role for new
	 * users or WooCommerce's customer role.
	 *
	 * @param \WP_User $user The new user.
	 *
	 * @return bool
	 */
	private static function is_sign_up( \WP_User $user ): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		$creator = get_current_user_id();
		if ( 0 !== $creator && (int) $user->ID !== $creator ) {
			return false;
		}

		$sign_up_roles = [ (string) get_option( 'default_role' ), 'customer' ];

		return [] === array_diff( self::role_list( $user->roles ), $sign_up_roles );
	}
}
