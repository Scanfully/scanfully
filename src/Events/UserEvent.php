<?php
/**
 * The user event base class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class UserEvent
 *
 * Base for the user created, deleted and role changed events. These only
 * ever send a user's ID, display name and roles: never the email address or
 * the login.
 *
 * @package Scanfully\Events
 */
abstract class UserEvent extends Event {

	/**
	 * Default maximum number of user events per minute, so bulk changes
	 * (deleting hundreds of spam accounts, a role sync) can't queue thousands
	 * of jobs. Filterable via `scanfully_user_events_per_minute`.
	 */
	private const MAX_EVENTS_PER_MINUTE = 30;

	/**
	 * The ID, display name and roles of a user.
	 *
	 * @param \WP_User $user The user.
	 *
	 * @return array{id: int, name: string, roles: array<int, string>}
	 */
	protected static function describe_user( \WP_User $user ): array {
		return [
			'id'    => (int) $user->ID,
			'name'  => self::display_name( $user ),
			'roles' => self::role_list( $user->roles ),
		];
	}

	/**
	 * A list of role names.
	 *
	 * @param mixed $roles Roles, as WordPress passes them.
	 *
	 * @return array<int, string>
	 */
	protected static function role_list( $roles ): array {
		$list = [];
		foreach ( (array) $roles as $role ) {
			if ( is_string( $role ) && '' !== $role ) {
				$list[] = $role;
			}
		}

		return $list;
	}

	/**
	 * The user's display name. A display name that is an email address
	 * (common when people sign up with their email as their username) is
	 * replaced by their first and last name, or left empty.
	 *
	 * @param \WP_User $user The user.
	 *
	 * @return string
	 */
	private static function display_name( \WP_User $user ): string {
		$name = trim( (string) $user->display_name );
		if ( ! is_email( $name ) ) {
			return $name;
		}

		$name = trim( $user->first_name . ' ' . $user->last_name );

		return is_email( $name ) ? '' : $name;
	}

	/**
	 * Count this event against the per-minute cap. Returns true when the cap
	 * is reached and the event should be dropped.
	 *
	 * @return bool
	 */
	protected static function is_rate_limited(): bool {
		$limit = (int) apply_filters( 'scanfully_user_events_per_minute', self::MAX_EVENTS_PER_MINUTE );
		if ( $limit < 1 ) {
			return false;
		}

		$bucket = 'scanfully_user_events_' . (int) floor( time() / MINUTE_IN_SECONDS );
		$count  = (int) get_transient( $bucket );
		if ( $count >= $limit ) {
			return true;
		}

		set_transient( $bucket, $count + 1, 2 * MINUTE_IN_SECONDS );

		return false;
	}
}
