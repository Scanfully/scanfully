<?php
/**
 * The user login event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class UserLogin
 *
 * A successful login of an account with elevated rights, with the address
 * and browser it came from. `wp_login` only fires for an interactive login
 * (the login form, or code calling wp_signon()), not when a cookie is
 * checked again, nor for the REST API, XML-RPC or application passwords.
 *
 * @package Scanfully\Events
 */
class UserLogin extends UserEvent {

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
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'UserLogin', 'wp_login', 10, 2 );
	}

	/**
	 * The user logging in made the change. At `wp_login` WordPress has not
	 * set the signed-in user yet.
	 *
	 * @param array $args The event data: login, user.
	 *
	 * @return object|null
	 */
	protected function get_actor( array $args ): ?object {
		return ( $args[1] ?? null ) instanceof \WP_User ? $args[1] : null;
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send: login, user.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$user = $data[1] ?? null;
		if ( ! $user instanceof \WP_User ) {
			return [];
		}

		return array_merge(
			self::describe_user( $user, true ),
			[
				'ip'            => self::valid_ip( self::server_value( 'REMOTE_ADDR' ) ),
				'forwarded_for' => self::forwarded_for(),
				'user_agent'    => mb_substr( self::server_value( 'HTTP_USER_AGENT' ), 0, self::MAX_USER_AGENT_LENGTH ),
			]
		);
	}

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The event data: login, user.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		$user = $data[1] ?? null;
		if ( ! $user instanceof \WP_User || ! self::has_elevated_capabilities( $user ) ) {
			return false;
		}

		return ! self::is_rate_limited();
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
}
