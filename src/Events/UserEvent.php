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
 * Base for the user created, deleted, role changed and login events. These
 * send the ID, display name and roles of the account the event is about.
 * They add that account's email address only when it has elevated rights
 * (any capability in ELEVATED_CAPABILITIES; for a role change, after the
 * change or before it, so an administrator who is demoted still shows who
 * it was). No other email address is ever sent: never for an account
 * without elevated rights, never for another account, and never in the
 * actor's name. The display name may be the same as the login: WordPress
 * uses the login as the display name unless the user picks another one.
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
	 * Longest user agent that is sent, in characters.
	 */
	private const MAX_USER_AGENT_LENGTH = 500;

	/**
	 * Headers a proxy or CDN uses for the visitor's address, in the order
	 * they are trusted.
	 */
	private const FORWARDED_HEADERS = [
		'HTTP_CF_CONNECTING_IP',
		'HTTP_X_REAL_IP',
		'HTTP_X_FORWARDED_FOR',
	];

	/**
	 * The ID, display name and roles of a user, with the email address when
	 * the account has elevated rights.
	 *
	 * @param \WP_User  $user     The user.
	 * @param bool|null $elevated Whether the account counts as elevated; null
	 *                            to check its current capabilities.
	 *
	 * @return array<string, mixed>
	 */
	protected static function describe_user( \WP_User $user, ?bool $elevated = null ): array {
		$data = [
			'id'   => (int) $user->ID,
			'name' => self::safe_display_name( $user ),
		];
		if ( $elevated ?? self::has_elevated_capabilities( $user ) ) {
			$data['email'] = (string) $user->user_email;
		}
		$data['roles'] = self::role_list( $user->roles );

		return $data;
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
	 * Whether any of these roles has elevated rights.
	 *
	 * @param array<int, string> $roles Role names.
	 *
	 * @return bool
	 */
	protected static function roles_have_elevated_capabilities( array $roles ): bool {
		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role instanceof \WP_Role ) {
				continue;
			}
			foreach ( self::ELEVATED_CAPABILITIES as $capability ) {
				if ( $role->has_cap( $capability ) ) {
					return true;
				}
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
	 * Where the current request came from: the connecting address (`ip`),
	 * the visitor's address as a proxy or CDN reports it (`forwarded_for`)
	 * and the browser (`user_agent`, at most 500 characters).
	 *
	 * @return array{ip: string, forwarded_for: string, user_agent: string}
	 */
	protected static function request_origin(): array {
		return [
			'ip'            => self::valid_ip( self::server_value( 'REMOTE_ADDR' ) ),
			'forwarded_for' => self::forwarded_for(),
			'user_agent'    => mb_substr( self::server_value( 'HTTP_USER_AGENT' ), 0, self::MAX_USER_AGENT_LENGTH ),
		];
	}

	/**
	 * The visitor's address as a proxy or CDN in front of the site reports
	 * it: the first address of the first of these headers that is present,
	 * when it is a valid IP address. The client can send these headers too,
	 * so this is what the visitor claims, not proof.
	 *
	 * @return string
	 */
	private static function forwarded_for(): string {
		foreach ( self::FORWARDED_HEADERS as $header ) {
			$value = self::server_value( $header );
			if ( '' === $value ) {
				continue;
			}

			$first = self::valid_ip( trim( explode( ',', $value )[0] ) );
			if ( '' !== $first ) {
				return $first;
			}
		}

		return '';
	}

	/**
	 * The value if it is a valid IP address, else ''.
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 */
	private static function valid_ip( string $value ): string {
		return false !== filter_var( $value, FILTER_VALIDATE_IP ) ? $value : '';
	}

	/**
	 * A request header or server value as plain text, or ''.
	 *
	 * @param string $key The $_SERVER key.
	 *
	 * @return string
	 */
	private static function server_value( string $key ): string {
		if ( ! isset( $_SERVER[ $key ] ) || ! is_string( $_SERVER[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
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
