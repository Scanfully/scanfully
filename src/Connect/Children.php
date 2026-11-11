<?php
/**
 * The network children class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Connect;

use Scanfully\Health\Subsites;
use Scanfully\Options\Controller as OptionsController;

/**
 * The subsites of a connected network that its owner monitors in Scanfully,
 * each as a Scanfully site of its own (a child of the network's site), and
 * where the network connection sends each one's data.
 *
 * The plugin learns them from the API's answer about the network connection
 * (GET /v1/sites/{network}/plugin-connection, `subsites: [{blog_id,
 * site_id}]`), read on the main site with the network's token (see Scopes).
 * The mapping is kept in the network's options (not loaded on every
 * request), and each monitored subsite gets a small marker in its own,
 * autoloaded options: the child's site id, the blog id and a hash of the
 * subsite's address. The hot path (an activity event, the PHP error monitor
 * at boot) reads only the marker, which costs no query; a job that sends
 * checks the whole chain again (Targets::now()). A marker whose blog id or
 * address hash is not the subsite's own (a cloned subsite, a copy of its
 * tables elsewhere) is ignored.
 *
 * Markers are written and removed by the main site, straight in each
 * subsite's options table (never switch_to_blog()), a hundred subsites a
 * query, in jobs that stop after a few seconds and go on in the next one.
 * They go when the network disconnects, when a subsite is no longer
 * monitored, and when it moves to another network; a deleted subsite's
 * tables go with it. Nothing is ever sent for a subsite without one.
 */
final class Children {

	/**
	 * Network option: the monitored subsites, blog id to child site id.
	 */
	public const MAPPING_OPTION = 'scanfully_connect_children';

	/**
	 * Network option: the blog ids whose marker is to be written or removed.
	 */
	public const PENDING_OPTION = 'scanfully_connect_children_pending';

	/**
	 * A subsite's own, autoloaded option: its marker.
	 */
	public const MARKER_OPTION = 'scanfully_network_child';

	/**
	 * Hook: write and remove markers, on the main site.
	 */
	public const ACTION_SYNC_MARKERS = 'scanfully_sync_child_markers';

	/**
	 * Subsites whose markers are written per query of their option tables.
	 */
	private const CHUNK = 100;

	/**
	 * Seconds a marker sync runs before it goes on in a new job.
	 */
	private const BUDGET = 10;

	/**
	 * The highest blog id the API takes (its column is a 32-bit integer).
	 */
	private const MAX_BLOG_ID = 2147483647;

	/**
	 * Watch the network for what ends a marker. Nothing on a single site.
	 *
	 * @return void
	 */
	public static function setup(): void {
		if ( ! Network::multisite() ) {
			return;
		}
		add_action( self::ACTION_SYNC_MARKERS, [ self::class, 'sync_markers' ] );
		add_action( 'scanfully_options_cleared', [ self::class, 'disconnected' ] );
		add_action( 'wp_delete_site', [ self::class, 'site_deleted' ] );
		add_action( 'wp_update_site', [ self::class, 'site_updated' ], 10, 2 );
	}

	/**
	 * Keep the monitored subsites the API answered, on the main site under
	 * the network connection. An answer without the list (an older API)
	 * changes nothing; an empty list ends every child.
	 *
	 * @param mixed $raw The `subsites` of the answer.
	 *
	 * @return int[] The blog ids that became children or changed child site.
	 */
	public static function learn( $raw ): array {
		if ( ! is_array( $raw ) || ! Network::multisite() || ! OptionsController::network_scope() ) {
			return [];
		}
		$mapping = self::parse( $raw );
		$before  = self::mapping();
		if ( $mapping === $before ) {
			return [];
		}

		$added   = [];
		$changed = [];
		foreach ( $mapping as $blog => $site ) {
			if ( ( $before[ $blog ] ?? '' ) !== $site ) {
				$added[]   = $blog;
				$changed[] = $blog;
			}
		}
		foreach ( array_keys( $before ) as $blog ) {
			if ( ! isset( $mapping[ $blog ] ) ) {
				$changed[] = $blog;
			}
		}

		if ( [] === $mapping ) {
			delete_site_option( self::MAPPING_OPTION );
		} else {
			update_site_option( self::MAPPING_OPTION, $mapping );
		}
		self::queue_markers( $changed );

		return $added;
	}

