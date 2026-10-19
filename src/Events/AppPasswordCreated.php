<?php
/**
 * The application password created event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class AppPasswordCreated
 *
 * An application password created for an account with elevated rights. The
 * actor is whoever created it (the account itself or an administrator).
 *
 * @package Scanfully\Events
 */
class AppPasswordCreated extends AppPasswordEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'AppPasswordCreated', 'wp_create_application_password', 10, 2 );
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send: user ID, the new password's record.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$user = self::elevated_owner( $data[0] ?? 0 );
		if ( null === $user ) {
			return [];
		}

		return array_merge( self::describe_user( $user, true ), self::describe_app_password( $data[1] ?? [] ) );
	}

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The event data: user ID, the new password's record.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		if ( ! is_array( $data[1] ?? null ) || null === self::elevated_owner( $data[0] ?? 0 ) ) {
			return false;
		}

		return ! self::is_rate_limited();
	}
}
