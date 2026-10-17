<?php
/**
 * The application password revoked event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class AppPasswordRevoked
 *
 * An application password of an account with elevated rights revoked.
 * "Revoke all" fires the hook once per password, so each one is its own
 * event.
 *
 * @package Scanfully\Events
 */
class AppPasswordRevoked extends AppPasswordEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'AppPasswordRevoked', 'wp_delete_application_password', 10, 2 );
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send: user ID, the password's record.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$user = self::elevated_owner( $data[0] ?? 0 );
		if ( null === $user ) {
			return [];
		}

		$item      = is_array( $data[1] ?? null ) ? $data[1] : [];
		$last_used = is_numeric( $item['last_used'] ?? null ) ? (int) $item['last_used'] : 0;

		return array_merge(
			self::describe_user( $user, true ),
			self::describe_app_password( $item ),
			[
				// RFC 3339 in UTC, or "" when the password was never used.
				'last_used' => $last_used > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $last_used ) : '',
			]
		);
	}

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The event data: user ID, the password's record.
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
