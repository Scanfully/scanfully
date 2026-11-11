<?php
/**
 * An in-memory multisite database for unit tests of the subsite report.
 *
 * @package Scanfully\Tests\Unit\Health
 */

namespace Scanfully\Tests\Unit\Health;

/**
 * Stands in for `$wpdb` with the queries Health\Subsites runs: a page of
 * the sites table by blog id, and the blogname and home options of some
 * sites from their own options tables, joined with UNION ALL.
 */
final class FakeNetworkDb {

	/**
	 * Table name, as `$wpdb->blogs`.
	 *
	 * @var string
	 */
	public string $blogs = 'wp_blogs';

	/**
	 * The last query's error, as `$wpdb->last_error`.
	 *
	 * @var string
	 */
	public string $last_error = '';

	/**
	 * The rows of the sites table, by blog id.
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $sites = [];

	/**
	 * Each site's options, by blog id and name.
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $options = [];

	/**
	 * Option tables that do not exist.
	 *
	 * @var string[]
	 */
	public array $missing = [];

	/**
	 * Whether a query of the sites table fails.
	 *
	 * @var bool
	 */
	public bool $blogs_fail = false;

	/**
	 * Whether a query of several option tables fails (tables on other
	 * database servers).
	 *
	 * @var bool
	 */
	public bool $union_fails = false;

	/**
	 * Whether every query of an option table fails.
	 *
	 * @var bool
	 */
	public bool $options_fail = false;

	/**
	 * The queries run.
	 *
	 * @var string[]
	 */
	public array $queries = [];

	/**
	 * Whether errors are suppressed.
	 *
	 * @var bool
	 */
	public bool $suppressed = false;

	/**
	 * Add a site.
	 *
	 * @param int                  $id      The blog id.
	 * @param string               $domain  The domain.
	 * @param string               $path    The path.
	 * @param array<string, mixed> $columns Other columns: network (site_id), public, archived, spam, deleted.
	 * @param array<string, mixed> $options The blogname and home options; null leaves one out.
	 *
	 * @return void
	 */
	public function add_site( int $id, string $domain, string $path = '/', array $columns = [], array $options = [] ): void {
		$this->sites[ $id ] = [
			'blog_id'  => (string) $id,
			'site_id'  => (string) ( $columns['site_id'] ?? 1 ),
			'domain'   => $domain,
			'path'     => $path,
			'public'   => (string) ( $columns['public'] ?? 1 ),
			'archived' => (string) ( $columns['archived'] ?? 0 ),
			'spam'     => (string) ( $columns['spam'] ?? 0 ),
			'deleted'  => (string) ( $columns['deleted'] ?? 0 ),
		];
		$defaults              = [
			'blogname' => 'Site ' . $id,
			'home'     => 'https://' . $domain . rtrim( $path, '/' ),
		];
		$this->options[ $id ] = array_filter( array_merge( $defaults, $options ), static fn( $value ): bool => null !== $value );
	}

	/**
	 * `$wpdb->prepare()`: %d and %s, quoted.
	 *
	 * @param string $query   The query.
	 * @param mixed  ...$args The values.
	 *
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
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
	 * `$wpdb->get_blog_prefix()`.
	 *
	 * @param int $id The blog id.
	 *
	 * @return string
	 */
	public function get_blog_prefix( int $id ): string {
		return 1 === $id ? 'wp_' : 'wp_' . $id . '_';
	}

	/**
	 * `$wpdb->suppress_errors()`.
	 *
	 * @param bool $suppress Whether to suppress errors.
	 *
	 * @return bool The setting before.
	 */
	public function suppress_errors( bool $suppress = true ): bool {
		$before           = $this->suppressed;
		$this->suppressed = $suppress;

		return $before;
	}

	/**
	 * `$wpdb->get_results()` with ARRAY_A.
	 *
	 * @param string $sql The query.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function get_results( string $sql ): array {
		$this->queries[]  = $sql;
		$this->last_error = '';

		if ( 1 === preg_match( '/^SELECT blog_id, domain, path, public, archived, spam, deleted FROM wp_blogs WHERE site_id = (\d+) AND blog_id > (\d+) ORDER BY blog_id ASC LIMIT (\d+)$/', $sql, $match ) ) {
			if ( $this->blogs_fail ) {
				$this->last_error = 'Lost connection';
				return [];
			}
			$network = $match[1];
			$after   = (int) $match[2];
			$rows    = array_filter(
				$this->sites,
				static fn( array $row ): bool => $row['site_id'] === $network && (int) $row['blog_id'] > $after
			);
			ksort( $rows );
			$rows = array_map(
				static function ( array $row ): array {
					unset( $row['site_id'] );
					return $row;
				},
				$rows
			);

			return array_slice( array_values( $rows ), 0, (int) $match[3] );
		}

		$rows  = [];
		$parts = explode( ' UNION ALL ', $sql );
		if ( $this->options_fail || ( $this->union_fails && count( $parts ) > 1 ) ) {
			$this->last_error = 'Table is on another server';
			return [];
		}
		foreach ( $parts as $part ) {
			if ( 1 !== preg_match( "/^SELECT (\d+) AS blog_id, CONVERT\(option_name USING utf8mb4\) AS option_name, CONVERT\(option_value USING utf8mb4\) AS option_value FROM `(\w+)` WHERE option_name IN \('blogname', 'home'\)$/", $part, $match ) ) {
				$this->last_error = 'Unexpected query: ' . $part;
				return [];
			}
			if ( in_array( $match[2], $this->missing, true ) ) {
				$this->last_error = "Table '" . $match[2] . "' doesn't exist";
				return [];
			}
			$id = (int) $match[1];
			if ( $this->get_blog_prefix( $id ) . 'options' !== $match[2] ) {
				$this->last_error = 'Wrong table for site ' . $id;
				return [];
			}
			foreach ( $this->options[ $id ] ?? [] as $name => $value ) {
				$rows[] = [
					'blog_id'      => (string) $id,
					'option_name'  => $name,
					'option_value' => $value,
				];
			}
		}

		return $rows;
	}

	/**
	 * The queries of the option tables run.
	 *
	 * @return string[]
	 */
	public function option_queries(): array {
		return array_values( array_diff( $this->queries, $this->blog_queries() ) );
	}

	/**
	 * The queries of the sites table run.
	 *
	 * @return string[]
	 */
	public function blog_queries(): array {
		return array_values( array_filter( $this->queries, static fn( string $sql ): bool => 0 === strpos( $sql, 'SELECT blog_id, domain' ) ) );
	}
}
