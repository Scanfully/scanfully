<?php
/**
 * An in-memory options table for unit tests of the PHP error store.
 *
 * @package Scanfully\Tests\Unit\Errors
 */

namespace Scanfully\Tests\Unit\Errors;

/**
 * Stands in for `$wpdb` with the queries Errors\Store runs on the options
 * table: reads by name and by prefix, counts taken off, rows removed.
 */
final class FakeStoreDb {

	/**
	 * Table name, as `$wpdb->options`.
	 *
	 * @var string
	 */
	public string $options = 'wp_options';

	/**
	 * The rows: name to value.
	 *
	 * @var array<string, string>
	 */
	public array $rows = [];

	/**
	 * `$wpdb->prepare()`: %d and %s, quoted.
	 *
	 * @param string $query   The query.
	 * @param mixed  ...$args The values.
	 *
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$index = 0;

		return (string) preg_replace_callback(
			'/%[ds]/',
			static function ( array $match ) use ( &$index, $args ): string {
				$value = $args[ $index++ ];
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
	 * Whether a name matches a LIKE pattern.
	 *
	 * @param string $name    The name.
	 * @param string $pattern The pattern.
	 *
	 * @return bool
	 */
	private static function like( string $name, string $pattern ): bool {
		$regex = '';
		$len   = strlen( $pattern );
		for ( $i = 0; $i < $len; $i++ ) {
			$char = $pattern[ $i ];
			if ( '\\' === $char && $i + 1 < $len ) {
				$regex .= preg_quote( $pattern[ ++$i ], '/' );
			} elseif ( '%' === $char ) {
				$regex .= '.*';
			} elseif ( '_' === $char ) {
				$regex .= '.';
			} else {
				$regex .= preg_quote( $char, '/' );
			}
		}

		return 1 === preg_match( '/^' . $regex . '$/s', $name );
	}

	/**
	 * `$wpdb->get_results()`: rows by name prefix, as objects.
	 *
	 * @param string $sql The query.
	 *
	 * @return array<int, object>
	 */
	public function get_results( string $sql ): array {
		if ( 1 !== preg_match( "/^SELECT option_name, option_value FROM wp_options WHERE option_name LIKE '(.*)'$/", $sql, $match ) ) {
			return [];
		}
		$out = [];
		foreach ( $this->rows as $name => $value ) {
			if ( self::like( $name, stripslashes( $match[1] ) ) ) {
				$out[] = (object) [
					'option_name'  => $name,
					'option_value' => $value,
				];
			}
		}

		return $out;
	}

	/**
	 * `$wpdb->get_col()`: names matching one of two patterns.
	 *
	 * @param string $sql The query.
	 *
	 * @return string[]
	 */
	public function get_col( string $sql ): array {
		if ( 1 !== preg_match( "/^SELECT option_name FROM wp_options WHERE option_name LIKE '(.*)' OR option_name LIKE '(.*)'$/", $sql, $match ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_keys( $this->rows ),
				static fn( string $name ): bool => self::like( $name, stripslashes( $match[1] ) ) || self::like( $name, stripslashes( $match[2] ) )
			)
		);
	}

	/**
	 * `$wpdb->get_var()`: a name or a value by name.
	 *
	 * @param string $sql The query.
	 *
	 * @return string|null
	 */
	public function get_var( string $sql ): ?string {
		if ( 1 === preg_match( "/^SELECT (option_name|option_value) FROM wp_options WHERE option_name = '(.*)'$/", $sql, $match ) && isset( $this->rows[ $match[2] ] ) ) {
			return 'option_name' === $match[1] ? $match[2] : $this->rows[ $match[2] ];
		}

		return null;
	}

	/**
	 * `$wpdb->query()`: counts taken off and rows removed.
	 *
	 * @param string $sql The query.
	 *
	 * @return int
	 */
	public function query( string $sql ): int {
		if ( 1 === preg_match( "/^UPDATE wp_options SET option_value = option_value - (\\d+) WHERE option_name = '(.*)'$/", $sql, $match ) ) {
			if ( ! isset( $this->rows[ $match[2] ] ) ) {
				return 0;
			}
			$this->rows[ $match[2] ] = (string) ( (int) $this->rows[ $match[2] ] - (int) $match[1] );
			return 1;
		}
		if ( 1 === preg_match( "/^DELETE FROM wp_options WHERE option_name = '([^']*)' AND CAST\\(option_value AS SIGNED\\) <= 0$/", $sql, $match ) ) {
			if ( isset( $this->rows[ $match[1] ] ) && (int) $this->rows[ $match[1] ] <= 0 ) {
				unset( $this->rows[ $match[1] ] );
				return 1;
			}
			return 0;
		}
		if ( 1 === preg_match( '/^DELETE FROM wp_options WHERE option_name IN \\((.*)\\)$/', $sql, $match ) ) {
			preg_match_all( "/'([^']*)'/", $match[1], $names );
			foreach ( $names[1] as $name ) {
				unset( $this->rows[ $name ] );
			}
			return 1;
		}
		if ( 1 === preg_match( "/^DELETE FROM wp_options WHERE option_name = '([^']*)'$/", $sql, $match ) ) {
			unset( $this->rows[ $match[1] ] );
			return 1;
		}
		if ( 1 === preg_match( "/^DELETE FROM wp_options WHERE option_name LIKE '(.*)' AND CAST\\(option_value AS (SIGNED|UNSIGNED)\\) (<=|<) (-?\\d+)$/", $sql, $match ) ) {
			foreach ( $this->rows as $name => $value ) {
				$below = '<=' === $match[3] ? (int) $value <= (int) $match[4] : (int) $value < (int) $match[4];
				if ( self::like( $name, stripslashes( $match[1] ) ) && $below ) {
					unset( $this->rows[ $name ] );
				}
			}
			return 1;
		}

		return 0;
	}
}
