<?php
/**
 * An in-memory options table for unit tests of the failed login counters.
 *
 * @package Scanfully\Tests\Unit\Events\FailedLogins
 */

namespace Scanfully\Tests\Unit\Events\FailedLogins;

/**
 * Stands in for `$wpdb` for the queries the failed login Store makes: the
 * atomic increment, reading one counter, reading by name prefix and deleting
 * by name prefix.
 */
final class FakeCounterTable {

	/**
	 * Table name, as `$wpdb->options`.
	 *
	 * @var string
	 */
	public string $options = 'wp_options';

	/**
	 * The stored counters by option name.
	 *
	 * @var array<string, int>
	 */
	public array $rows = [];

	/**
	 * The queries run, in order.
	 *
	 * @var array<int, string>
	 */
	public array $queries = [];

	/**
	 * Install it as `$wpdb`.
	 *
	 * @return self
	 */
	public static function install(): self {
		$table           = new self();
		$GLOBALS['wpdb'] = $table;

		return $table;
	}

	/**
	 * `$wpdb->prepare()`, for %s and %d.
	 *
	 * @param string $query The query.
	 * @param mixed  ...$args The values, or one array of them.
	 *
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$index = 0;

		return (string) preg_replace_callback(
			'/%[sd]/',
			static function ( array $match ) use ( &$index, $args ): string {
				$value = $args[ $index++ ] ?? '';
				return '%d' === $match[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
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
	 * `$wpdb->query()`: the increment and the deletes.
	 *
	 * @param string $sql The query.
	 *
	 * @return int|false
	 */
	public function query( string $sql ) {
		$this->queries[] = $sql;

		if ( 0 === strpos( $sql, 'INSERT INTO wp_options' ) ) {
			preg_match_all( "/\('([^']+)', '1', 'no'\)/", $sql, $matches );
			foreach ( $matches[1] as $name ) {
				$this->rows[ $name ] = ( $this->rows[ $name ] ?? 0 ) + 1;
			}
			return count( $matches[1] );
		}

		if ( 1 === preg_match( "/^DELETE FROM wp_options WHERE option_name LIKE '(.*)'$/", $sql, $match ) ) {
			$prefix  = self::like_prefix( $match[1] );
			$deleted = 0;
			foreach ( array_keys( $this->rows ) as $name ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					unset( $this->rows[ $name ] );
					++$deleted;
				}
			}
			return $deleted;
		}

		return false;
	}

	/**
	 * The prefix a quoted `LIKE 'prefix%'` pattern matches: the SQL quoting
	 * undone, then the LIKE escapes.
	 *
	 * @param string $quoted The pattern as it is in the query, without its quotes.
	 *
	 * @return string
	 */
	private static function like_prefix( string $quoted ): string {
		$pattern = stripslashes( substr( $quoted, 0, -1 ) );

		return str_replace( [ '\\_', '\\%', '\\\\' ], [ '_', '%', '\\' ], $pattern );
	}

	/**
	 * `$wpdb->get_var()`: one counter.
	 *
	 * @param string $sql The query.
	 *
	 * @return string|null
	 */
	public function get_var( string $sql ): ?string {
		$this->queries[] = $sql;

		return 1 === preg_match( "/option_name = '([^']+)'/", $sql, $match ) && isset( $this->rows[ $match[1] ] ) ? (string) $this->rows[ $match[1] ] : null;
	}

	/**
	 * `$wpdb->get_results()`: the counters by name prefix.
	 *
	 * @param string $sql The query.
	 *
	 * @return array<int, object>
	 */
	public function get_results( string $sql ): array {
		$this->queries[] = $sql;
		if ( 1 !== preg_match( "/option_name LIKE '(.*)'$/", $sql, $match ) ) {
			return [];
		}
		$prefix = self::like_prefix( $match[1] );

		$rows = [];
		foreach ( $this->rows as $name => $value ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				$rows[] = (object) [
					'option_name'  => $name,
					'option_value' => (string) $value,
				];
			}
		}

		return $rows;
	}
}
