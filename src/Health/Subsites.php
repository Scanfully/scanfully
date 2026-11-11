<?php
/**
 * The subsites class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Health;

use Scanfully\Connect\Network;
use Scanfully\Options\Controller as OptionsController;

/**
 * The sites of a multisite network, reported with the site data on the
 * network connection only, so the owner can choose in Scanfully which of
 * them to monitor: every site of the network by blog id, the main site
 * included, with its address, name and status, at most 10,000.
 *
 * The list is read with direct queries: the sites table in pages by blog id,
 * then each site's name and address from its own options table, a hundred
 * sites a query, so a network of 10,000 sites costs about 110 indexed
 * queries and no site's options end up in memory (switch_to_blog() with
 * get_option() would load every site's autoloaded options into the object
 * cache, and get_sites() would cache every site object). A query of a
 * hundred option tables that fails (a missing table, or tables a database
 * plugin such as HyperDB keeps on other servers) is split in halves until
 * the parts work; when it is the joining itself that fails, the rest is
 * read one site a query.
 *
 * The address is the site's home option as stored, as the plugin reports
 * its own address: what WordPress gives without the home_url filters and
 * the scheme of the request, which answer differently in wp-admin, REST and
 * cron. WordPress's own domain mapping stores the mapped address there.
 *
 * A change to a site of a network (created, changed or deleted, or its name
 * or address) sends a new site data report a minute later, from the main
 * site of that network.
 */
final class Subsites {

	/**
	 * The most sites reported; the report says when there were more.
	 */
	public const LIMIT = 10000;

	/**
	 * The longest name sent, in characters (the API takes 200).
	 */
	public const NAME_MAX = 200;

	/**
	 * The longest address sent, in bytes (the API takes 2,048).
	 */
	public const URL_MAX = 2048;

	/**
	 * Sites read from the sites table per query.
	 */
	private const PAGE = 1000;

	/**
	 * Option tables read per query.
	 */
	private const CHUNK = 100;

	/**
	 * The columns of a site whose change changes the report: site_id is its
	 * network.
	 */
	private const WATCHED = [ 'domain', 'path', 'public', 'archived', 'spam', 'deleted', 'site_id' ];

	/**
	 * Whether a query of several option tables fails on this install
	 * however few, while each table reads fine: the rest of the report is
	 * read one site a query.
	 *
	 * @var bool
	 */
	private static bool $single = false;

	/**
	 * Watch the sites of a network for changes. Nothing on a single site.
	 *
	 * @return void
	 */
	public static function setup(): void {
		if ( ! Network::multisite() ) {
			return;
		}
		// After WordPress set up the new site's tables (priority 10).
		add_action( 'wp_initialize_site', [ self::class, 'site_changed' ], 100 );
		add_action( 'wp_update_site', [ self::class, 'site_updated' ], 10, 2 );
		add_action( 'wp_delete_site', [ self::class, 'site_changed' ] );
		// A site's name and address live in its own options.
		add_action( 'update_option_blogname', [ self::class, 'option_changed' ], 10, 0 );
		add_action( 'update_option_home', [ self::class, 'option_changed' ], 10, 0 );
	}

	/**
	 * Whether the site data carries the network's sites: on the network
	 * connection only, never on a single site or a site connected on its
	 * own.
	 *
	 * @return bool
	 */
	public static function applies(): bool {
		return Network::multisite() && OptionsController::network_scope();
	}

	/**
	 * Schedule a site data report for the network of a site that was
	 * created or deleted.
	 *
	 * @param mixed $site The site.
	 *
	 * @return void
	 */
	public static function site_changed( $site ): void {
		self::schedule_report( self::network_of( $site ) );
	}

	/**
	 * Schedule a site data report when a site's row changed in a way the
	 * report shows: for its network, and for the network it left when it
	 * moved. WordPress also updates the row on every post published anywhere
	 * in the network (last_updated), which changes nothing here.
	 *
	 * @param mixed $new_site The site after the change.
	 * @param mixed $old_site The site before the change.
	 *
	 * @return void
	 */
	public static function site_updated( $new_site, $old_site ): void {
		if ( ! is_object( $new_site ) || ! is_object( $old_site ) ) {
			return;
		}
		foreach ( self::WATCHED as $field ) {
			if ( (string) ( $new_site->$field ?? '' ) !== (string) ( $old_site->$field ?? '' ) ) {
				$networks = array_unique( [ self::network_of( $new_site ), self::network_of( $old_site ) ] );
				foreach ( $networks as $network ) {
					self::schedule_report( $network );
				}
				return;
			}
		}
	}

	/**
	 * Schedule a site data report for the network of the current site, whose
	 * name or address changed.
	 *
	 * @return void
	 */
	public static function option_changed(): void {
		self::schedule_report( self::network_of( get_site() ) );
	}

