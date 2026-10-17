<?php
/**
 * The application password first used event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class AppPasswordFirstUsed
 *
 * The first time an application password of an account with elevated rights
 * signs a request in, with where the request came from. The hook fires on
 * every request signed with an application password; WordPress passes the
 * password's record as it was before this request, so a record without
 * `last_used` means this is the first use.
 *
 * @package Scanfully\Events
 */
class AppPasswordFirstUsed extends AppPasswordEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'AppPasswordFirstUsed', 'application_password_did_authenticate', 10, 2 );
	}

	/**
	 * Return before any other work (such as reading the connection options)
	 * unless the password was never used: this runs on every request signed
	 * with an application password.
	 *
	 * @param mixed ...$args The arguments passed to the action: user, record.
	 *
	 * @return void
	 */
	public function listener_callback( ...$args ): void {
		if ( ! is_array( $args[1] ?? null ) || ! empty( $args[1]['last_used'] ) ) {
			return;
		}

		parent::listener_callback( ...$args );
	}

	/**
	 * The authenticated user made the request.
	 *
	 * @param array $args The event data: user, record.
	 *
	 * @return object|null
	 */
	protected function get_actor( array $args ): ?object {
		return ( $args[0] ?? null ) instanceof \WP_User ? $args[0] : null;
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send: user, the password's record.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$user = self::elevated_owner( $data[0] ?? null );
		if ( null === $user ) {
			return [];
		}

		return array_merge(
			self::describe_user( $user, true ),
			self::describe_app_password( $data[1] ?? [] ),
			self::request_origin()
		);
	}

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The event data: user, the password's record.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		if ( ! is_array( $data[1] ?? null ) || ! empty( $data[1]['last_used'] ) ) {
			return false;
		}
		if ( null === self::elevated_owner( $data[0] ?? null ) ) {
			return false;
		}

		return ! self::is_rate_limited();
	}
}
