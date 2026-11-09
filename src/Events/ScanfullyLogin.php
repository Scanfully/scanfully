<?php
/**
 * The Scanfully login event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

use Scanfully\Login\Controller as Login;

/**
 * Class ScanfullyLogin
 *
 * A Scanfully user signed in to wp-admin with one-click WP admin access: who
 * at Scanfully (the name the sign-in link carries), how they asked
 * (dashboard, api_token or mcp) and the WordPress user they were signed in
 * as, who is the event's actor. WordPress's own wp_login does not fire for
 * it (no password was checked), so this is the record of the login on the
 * site; it reaches the Activity Log and the Timeline like every event.
 *
 * @package Scanfully\Events
 */
class ScanfullyLogin extends Event {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'ScanfullyLogin', Login::ACTION_SIGNED_IN, 10, 2 );
	}

	/**
	 * The WordPress user signed in made the change.
	 *
	 * @param array $args The event data: user, login.
	 *
	 * @return object|null
	 */
	protected function get_actor( array $args ): ?object {
		return ( $args[0] ?? null ) instanceof \WP_User ? $args[0] : null;
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The event data: user, login (name, via).
	 *
	 * @return array{scanfully_user: string, via: string, wp_user_id: int, wp_user_login: string}|array{}
	 */
	public function get_post_body( array $data ): array {
		$user  = $data[0] ?? null;
		$login = $data[1] ?? null;
		if ( ! $user instanceof \WP_User || ! is_array( $login ) ) {
			return [];
		}

		return [
			'scanfully_user' => sanitize_text_field( (string) ( $login['name'] ?? '' ) ),
			'via'            => (string) ( $login['via'] ?? '' ),
			'wp_user_id'     => (int) $user->ID,
			'wp_user_login'  => (string) $user->user_login,
		];
	}

	/**
	 * A check if a event should fire: every login, which the login's own
	 * rate limit already bounds.
	 *
	 * @param  array $data The event data: user, login.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		return ( $data[0] ?? null ) instanceof \WP_User && is_array( $data[1] ?? null );
	}
}
