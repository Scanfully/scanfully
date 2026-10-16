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
 * ever send a user's ID, display name and roles, and never an email
 * address. The display name may be the same as the login: WordPress uses
 * the login as the display name unless the user picks another one.
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
	 * The transient that counts user events: [minute, count].
	 */
	private const RATE_TRANSIENT = 'scanfully_user_events_rate';

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
			'name'  => self::safe_display_name( $user ),
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
	 * Capabilities that make an account more than a plain sign-up: an account
	 * with any of them can edit content, manage users or change the site.
	 */
	private const ELEVATED_CAPABILITIES = [
		'edit_posts',
		'list_users',
		'edit_users',
		'promote_users',
		'manage_options',
		'install_plugins',
	];

	/**
	 * Whether a user can do more than a plain sign-up (a subscriber or a
	 * shop customer) can, whatever role that takes.
	 *
	 * @param \WP_User $user The user.
	 *
	 * @return bool
	 */
	protected static function has_elevated_capabilities( \WP_User $user ): bool {
		foreach ( self::ELEVATED_CAPABILITIES as $capability ) {
			if ( user_can( $user, $capability ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Core capabilities from ELEVATED_CAPABILITIES.
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a change to a user is self-service: made outside WP-CLI with
	 * nobody signed in but (at most) the user themselves, such as a visitor
	 * registering or a customer account created at checkout.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return bool
	 */
	protected static function is_self_service( int $user_id ): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		$current_user_id = get_current_user_id();

		return 0 === $current_user_id || $user_id === $current_user_id;
	}

	/**
	 * Whether a new account is a plain sign-up: self-service and without any
	 * elevated capability. A sign-up that ends up with one (for instance
	 * because the default role for new users was set to Administrator) is
	 * never a plain sign-up.
	 *
	 * @param \WP_User $user The user.
	 *
	 * @return bool
	 */
	protected static function is_plain_sign_up( \WP_User $user ): bool {
		return self::is_self_service( (int) $user->ID ) && ! self::has_elevated_capabilities( $user );
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

		// One transient holding the current minute and its count, instead of
		// a new transient every minute.
		$minute = (int) floor( time() / MINUTE_IN_SECONDS );
		$state  = get_transient( self::RATE_TRANSIENT );
		$count  = is_array( $state ) && (int) ( $state[0] ?? -1 ) === $minute ? (int) ( $state[1] ?? 0 ) : 0;
		if ( $count >= $limit ) {
			return true;
		}

		set_transient( self::RATE_TRANSIENT, [ $minute, $count + 1 ], 2 * MINUTE_IN_SECONDS );

		return false;
	}
}
