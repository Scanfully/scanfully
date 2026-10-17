<?php
/**
 * The password changed event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class PasswordChanged
 *
 * The password of an account with elevated rights changed. Never sends the
 * password or its hash, only how it was changed (`via`):
 * - `reset`: with a password reset link (`password_reset`, then
 *   `wp_set_password`, then `after_password_reset`);
 * - `profile`: through a profile update (`profile_update` with another hash:
 *   the profile screen, `wp_update_user()`);
 * - `other`: `wp_set_password()` called directly (WP-CLI, plugins).
 *
 * These hooks overlap and their order differs between WordPress versions
 * (newer versions fire `wp_set_password` from `wp_update_user()` before
 * `profile_update`, and for every new user before `user_register`). So a
 * change is only noted while the request runs, and each user's change is
 * sent once, at the end of the request, with the most specific `via`.
 * Nothing is sent for a user created in the same request, or when logging
 * in only stored the same password with a newer hash.
 *
 * @package Scanfully\Events
 */
class PasswordChanged extends UserEvent {

	/**
	 * The action that sends a noted change: user ID, via.
	 */
	public const ACTION = 'scanfully_password_changed';

	/**
	 * How specific each `via` is; a more specific one wins.
	 */
	private const VIA_RANK = [
		'other'   => 1,
		'profile' => 2,
		'reset'   => 3,
	];

	/**
	 * Password changes noted in this request: user ID to via.
	 *
	 * @var array<int, string>
	 */
	private static array $pending = [];

	/**
	 * Users whose password is being reset with a reset link, by ID.
	 *
	 * @var array<int, bool>
	 */
	private static array $resetting = [];

	/**
	 * Users created in this request, by ID.
	 *
	 * @var array<int, bool>
	 */
	private static array $created = [];

	/**
	 * Whether a login is being checked. WordPress stores the password again
	 * with a newer hash when a login uses an old one; that isn't a change.
	 *
	 * @var bool
	 */
	private static bool $authenticating = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'PasswordChanged', self::ACTION, 10, 2 );

		add_action( 'user_register', [ self::class, 'note_user_created' ], 0, 1 );
		add_action( 'password_reset', [ self::class, 'note_reset_started' ], 10, 1 );
		add_action( 'after_password_reset', [ self::class, 'note_reset_done' ], 10, 1 );
		add_action( 'profile_update', [ self::class, 'note_profile_update' ], 10, 2 );
		// Only the user ID is used: the plain password is never kept.
		add_action( 'wp_set_password', [ self::class, 'note_password_set' ], 10, 2 );
		add_filter( 'authenticate', [ self::class, 'note_authentication_started' ], 0, 1 );
		add_filter( 'authenticate', [ self::class, 'note_authentication_done' ], PHP_INT_MAX, 1 );
		add_action( 'shutdown', [ self::class, 'flush' ], 0, 0 );
	}

	/**
	 * Note a password change for a user.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $via     How the password was changed.
	 *
	 * @return void
	 */
	private static function note( int $user_id, string $via ): void {
		if ( $user_id < 1 ) {
			return;
		}

		$current = self::$pending[ $user_id ] ?? '';
		if ( '' === $current || self::VIA_RANK[ $via ] > self::VIA_RANK[ $current ] ) {
			self::$pending[ $user_id ] = $via;
		}
	}

	/**
	 * `wp_set_password`: a password was stored.
	 *
	 * @param mixed $password The plain password; ignored.
	 * @param mixed $user_id  The user ID.
	 *
	 * @return void
	 */
	public static function note_password_set( $password, $user_id ): void {
		if ( self::$authenticating || ! is_numeric( $user_id ) ) {
			return;
		}

		$user_id = (int) $user_id;
		self::note( $user_id, isset( self::$resetting[ $user_id ] ) ? 'reset' : 'other' );
	}

	/**
	 * `profile_update`: note a change when the password hash differs.
	 *
	 * @param mixed $user_id The user ID.
	 * @param mixed $before  The user before the update.
	 *
	 * @return void
	 */
	public static function note_profile_update( $user_id, $before ): void {
		$user = is_numeric( $user_id ) ? get_userdata( (int) $user_id ) : false;
		if ( ! $user instanceof \WP_User || ! is_object( $before ) || ! isset( $before->user_pass ) ) {
			return;
		}

		if ( (string) $before->user_pass !== (string) $user->user_pass ) {
			self::note( (int) $user_id, 'profile' );
		}
	}

	/**
	 * `password_reset`: a reset with a reset link starts.
	 *
	 * @param mixed $user The user.
	 *
	 * @return void
	 */
	public static function note_reset_started( $user ): void {
		if ( $user instanceof \WP_User ) {
			self::$resetting[ (int) $user->ID ] = true;
		}
	}

	/**
	 * `after_password_reset`: the reset is done (also noted here for sites
	 * whose own wp_set_password() doesn't fire its action).
	 *
	 * @param mixed $user The user.
	 *
	 * @return void
	 */
	public static function note_reset_done( $user ): void {
		if ( $user instanceof \WP_User ) {
			self::note( (int) $user->ID, 'reset' );
			unset( self::$resetting[ (int) $user->ID ] );
		}
	}

	/**
	 * `user_register`: setting the first password of a new user isn't a
	 * change.
	 *
	 * @param mixed $user_id The new user's ID.
	 *
	 * @return void
	 */
	public static function note_user_created( $user_id ): void {
		if ( is_numeric( $user_id ) ) {
			self::$created[ (int) $user_id ] = true;
		}
	}

	/**
	 * A login is checked from here on.
	 *
	 * @param mixed $user The authentication result so far, unchanged.
	 *
	 * @return mixed
	 */
	public static function note_authentication_started( $user ) {
		self::$authenticating = true;

		return $user;
	}

	/**
	 * The login check is done.
	 *
	 * @param mixed $user The authentication result, unchanged.
	 *
	 * @return mixed
	 */
	public static function note_authentication_done( $user ) {
		self::$authenticating = false;

		return $user;
	}

	/**
	 * Send the password changes noted in this request, one per user.
	 *
	 * @return void
	 */
	public static function flush(): void {
		$pending       = self::$pending;
		self::$pending = [];

		foreach ( $pending as $user_id => $via ) {
			if ( ! isset( self::$created[ $user_id ] ) ) {
				do_action( 'scanfully_password_changed', $user_id, $via );
			}
		}
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send: user ID, via.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$user = get_userdata( (int) ( $data[0] ?? 0 ) );
		if ( ! $user instanceof \WP_User ) {
			return [];
		}

		$via = is_string( $data[1] ?? null ) && isset( self::VIA_RANK[ $data[1] ] ) ? $data[1] : 'other';

		return array_merge( self::describe_user( $user, true ), [ 'via' => $via ] );
	}

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The event data: user ID, via.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		$user = get_userdata( (int) ( $data[0] ?? 0 ) );
		if ( ! $user instanceof \WP_User || ! self::has_elevated_capabilities( $user ) ) {
			return false;
		}

		return ! self::is_rate_limited();
	}
}
