<?php
/**
 * The user login event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class UserLogin
 *
 * A successful login of an account with elevated rights, with the address
 * and browser it came from. `wp_login` only fires for an interactive login
 * (the login form, or code calling wp_signon()), not when a cookie is
 * checked again, nor for the REST API, XML-RPC or application passwords.
 *
 * @package Scanfully\Events
 */
class UserLogin extends UserEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'UserLogin', 'wp_login', 10, 2 );
	}

	/**
	 * The user logging in made the change. At `wp_login` WordPress has not
	 * set the signed-in user yet.
	 *
	 * @param array $args The event data: login, user.
	 *
	 * @return object|null
	 */
	protected function get_actor( array $args ): ?object {
		return ( $args[1] ?? null ) instanceof \WP_User ? $args[1] : null;
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send: login, user.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$user = $data[1] ?? null;
		if ( ! $user instanceof \WP_User ) {
			return [];
		}

		return array_merge( self::describe_user( $user, true ), self::request_origin() );
	}

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The event data: login, user.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		$user = $data[1] ?? null;
		if ( ! $user instanceof \WP_User || ! self::has_elevated_capabilities( $user ) ) {
			return false;
		}

		return ! self::is_rate_limited();
	}
}
