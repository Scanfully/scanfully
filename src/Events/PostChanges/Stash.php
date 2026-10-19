<?php
/**
 * The post change stash class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\PostChanges;

/**
 * Class Stash
 *
 * Holds what a post event's background job needs but Action Scheduler can't
 * carry in its arguments (they are limited to 8000 characters): the content
 * before and after a save on a post without revisions, or a large set of
 * changes. Stored as a transient that expires after a day, so a job that
 * never runs leaves nothing behind.
 *
 * @package Scanfully\Events\PostChanges
 */
final class Stash {

	/**
	 * Prefix of the transient names.
	 */
	private const PREFIX = 'scanfully_post_change_';

	/**
	 * Store a value; returns its key, or '' when it could not be stored.
	 *
	 * @param array<string, mixed> $value The value.
	 *
	 * @return string
	 */
	public static function put( array $value ): string {
		$key = str_replace( '-', '', wp_generate_uuid4() );

		return set_transient( self::PREFIX . $key, $value, DAY_IN_SECONDS ) ? $key : '';
	}

	/**
	 * A stored value, or null when it is gone.
	 *
	 * @param string $key The key.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( string $key ): ?array {
		if ( ! self::is_key( $key ) ) {
			return null;
		}

		$value = get_transient( self::PREFIX . $key );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Remove a stored value.
	 *
	 * @param string $key The key.
	 *
	 * @return void
	 */
	public static function delete( string $key ): void {
		if ( self::is_key( $key ) ) {
			delete_transient( self::PREFIX . $key );
		}
	}

	/**
	 * Whether a key is one put() makes.
	 *
	 * @param string $key The key.
	 *
	 * @return bool
	 */
	private static function is_key( string $key ): bool {
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $key );
	}
}
