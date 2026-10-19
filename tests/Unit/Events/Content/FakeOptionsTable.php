<?php
/**
 * An in-memory options table for unit tests of the minute store.
 *
 * @package Scanfully\Tests\Unit\Events\Content
 */

namespace Scanfully\Tests\Unit\Events\Content;

use Brain\Monkey\Functions;

/**
 * Stands in for `$wpdb` and the options functions the MinuteStore uses:
 * add_option(), delete_option(), maybe_unserialize() and the one query
 * that finds options by name prefix.
 */
final class FakeOptionsTable {

	/**
	 * Table name, as `$wpdb->options`.
	 *
	 * @var string
	 */
	public string $options = 'wp_options';

	/**
	 * The stored options, in insertion order.
	 *
	 * @var array<string, mixed>
	 */
	public array $rows = [];

	/**
	 * Install it: `$wpdb` and the options functions.
	 *
	 * @return self
	 */
	public static function install(): self {
		$table           = new self();
		$GLOBALS['wpdb'] = $table;

		Functions\when( 'add_option' )->alias(
			static function ( string $name, $value ) use ( $table ): bool {
				if ( array_key_exists( $name, $table->rows ) ) {
					return false;
				}
				$table->rows[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $name ) use ( $table ): bool {
				if ( ! array_key_exists( $name, $table->rows ) ) {
					return false;
				}
				unset( $table->rows[ $name ] );
				return true;
			}
		);
		Functions\when( 'maybe_unserialize' )->returnArg();

		return $table;
	}

	/**
	 * Names of the stored options that start with a prefix.
	 *
	 * @param string $prefix The prefix.
	 *
	 * @return array<int, string>
	 */
	public function names( string $prefix ): array {
		return array_values( array_filter( array_keys( $this->rows ), static fn( string $name ): bool => 0 === strpos( $name, $prefix ) ) );
	}

	/**
	 * `$wpdb->esc_like()`.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * `$wpdb->prepare()` for the one LIKE query: keeps the pattern.
	 *
	 * @param string $query   The query.
	 * @param mixed  ...$args The arguments.
	 *
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		return (string) ( $args[0] ?? '' );
	}

	/**
	 * `$wpdb->get_results()` for the LIKE query: the options whose name
	 * matches the pattern, in insertion order.
	 *
	 * @param string $pattern The LIKE pattern prepare() kept.
	 *
	 * @return array<int, object>
	 */
	public function get_results( string $pattern ): array {
		$prefix = stripcslashes( substr( $pattern, 0, -1 ) );
		$rows   = [];
		foreach ( $this->names( $prefix ) as $name ) {
			$rows[] = (object) [
				'option_name'  => $name,
				'option_value' => $this->rows[ $name ],
			];
		}

		return $rows;
	}
}
