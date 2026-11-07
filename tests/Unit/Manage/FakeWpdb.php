<?php
/**
 * An in-memory options table for the Manage ledger tests.
 *
 * @package Scanfully\Tests\Unit\Manage
 */

namespace Scanfully\Tests\Unit\Manage;

/**
 * Stands in for $wpdb with the statements the ledger sends: an INSERT
 * IGNORE claim, a compare-and-swap UPDATE, reads by name and prefix, and
 * deletes. prepare() hands the statement and its values through as they are.
 */
final class FakeWpdb {

	/**
	 * The options table's name.
	 *
	 * @var string
	 */
	public string $options = 'wp_options';

	/**
	 * The rows, by option name.
	 *
	 * @var array<string, string>
	 */
	public array $rows = [];

	/**
	 * The option ids, by option name, as the table hands them out.
	 *
	 * @var array<string, int>
	 */
	private array $ids = [];

	/**
	 * The last option id handed out.
	 *
	 * @var int
	 */
	private int $last_id = 0;

	/**
	 * Add a row as WordPress would, with the next option id.
	 *
	 * @param string $name  The option name.
	 * @param string $value The value.
	 *
	 * @return void
	 */
	public function add( string $name, string $value ): void {
		$this->rows[ $name ] = $value;
		$this->ids[ $name ]  = ++$this->last_id;
	}

	/**
	 * Escape LIKE wildcards, as wpdb does.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * The statement and its values, unformatted.
	 *
	 * @param string $sql  The statement.
	 * @param mixed  ...$args The values.
	 *
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	public function prepare( string $sql, ...$args ): array {
		return [ $sql, $args ];
	}

	/**
	 * Run a write.
	 *
	 * @param array{0: string, 1: array<int, mixed>} $prepared The prepared statement.
	 *
	 * @return int The rows changed.
	 */
	public function query( array $prepared ): int {
		[ $sql, $args ] = $prepared;
		if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
			if ( isset( $this->rows[ $args[0] ] ) ) {
				return 0;
			}
			$this->add( (string) $args[0], (string) $args[1] );
			return 1;
		}
		if ( 0 === strpos( $sql, 'UPDATE' ) ) {
			[ $value, $name, $old ] = $args;
			if ( ! isset( $this->rows[ $name ] ) || $this->rows[ $name ] !== $old ) {
				return 0;
			}
			$this->rows[ $name ] = (string) $value;
			return 1;
		}
		if ( 0 === strpos( $sql, 'DELETE' ) ) {
			$removed = 0;
			foreach ( $this->names( (string) $args[0] ) as $name ) {
				unset( $this->rows[ $name ] );
				++$removed;
			}
			return $removed;
		}

		return 0;
	}

	/**
	 * Read one value by name.
	 *
	 * @param array{0: string, 1: array<int, mixed>} $prepared The prepared statement.
	 *
	 * @return string|null
	 */
	public function get_var( array $prepared ): ?string {
		return $this->rows[ $prepared[1][0] ] ?? null;
	}

	/**
	 * Read a page of the rows of a prefix: after an option id, in id order,
	 * up to a limit.
	 *
	 * @param array{0: string, 1: array<int, mixed>} $prepared The prepared statement.
	 * @param string                                 $output   Unused.
	 *
	 * @return array<int, array{option_id: int, option_name: string, option_value: string}>
	 */
	public function get_results( array $prepared, string $output = '' ): array {
		unset( $output );
		[ $pattern, $after, $limit ] = $prepared[1];
		$out                         = [];
		foreach ( $this->names( (string) $pattern ) as $name ) {
			$id = $this->ids[ $name ] ?? 0;
			if ( $id > (int) $after ) {
				$out[ $id ] = [
					'option_id'    => $id,
					'option_name'  => $name,
					'option_value' => $this->rows[ $name ],
				];
			}
		}
		ksort( $out );
		return array_slice( array_values( $out ), 0, (int) $limit );
	}

	/**
	 * Delete a row by name.
	 *
	 * @param string                $table The table.
	 * @param array<string, string> $where The row's name.
	 *
	 * @return int
	 */
	public function delete( string $table, array $where ): int {
		unset( $table );
		if ( ! isset( $this->rows[ $where['option_name'] ] ) ) {
			return 0;
		}
		unset( $this->rows[ $where['option_name'] ] );
		return 1;
	}

	/**
	 * The names that match a LIKE pattern of a prefix and %.
	 *
	 * @param string $pattern The pattern.
	 *
	 * @return string[]
	 */
	private function names( string $pattern ): array {
		$prefix = stripslashes( substr( $pattern, 0, -1 ) );
		return array_values(
			array_filter(
				array_keys( $this->rows ),
				static function ( string $name ) use ( $prefix ): bool {
					return 0 === strpos( $name, $prefix );
				}
			)
		);
	}
}
