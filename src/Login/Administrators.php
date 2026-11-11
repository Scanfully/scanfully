<?php
/**
 * The login administrators class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Login;

use Scanfully\Events\Event;

/**
 * The accounts a sign-in link from Scanfully may sign in as: the users with
 * the administrator role, or on a multisite network its super admins. The
 * capabilities report lists them (id, login and display name, at most 50,
 * by id) so the site's settings in Scanfully can offer them under Sign in
 * as, and the login checks the user a link names against the same rule.
 */
final class Administrators {

	/**
	 * The most administrators the capabilities report lists.
	 */
	public const MAX = 50;

	/**
	 * The administrators, by id, at most MAX of them.
	 *
	 * @return array<int, array{id: int, login: string, display_name: string}>
	 */
	public static function list(): array {
		// No one can be signed in on a monitoring-only connection (a site of
		// a multisite network), so there is no one to report.
		if ( \Scanfully\Connect\Multisite::monitoring_only() ) {
			return [];
		}

		$list = [];
		foreach ( is_multisite() ? self::super_admins() : self::site_admins() as $user ) {
			$list[] = [
				'id'           => (int) $user->ID,
				'login'        => (string) $user->user_login,
				// Never an email address, as in every event the plugin sends.
				'display_name' => Event::safe_display_name( $user ),
			];
		}

		return $list;
	}

	/**
	 * Whether a sign-in link may sign in as this user: an administrator of
	 * the site, or on a multisite network a super admin.
	 *
	 * @param \WP_User $user The user.
	 *
	 * @return bool
	 */
	public static function is_administrator( \WP_User $user ): bool {
		if ( is_multisite() ) {
			return is_super_admin( (int) $user->ID );
		}

		return in_array( 'administrator', (array) $user->roles, true );
	}

	/**
	 * The users with the administrator role, by id.
	 *
	 * @return \WP_User[]
	 */
	private static function site_admins(): array {
		$users = get_users(
			[
				'role'        => 'administrator',
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'number'      => self::MAX,
				'count_total' => false,
			]
		);

		return array_values(
			array_filter(
				is_array( $users ) ? $users : [],
				static function ( $user ): bool {
					return $user instanceof \WP_User;
				}
			)
		);
	}

	/**
	 * The super admins of the network, by id.
	 *
	 * @return \WP_User[]
	 */
	private static function super_admins(): array {
		$users = [];
		foreach ( get_super_admins() as $login ) {
			$user = is_string( $login ) ? get_user_by( 'login', $login ) : false;
			if ( $user instanceof \WP_User ) {
				$users[ (int) $user->ID ] = $user;
			}
		}
		ksort( $users );

		return array_slice( array_values( $users ), 0, self::MAX );
	}
}
