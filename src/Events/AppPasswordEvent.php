<?php
/**
 * The application password event base class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class AppPasswordEvent
 *
 * Base for the application password events. They describe the password by
 * its name and its WordPress ID (the uuid), never by the password itself or
 * its hash, and only for accounts with elevated rights.
 *
 * @package Scanfully\Events
 */
abstract class AppPasswordEvent extends UserEvent {

	/**
	 * The account the application password belongs to, when it has elevated
	 * rights.
	 *
	 * @param mixed $user The user ID or user.
	 *
	 * @return \WP_User|null
	 */
	protected static function elevated_owner( $user ): ?\WP_User {
		if ( ! $user instanceof \WP_User ) {
			$user = is_numeric( $user ) ? get_userdata( (int) $user ) : null;
		}

		return $user instanceof \WP_User && self::has_elevated_capabilities( $user ) ? $user : null;
	}

	/**
	 * The name and ID of an application password.
	 *
	 * @param mixed $item The application password record.
	 *
	 * @return array{app_name: string, app_id: string}
	 */
	protected static function describe_app_password( $item ): array {
		$item = is_array( $item ) ? $item : [];

		return [
			'app_name' => self::plain_text( is_scalar( $item['name'] ?? null ) ? $item['name'] : '' ),
			'app_id'   => is_scalar( $item['uuid'] ?? null ) ? (string) $item['uuid'] : '',
		];
	}
}
