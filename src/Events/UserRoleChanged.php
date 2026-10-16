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
	 * Whether a new user is being inserted. WordPress sets the first role of
	 * a new user before `user_register`; that is part of creating the user,
	 * not a role change.
	 *
	 * @var bool
	 */
	private static bool $creating_user = false;

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
		add_action( 'user_register', [ self::class, 'note_user_registered' ], 0, 0 );
	}

	/**
	 * Note whether the user being saved is a new user.
	 *
	 * @param mixed $data   The user data, returned unchanged.
	 * @param mixed $update Whether an existing user is updated.
	 *
	 * @return mixed
	 */
	public static function note_user_insert( $data, $update ) {
		self::$creating_user = ! $update;

		return $data;
	}

	/**
	 * The new user is in place; role changes from now on are real changes.
	 *
	 * @return void
	 */
	public static function note_user_registered(): void {
		self::$creating_user = false;
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

		$role = (string) ( $data[1] ?? '' );

		return array_merge(
			self::describe_user( $user ),
			[
				'roles'     => self::role_list( '' !== $role ? [ $role ] : [] ),
				'old_roles' => self::role_list( $data[2] ?? [] ),
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

		// The first role of a user that is being created.
		if ( self::$creating_user && [] === $old_roles ) {
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

		if ( ! get_userdata( $user_id ) instanceof \WP_User || self::is_rate_limited() ) {
			return false;
		}

		self::$fired[ $key ] = true;

		return true;
	}
}