	/**
	 * Schedule a site data report on the main site of a network a minute
	 * from now, when the network connection applies there: the plugin is
	 * network-activated and that network connected. One report is pending
	 * at a time: each change moves it on (the debounced site data sync).
	 *
	 * @param int $network The network's id; 0 for the network of this request.
	 *
	 * @return void
	 */
	public static function schedule_report( int $network = 0 ): void {
		if ( ! Network::multisite() ) {
			return;
		}
		$network = $network > 0 ? $network : (int) get_current_network_id();
		if ( ! Network::applies_on( $network ) ) {
			return;
		}
		if ( ! function_exists( 'as_schedule_single_action' ) || ! did_action( 'action_scheduler_init' ) ) {
			return;
		}
		$main = (int) get_main_site_id( $network );
		if ( $main <= 0 ) {
			return;
		}
		$switched = get_current_blog_id() !== $main;
		if ( $switched ) {
			switch_to_blog( $main );
		}
		try {
			\Scanfully\Cron\Controller::schedule_health_sync();
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * The network a site belongs to; 0 (this request's) when unknown.
	 *
	 * @param mixed $site The site.
	 *
	 * @return int
	 */
	private static function network_of( $site ): int {
		return is_object( $site ) && isset( $site->site_id ) ? (int) $site->site_id : 0;
	}

	/**
	 * The network's sites for the site data, or null when they are not
	 * reported: not on the network connection, or the sites could not be
	 * read (the API then keeps the list it has).
	 *
	 * @return array{subsites: list<array{blog_id: int, home_url: string, name: string, main: bool, public: bool, archived: bool, spam: bool, deleted: bool}>, subsites_truncated: bool}|null
	 */
	public static function report(): ?array {
		if ( ! self::applies() ) {
			return null;
		}

		self::$single = false;
		$main         = (int) get_main_site_id();
		$scheme       = 'https' === wp_parse_url( Controller::site_address(), PHP_URL_SCHEME ) ? 'https' : 'http';
		$sites        = [];
		$read         = 0;
		$truncated    = false;
		$after        = 0;
		do {
			$room  = self::LIMIT - count( $sites );
			$limit = min( self::PAGE, $room + 1 );
			$rows  = self::blogs( $after, $limit );
			if ( null === $rows ) {
				return null;
			}
			$full = count( $rows ) === $limit;
			if ( count( $rows ) > $room ) {
				$truncated = true;
				$rows      = array_slice( $rows, 0, $room );
			}
			foreach ( array_chunk( $rows, self::CHUNK ) as $chunk ) {
				$options = self::options( array_map( 'intval', array_column( $chunk, 'blog_id' ) ) );
				if ( [] === $options && self::CHUNK === count( $chunk ) ) {
					// Not one of a hundred sites read: the database fails,
					// not a table, and every other hundred would cost as many
					// queries for nothing.
					return null;
				}
				$read += count( $options );
				foreach ( $chunk as $row ) {
					$sites[] = self::entry( $row, $options[ (int) $row['blog_id'] ] ?? [], $main, $scheme );
				}
			}
			if ( [] !== $rows ) {
				$after = (int) $rows[ count( $rows ) - 1 ]['blog_id'];
			}
		} while ( $full && ! $truncated );

		// A network always has its main site, and its options read: a list
		// without names, the addresses all rebuilt, would replace a good one
		// in Scanfully over a passing failure.
		if ( [] === $sites || 0 === $read ) {
			return null;
		}

		return [
			'subsites'           => $sites,
			'subsites_truncated' => $truncated,
		];
	}

	/**
	 * One site of the report.
	 *
	 * @param array<string, mixed>  $row     The site's row of the sites table.
	 * @param array<string, string> $options The site's blogname and home options.
	 * @param int                   $main    The main site's blog id.
	 * @param string                $scheme  The scheme of an address made from the domain and path.
	 *
	 * @return array{blog_id: int, home_url: string, name: string, main: bool, public: bool, archived: bool, spam: bool, deleted: bool}
	 */
	private static function entry( array $row, array $options, int $main, string $scheme ): array {
		$id   = (int) $row['blog_id'];
		$home = untrailingslashit( trim( $options['home'] ?? '' ) );
		if ( ! self::is_address( $home ) ) {
			// No usable home option (a site whose tables are missing, or a
			// value the API would refuse): its address in the sites table.
			$home = untrailingslashit( $scheme . '://' . $row['domain'] . $row['path'] );
		}

		return [
			'blog_id'  => $id,
			'home_url' => $home,
			'name'     => self::name( $options['blogname'] ?? '' ),
			'main'     => $id === $main,
			// Below 0 is a privacy plugin's "members only" and the like.
			'public'   => (int) $row['public'] > 0,
			'archived' => '1' === (string) $row['archived'],
			'spam'     => '1' === (string) $row['spam'],
			'deleted'  => '1' === (string) $row['deleted'],
		];
	}

	/**
	 * Whether a home option is an address the API takes: http or https,
	 * with a host, at most URL_MAX bytes.
	 *
	 * @param string $home The home option.
	 *
	 * @return bool
	 */
	private static function is_address( string $home ): bool {
		if ( '' === $home || strlen( $home ) > self::URL_MAX ) {
			return false;
		}
		$parts = wp_parse_url( $home );

		return is_array( $parts )
			&& in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http', 'https' ], true )
			&& '' !== ( $parts['host'] ?? '' );
	}

	/**
	 * A site's name as people read it: WordPress stores it HTML-escaped. No
	 * control characters, at most NAME_MAX characters.
	 *
	 * @param string $name The blogname option.
	 *
	 * @return string
	 */
	public static function name( string $name ): string {
		$name = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( 1 !== preg_match( '//u', $name ) ) {
			$name = function_exists( 'mb_convert_encoding' ) ? (string) mb_convert_encoding( $name, 'UTF-8', 'UTF-8' ) : '';
		}
		$name = trim( (string) preg_replace( '/[\x{0}-\x{1F}\x{7F}-\x{9F}]+/u', ' ', $name ) );

		return mb_strlen( $name ) > self::NAME_MAX ? rtrim( mb_substr( $name, 0, self::NAME_MAX ) ) : $name;
	}

	/**
	 * A page of the network's rows of the sites table, by blog id.
	 *
	 * @param int $after The blog id the page starts after.
	 * @param int $limit The most rows.
	 *
	 * @return array<int, array<string, mixed>>|null Null when the query failed.
	 */
	private static function blogs( int $after, int $limit ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One pass over the sites table, in pages, without caching every site.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT blog_id, domain, path, public, archived, spam, deleted FROM {$wpdb->blogs} WHERE site_id = %d AND blog_id > %d ORDER BY blog_id ASC LIMIT %d",
				get_current_network_id(),
				$after,
				$limit
			),
			ARRAY_A
		);
		if ( self::query_failed() || ! is_array( $rows ) ) {
			return null;
		}

		return $rows;
	}

	/**
	 * The blogname and home options of some sites, read from each site's
	 * own options table, errors suppressed. Only the sites whose table could
	 * be read are in the result.
	 *
	 * @param int[] $ids The blog ids.
	 *
	 * @return array<int, array<string, string>> The options by blog id, then name.
	 */
	private static function options( array $ids ): array {
		global $wpdb;

		$suppressed = $wpdb->suppress_errors( true );
		try {
			$rows = self::read_options( $ids );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}

		$options = [];
		foreach ( $rows as $id => $site ) {
			$options[ $id ] = [];
			foreach ( $site as $row ) {
				$options[ $id ][ (string) $row['option_name'] ] = (string) $row['option_value'];
			}
		}

		return $options;
	}

	/**
	 * Read the options of some sites in one query. When it fails, each half
	 * is read the same way, down to a single site; when two sites that each
	 * read fine fail together, it is the joining that fails here, and the
	 * rest of the report reads one site a query.
	 *
	 * @param int[] $ids The blog ids.
	 *
	 * @return array<int, array<int, array<string, mixed>>> The rows by blog id, for each site that could be read.
	 */
	private static function read_options( array $ids ): array {
		global $wpdb;

		if ( self::$single && count( $ids ) > 1 ) {
			$out = [];
			foreach ( $ids as $id ) {
				$out += self::read_options( [ $id ] );
			}

			return $out;
		}

		$queries = array_map( [ self::class, 'options_query' ], $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Each part is prepared in options_query().
		$rows = $wpdb->get_results( implode( ' UNION ALL ', $queries ), ARRAY_A );
		if ( ! self::query_failed() && is_array( $rows ) ) {
			$out = array_fill_keys( $ids, [] );
			foreach ( $rows as $row ) {
				$out[ (int) $row['blog_id'] ][] = $row;
			}

			return $out;
		}
		if ( 1 === count( $ids ) ) {
			return [];
		}

		$half  = (int) ceil( count( $ids ) / 2 );
		$first = self::read_options( array_slice( $ids, 0, $half ) );
		$rest  = self::read_options( array_slice( $ids, $half ) );
		if ( 2 === count( $ids ) && 2 === count( $first ) + count( $rest ) ) {
			self::$single = true;
		}

		return $first + $rest;
	}

	/**
	 * Whether the last query failed: each query sets the error again.
	 *
	 * @phpstan-impure
	 *
	 * @return bool
	 */
	private static function query_failed(): bool {
		global $wpdb;

		return '' !== (string) $wpdb->last_error;
	}

	/**
	 * The query of one site's blogname and home options. The columns are
	 * converted to one character set, so tables with different collations
	 * join.
	 *
	 * @param int $id The blog id.
	 *
	 * @return string
	 */
	private static function options_query( int $id ): string {
		global $wpdb;

		// The table name comes from WordPress's prefix and an integer.
		$table = $wpdb->get_blog_prefix( $id ) . 'options';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name cannot be a placeholder.
		return $wpdb->prepare( "SELECT %d AS blog_id, CONVERT(option_name USING utf8mb4) AS option_name, CONVERT(option_value USING utf8mb4) AS option_value FROM `{$table}` WHERE option_name IN (%s, %s)", $id, 'blogname', 'home' );
	}
}