	/**
	 * The monitored subsites of an answer, checked: a blog id the API takes
	 * that is not the main site, and a site id of letters, digits, `-` and
	 * `_`. The first entry per blog id, and per site id, wins.
	 *
	 * @param array<mixed> $raw The `subsites` of the answer.
	 *
	 * @return array<int, string> Blog id to site id, by blog id.
	 */
	public static function parse( array $raw ): array {
		$main    = (int) get_main_site_id();
		$mapping = [];
		$sites   = [];
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$blog = $entry['blog_id'] ?? null;
			$site = $entry['site_id'] ?? null;
			if ( is_string( $blog ) && ctype_digit( $blog ) ) {
				$blog = (int) $blog;
			}
			if ( ! is_int( $blog ) || $blog < 1 || $blog > self::MAX_BLOG_ID || $blog === $main ) {
				continue;
			}
			if ( ! self::is_site_id( $site ) || isset( $mapping[ $blog ] ) || isset( $sites[ $site ] ) ) {
				continue;
			}
			$mapping[ $blog ] = $site;
			$sites[ $site ]   = true;
		}
		ksort( $mapping );

		return $mapping;
	}

	/**
	 * Whether a value is a Scanfully site id as the plugin takes it.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 * @phpstan-assert-if-true string $value
	 */
	private static function is_site_id( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', $value );
	}

	/**
	 * The monitored subsites of a network: blog id to child site id.
	 *
	 * @param int $network The network's id; 0 for this request's.
	 *
	 * @return array<int, string>
	 */
	public static function mapping( int $network = 0 ): array {
		if ( ! Network::multisite() ) {
			return [];
		}
		$raw = get_network_option( $network > 0 ? $network : (int) get_current_network_id(), self::MAPPING_OPTION, [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$mapping = [];
		foreach ( $raw as $blog => $site ) {
			if ( is_int( $blog ) && $blog > 0 && self::is_site_id( $site ) ) {
				$mapping[ $blog ] = $site;
			}
		}

		return $mapping;
	}

	/**
	 * The marker of this site, on a subsite (never the main site), read from
	 * the autoloaded options, so it costs no query; null without one, or
	 * when it is not this site's: another blog id, or (with `$check_home`)
	 * another address.
	 *
	 * @param bool $check_home Whether the address must match.
	 *
	 * @return array{site_id: string, blog_id: int, home: string}|null
	 */
	public static function marker_here( bool $check_home = true ): ?array {
		if ( ! Network::multisite() || is_main_site() || ! function_exists( 'wp_load_alloptions' ) ) {
			return null;
		}
		$all = wp_load_alloptions();
		if ( ! isset( $all[ self::MARKER_OPTION ] ) ) {
			return null;
		}
		$marker = self::marker_of( maybe_unserialize( $all[ self::MARKER_OPTION ] ) );
		if ( null === $marker || (int) get_current_blog_id() !== $marker['blog_id'] ) {
			return null;
		}
		if ( $check_home && ! hash_equals( $marker['home'], Address::hash( Address::current() ) ) ) {
			return null;
		}

		return $marker;
	}

	/**
	 * A stored marker, checked.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array{site_id: string, blog_id: int, home: string}|null
	 */
	public static function marker_of( $value ): ?array {
		if ( ! is_array( $value ) || ! self::is_site_id( $value['site_id'] ?? null ) || ! is_int( $value['blog_id'] ?? null ) || ! is_string( $value['home'] ?? null ) ) {
			return null;
		}

		return [
			'site_id' => $value['site_id'],
			'blog_id' => $value['blog_id'],
			'home'    => $value['home'],
		];
	}

	/**
	 * The child target of this site, checked from end to end: a subsite
	 * with its marker, the network connection applies on its network (the
	 * plugin network-activated, the network connected), the network's
	 * mapping names this blog with the marker's child site, and the network
	 * is not a copy (see Network::moved()). Null otherwise.
	 *
	 * @return Target|null
	 */
	public static function current(): ?Target {
		$marker = self::marker_here();
		if ( null === $marker ) {
			return null;
		}
		$network = (int) get_current_network_id();
		if ( ! Network::applies_on( $network ) ) {
			return null;
		}
		if ( ( self::mapping( $network )[ $marker['blog_id'] ] ?? '' ) !== $marker['site_id'] ) {
			return null;
		}
		if ( Network::moved() ) {
			return null;
		}

		return Target::child( $marker['site_id'], $marker['blog_id'] );
	}

	/**
	 * Forget a subsite: the API answered that its child site is gone (404).
	 * Its marker goes in the next sync; the next answer of the API may bring
	 * it back.
	 *
	 * @param int $blog The blog id.
	 *
	 * @return void
	 */
	public static function forget( int $blog ): void {
		$mapping = self::mapping();
		if ( ! isset( $mapping[ $blog ] ) ) {
			return;
		}
		unset( $mapping[ $blog ] );
		if ( [] === $mapping ) {
			delete_site_option( self::MAPPING_OPTION );
		} else {
			update_site_option( self::MAPPING_OPTION, $mapping );
		}
		self::queue_markers( [ $blog ] );
	}

	/**
	 * Add blogs to the markers to sync, and sync them in a job on the main
	 * site.
	 *
	 * @param int[] $blogs The blog ids.
	 *
	 * @return void
	 */
	private static function queue_markers( array $blogs ): void {
		if ( [] === $blogs ) {
			return;
		}
		$pending = array_values( array_unique( array_merge( self::pending(), array_map( 'intval', $blogs ) ) ) );
		update_site_option( self::PENDING_OPTION, $pending );
		if ( function_exists( 'as_enqueue_async_action' ) && did_action( 'action_scheduler_init' ) && is_main_site() ) {
			as_enqueue_async_action( self::ACTION_SYNC_MARKERS, [], 'scanfully', true );
		}
	}

	/**
	 * The blog ids whose marker is to be written or removed.
	 *
	 * @return int[]
	 */
	public static function pending(): array {
		$raw = get_site_option( self::PENDING_OPTION, [] );

		return is_array( $raw ) ? array_values( array_filter( array_map( 'intval', $raw ), static fn( int $blog ): bool => $blog > 0 ) ) : [];
	}

	/**
	 * Write and remove the markers that are pending, on the main site, a
	 * hundred subsites at a time, for at most a few seconds; the rest goes
	 * on in a new job.
	 *
	 * @return void
	 */
	public static function sync_markers(): void {
		if ( ! Network::multisite() || ! is_main_site() ) {
			return;
		}
		$pending = self::pending();
		$mapping = self::mapping();
		$started = microtime( true );
		while ( [] !== $pending ) {
			$chunk = array_slice( $pending, 0, self::CHUNK );
			self::write_markers( $chunk, $mapping );
			$pending = array_slice( $pending, self::CHUNK );
			if ( [] === $pending ) {
				break;
			}
			update_site_option( self::PENDING_OPTION, $pending );
			if ( microtime( true ) - $started > self::BUDGET ) {
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					as_enqueue_async_action( self::ACTION_SYNC_MARKERS, [], 'scanfully', true );
				}
				return;
			}
		}
		delete_site_option( self::PENDING_OPTION );
	}

	/**
	 * Write the markers of the subsites in the mapping that belong to this
	 * network, and remove the others, where they differ from what is stored.
	 *
	 * @param int[]              $blogs   The blog ids.
	 * @param array<int, string> $mapping The mapping.
	 *
	 * @return void
	 */
	public static function write_markers( array $blogs, array $mapping ): void {
		$main    = (int) get_main_site_id();
		$blogs   = array_values( array_filter( $blogs, static fn( int $blog ): bool => $blog !== $main ) );
		$ours    = self::in_network( $blogs );
		$options = Subsites::read( $blogs, [ 'home', self::MARKER_OPTION ] );
		foreach ( $blogs as $blog ) {
			if ( ! isset( $options[ $blog ] ) ) {
				// No options table: the subsite is gone.
				continue;
			}
			if ( isset( $mapping[ $blog ], $ours[ $blog ] ) ) {
				self::keep_marker( $blog, $mapping[ $blog ], $options[ $blog ] );
			} elseif ( isset( $options[ $blog ][ self::MARKER_OPTION ] ) ) {
				self::remove_marker( $blog );
			}
		}
	}

	/**
	 * Store a monitored subsite's marker when what is stored differs: a new
	 * child, another child site, or the subsite's address changed.
	 *
	 * @param int                   $blog    The blog id.
	 * @param string                $site    The child's site id.
	 * @param array<string, string> $options The subsite's home and marker options, as stored.
	 *
	 * @return void
	 */
	public static function keep_marker( int $blog, string $site, array $options ): void {
		$marker = [
			'site_id' => $site,
			'blog_id' => $blog,
			'home'    => Address::hash( Address::normalize( $options['home'] ?? '' ) ),
		];
		if ( self::marker_of( maybe_unserialize( $options[ self::MARKER_OPTION ] ?? '' ) ) !== $marker ) {
			self::store_marker( $blog, $marker );
		}
	}

	/**
	 * Of some blog ids, the ones in this request's network.
	 *
	 * @param int[] $blogs The blog ids.
	 *
	 * @return array<int, true>
	 */
	private static function in_network( array $blogs ): array {
		global $wpdb;

		if ( [] === $blogs ) {
			return [];
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One indexed read of the sites table, without caching every site; the placeholders are built from the count of ids.
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT blog_id FROM {$wpdb->blogs} WHERE site_id = %d AND blog_id IN (" . implode( ', ', array_fill( 0, count( $blogs ), '%d' ) ) . ')',
				(int) get_current_network_id(),
				...$blogs
			)
		);
		// phpcs:enable

		return array_fill_keys( array_map( 'intval', is_array( $found ) ? $found : [] ), true );
	}

	/**
	 * Store a subsite's marker, autoloaded, in its own options table.
	 *
	 * @param int                                                $blog   The blog id.
	 * @param array{site_id: string, blog_id: int, home: string} $marker The marker.
	 *
	 * @return void
	 */
	public static function store_marker( int $blog, array $marker ): void {
		global $wpdb;

		$table = $wpdb->get_blog_prefix( $blog ) . 'options';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A write to another site's options without switching to it; the table name comes from WordPress's prefix and an integer.
		$wpdb->query( $wpdb->prepare( "INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES (%s, %s, 'yes') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'yes'", self::MARKER_OPTION, maybe_serialize( $marker ) ) );
		self::forget_cached_options( $blog );
	}

	/**
	 * Remove a subsite's marker from its own options table.
	 *
	 * @param int $blog The blog id.
	 *
	 * @return void
	 */
	public static function remove_marker( int $blog ): void {
		global $wpdb;

		$table      = $wpdb->get_blog_prefix( $blog ) . 'options';
		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A write to another site's options without switching to it; the table name comes from WordPress's prefix and an integer.
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE option_name = %s", self::MARKER_OPTION ) );
		$wpdb->suppress_errors( $suppressed );
		self::forget_cached_options( $blog );
	}

	/**
	 * Drop a site's cached options after a direct write, so its next request
	 * reads the marker as it is now. Only a persistent object cache keeps
	 * another site's options past this request.
	 *
	 * @param int $blog The blog id.
	 *
	 * @return void
	 */
	private static function forget_cached_options( int $blog ): void {
		$current = (int) get_current_blog_id();
		if ( $blog !== $current && ! wp_using_ext_object_cache() ) {
			return;
		}
		$forget = static function (): void {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( self::MARKER_OPTION, 'options' );
		};
		if ( $blog === $current ) {
			$forget();
		} elseif ( function_exists( 'wp_cache_switch_to_blog' ) ) {
			wp_cache_switch_to_blog( $blog );
			$forget();
			wp_cache_switch_to_blog( $current );
		} else {
			// An object cache without per-site switching.
			switch_to_blog( $blog );
			$forget();
			restore_current_blog();
		}
	}

	/**
	 * The network connection was cleared: every child ends, and the markers
	 * go in jobs on the main site.
	 *
	 * @return void
	 */
	public static function disconnected(): void {
		if ( ! Network::multisite() || ! OptionsController::network_scope() ) {
			return;
		}
		$blogs = array_keys( self::mapping() );
		delete_site_option( self::MAPPING_OPTION );
		do_action( 'scanfully_network_children_cleared' );
		self::queue_markers( $blogs );
	}

	/**
	 * A subsite was deleted: it leaves its network's mapping (its tables, the
	 * marker with them, are gone).
	 *
	 * @param mixed $site The site.
	 *
	 * @return void
	 */
	public static function site_deleted( $site ): void {
		if ( ! is_object( $site ) || ! isset( $site->blog_id ) ) {
			return;
		}
		self::leave( (int) $site->blog_id, (int) ( $site->site_id ?? 0 ) );
	}

	/**
	 * A subsite moved to another network: it leaves the old network's
	 * mapping, and its marker goes now.
	 *
	 * @param mixed $new_site The site after the change.
	 * @param mixed $old_site The site before the change.
	 *
	 * @return void
	 */
	public static function site_updated( $new_site, $old_site ): void {
		if ( ! is_object( $new_site ) || ! is_object( $old_site ) || (int) ( $new_site->site_id ?? 0 ) === (int) ( $old_site->site_id ?? 0 ) ) {
			return;
		}
		$blog = (int) ( $new_site->blog_id ?? 0 );
		if ( self::leave( $blog, (int) ( $old_site->site_id ?? 0 ) ) ) {
			self::remove_marker( $blog );
		}
	}

	/**
	 * Take a blog out of a network's mapping.
	 *
	 * @param int $blog    The blog id.
	 * @param int $network The network's id.
	 *
	 * @return bool Whether it was in it.
	 */
	private static function leave( int $blog, int $network ): bool {
		if ( $blog <= 0 || $network <= 0 ) {
			return false;
		}
		$mapping = self::mapping( $network );
		if ( ! isset( $mapping[ $blog ] ) ) {
			return false;
		}
		unset( $mapping[ $blog ] );
		if ( [] === $mapping ) {
			delete_network_option( $network, self::MAPPING_OPTION );
		} else {
			update_network_option( $network, self::MAPPING_OPTION, $mapping );
		}

		return true;
	}
}
