<?php
/**
 * The user role changed event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class UserRoleChanged
 *
 * A user's role set to another one (the role on the user's profile, the
 * "Change role to" bulk action, WP-CLI, plugins calling `set_role()`).
 *
 * @package Scanfully\Events
 */
class UserRoleChanged extends UserEvent {

	/**
	 * The login of the new user being inserted, or ''. WordPress sets the
	 * first role of a new user before `user_register`; that is part of
	 * creating the user, not a role change. Keyed by login, so an insert that
	 * fails after this point can't hide a later role change of another user.
	 *
	 * @var string
	 */
	private static string $creating_login = '';

	/**
	 * Users created in this request, by ID. Other plugins (on
	 * `user_register`) and multisite (adding the new user to the site) often
	 * set the role of a new user right after it is created; for a plain
	 * sign-up that is part of signing up, not a role change.
	 *
	 * @var array<int, bool>
	 */
	private static array $created_user_ids = [];

	/**
	 * Role changes already reported in this request.
	 *
	 * @var array<string, bool>
	 */
	private static array $fired = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'UserRoleChanged', 'set_user_role', 10, 3 );

		add_filter( 'wp_pre_insert_user_data', [ self::class, 'note_user_insert' ], 10, 2 );
		add_action( 'user_register', [ self::class, 'note_user_registered' ], 0, 1 );
	}

	/**
	 * Note the login of a new user that is about to be inserted.
	 *
	 * @param mixed $data   The user data, returned unchanged.
	 * @param mixed $update Whether an existing user is updated.
	 *
	 * @return mixed
	 */
	public static function note_user_insert( $data, $update ) {
		self::$creating_login = ! $update && is_array( $data ) ? (string) ( $data['user_login'] ?? '' ) : '';

		return $data;
	}

	/**
	 * The new user is in place: remember it was created in this request.
	 *
	 * @param mixed $user_id The new user's ID.
	 *
	 * @return void
	 */
	public static function note_user_registered( $user_id = 0 ): void {
		self::$creating_login = '';
		if ( is_numeric( $user_id ) && (int) $user_id > 0 ) {
			self::$created_user_ids[ (int) $user_id ] = true;
		}
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send: user ID, new role, old roles.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$user = get_userdata( (int) ( $data[0] ?? 0 ) );
		if ( ! $user instanceof \WP_User ) {
			return [];
		}

		$role      = (string) ( $data[1] ?? '' );
		$old_roles = self::role_list( $data[2] ?? [] );

		// Elevated after the change, or before it: an administrator who is
		// demoted still shows who it was.
		$elevated = self::has_elevated_capabilities( $user ) || self::roles_have_elevated_capabilities( $old_roles );

		return array_merge(
			self::describe_user( $user, $elevated ),
			[
				'roles'     => self::role_list( '' !== $role ? [ $role ] : [] ),
				'old_roles' => $old_roles,
			]
		);
	}

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The event data: user ID, new role, old roles.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		$user_id   = (int) ( $data[0] ?? 0 );
		$role      = (string) ( $data[1] ?? '' );
		$old_roles = self::role_list( $data[2] ?? [] );

		if ( $user_id < 1 ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		// The first role of a user that is being created; only once.
		if ( [] === $old_roles && '' !== self::$creating_login && $user->user_login === self::$creating_login ) {
			self::$creating_login = '';
			return false;
		}

		$roles = self::role_list( '' !== $role ? [ $role ] : [] );
		sort( $old_roles );
		if ( $roles === $old_roles ) {
			return false;
		}

		$key = $user_id . '|' . $role . '|' . implode( ',', $old_roles );
		if ( isset( self::$fired[ $key ] ) ) {
			return false;
		}

		// A role set right after a plain sign-up, by another plugin or by
		// multisite adding the new user to the site.
		if ( isset( self::$created_user_ids[ $user_id ] ) && self::is_plain_sign_up( $user ) ) {
			return false;
		}

		if ( self::is_rate_limited() ) {
			return false;
		}

		self::$fired[ $key ] = true;

		return true;
	}
}
