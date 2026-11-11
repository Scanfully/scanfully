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
 * cache, and get_sites() would cache every site object).
 *
 * The address is the site's home option as stored, as the plugin reports
 * its own address: what WordPress gives without the home_url filters and
 * the scheme of the request, which answer differently in wp-admin, REST and
 * cron. WordPress's own domain mapping stores the mapped address there.
 *
 * A change to a site of the network (created, changed or deleted, or its
 * name or address) sends a new site data report a minute later, from the
 * main site.
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
	 * Sites read from the sites table per query.
	 */
	private const PAGE = 1000;

	/**
	 * Option tables read per query.
	 */
	private const CHUNK = 100;

	/**
	 * The columns of a site whose change changes the report.
	 */
	private const WATCHED = [ 'domain', 'path', 'public', 'archived', 'spam', 'deleted' ];

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
		add_action( 'wp_initialize_site', [ self::class, 'schedule_report' ], 100, 0 );
		add_action( 'wp_update_site', [ self::class, 'site_updated' ], 10, 2 );
		add_action( 'wp_delete_site', [ self::class, 'schedule_report' ], 10, 0 );
		// A site's name and address live in its own options.
		add_action( 'update_option_blogname', [ self::class, 'schedule_report' ], 10, 0 );
		add_action( 'update_option_home', [ self::class, 'schedule_report' ], 10, 0 );
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
	 * Schedule a site data report when a site's row changed in a way the
	 * report shows. WordPress also updates the row on every post published
	 * anywhere in the network (last_updated), which changes nothing here.
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
				self::schedule_report();
				return;
			}
		}
	}

	/**
	 * Schedule a site data report on the main site a minute from now, when
	 * the network connection applies there. One report is pending at a time:
	 * each change moves it on (the debounced site data sync).
	 *
	 * @return void
	 */
	public static function schedule_report(): void {
		if ( ! Network::activated() || ! Network::connected() ) {
			return;
		}
		if ( ! function_exists( 'as_schedule_single_action' ) || ! did_action( 'action_scheduler_init' ) ) {
			return;
		}
		$main     = (int) get_main_site_id();
		$switched = $main > 0 && get_current_blog_id() !== $main;
		if ( $switched ) {
			switch_to_blog( $main );
		}
		try {
			if ( Network::applies() ) {
				\Scanfully\Cron\Controller::schedule_health_sync();
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
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

		$main      = (int) get_main_site_id();
		$scheme    = 'https' === wp_parse_url( Controller::site_address(), PHP_URL_SCHEME ) ? 'https' : 'http';
		$sites     = [];
		$truncated = false;
		$after     = 0;
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
				foreach ( $chunk as $row ) {
					$sites[] = self::entry( $row, $options[ (int) $row['blog_id'] ] ?? [], $main, $scheme );
				}
			}
			if ( [] !== $rows ) {
				$after = (int) $rows[ count( $rows ) - 1 ]['blog_id'];
			}
		} while ( $full && ! $truncated );

		// A network always has its main site: an empty list is a failed read.
		if ( [] === $sites ) {
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
		$home = trim( $options['home'] ?? '' );
		if ( '' === $home ) {
			// No home option (a site whose tables are missing): its address
			// in the sites table.
			$home = $scheme . '://' . $row['domain'] . untrailingslashit( (string) $row['path'] );
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
	 * own options table in one query. When that fails (a site whose tables
	 * are missing), each site is read on its own and a site that fails is
	 * left without.
	 *
	 * @param int[] $ids The blog ids.
	 *
	 * @return array<int, array<string, string>> The options by blog id, then name.
	 */
	private static function options( array $ids ): array {
		global $wpdb;

		$queries = [];
		foreach ( $ids as $id ) {
			$queries[ $id ] = self::options_query( $id );
		}

		$suppressed = $wpdb->suppress_errors( true );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Each part is prepared in options_query().
			$rows = $wpdb->get_results( implode( ' UNION ALL ', $queries ), ARRAY_A );
			if ( self::query_failed() ) {
				$rows = [];
				foreach ( $queries as $query ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared in options_query().
					$site = $wpdb->get_results( $query, ARRAY_A );
					if ( ! self::query_failed() && is_array( $site ) ) {
						$rows = array_merge( $rows, $site );
					}
				}
			}
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}

		$options = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$options[ (int) $row['blog_id'] ][ (string) $row['option_name'] ] = (string) $row['option_value'];
		}

		return $options;
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
	 * The query of one site's blogname and home options.
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
		return $wpdb->prepare( "SELECT %d AS blog_id, option_name, option_value FROM `{$table}` WHERE option_name IN (%s, %s)", $id, 'blogname', 'home' );
	}
}
