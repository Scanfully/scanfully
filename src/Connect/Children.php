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
	 * A subsite's own, autoloaded option: the site id of its own connection,
	 * which the main site ended because the subsite is now monitored as
	 * that site through the network (attached in Scanfully). The subsite
	 * finishes the disconnect itself on its next request.
	 */
	public const ENDED_OPTION = 'scanfully_connect_ended_attached';

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
		// Once Action Scheduler can unschedule the connection's jobs.
		add_action( 'action_scheduler_init', [ self::class, 'finish_ended_connection' ], 20 );
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
	 * @param int  $network The network's id; 0 for this request's.
	 * @param bool $fresh   Whether to read it from the database.
	 *
	 * @return array<int, string>
	 */
	public static function mapping( int $network = 0, bool $fresh = false ): array {
		if ( ! Network::multisite() ) {
			return [];
		}
		if ( $fresh ) {
			self::forget_cached_option( self::MAPPING_OPTION );
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
	 * another address (the stored home option, never a filtered one).
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
		// The home option as stored, as the main site hashed it: a filter on
		// the option (domain mapping on pre_option_home) changes nothing here.
		if ( $check_home && ! hash_equals( $marker['home'], Address::hash( Address::normalize( is_string( $all['home'] ?? null ) ? $all['home'] : '' ) ) ) ) {
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
		$pending = array_values( array_unique( array_merge( self::pending( true ), array_map( 'intval', $blogs ) ) ) );
		update_site_option( self::PENDING_OPTION, $pending );
		if ( is_main_site() ) {
			self::schedule_sync();
		}
	}

	/**
	 * Schedule a marker sync on the main site, unless one is waiting to
	 * start. Never as a unique job: Action Scheduler refuses one while the
	 * same job runs, and a sync that is running may already have read the
	 * list (it keeps what was added meanwhile, see sync_markers()).
	 *
	 * @return void
	 */
	private static function schedule_sync(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! did_action( 'action_scheduler_init' ) ) {
			return;
		}
		// A timestamp: one is waiting; true: one runs; false: none.
		if ( function_exists( 'as_next_scheduled_action' ) && is_int( as_next_scheduled_action( self::ACTION_SYNC_MARKERS, [], 'scanfully' ) ) ) {
			return;
		}
		as_schedule_single_action( time(), self::ACTION_SYNC_MARKERS, [], 'scanfully' );
	}

	/**
	 * The blog ids whose marker is to be written or removed.
	 *
	 * @param bool $fresh Whether to read it from the database (another process may have added to it).
	 *
	 * @return int[]
	 */
	public static function pending( bool $fresh = false ): array {
		if ( $fresh ) {
			self::forget_cached_option( self::PENDING_OPTION );
		}
		$raw = get_site_option( self::PENDING_OPTION, [] );

		return is_array( $raw ) ? array_values( array_filter( array_map( 'intval', $raw ), static fn( int $blog ): bool => $blog > 0 ) ) : [];
	}

	/**
	 * Drop a network option of this request's network from the options
	 * cache.
	 *
	 * @param string $name The option's name.
	 *
	 * @return void
	 */
	private static function forget_cached_option( string $name ): void {
		$network = (int) get_current_network_id();
		wp_cache_delete( $network . ':notoptions', 'site-options' );
		wp_cache_delete( $network . ':' . $name, 'site-options' );
	}

	/**
	 * Write and remove the markers that are pending, on the main site, a
	 * hundred subsites at a time, for at most a few seconds; the rest goes
	 * on in a new job. Only what this run did leaves the list: blogs another
	 * process added meanwhile stay, and so do blogs whose child changed
	 * again while this run wrote their marker.
	 *
	 * @param float $budget Seconds to run before the rest goes on in a new job.
	 *
	 * @return void
	 */
	public static function sync_markers( float $budget = self::BUDGET ): void {
		if ( ! Network::multisite() || ! is_main_site() ) {
			return;
		}
		$pending   = self::pending( true );
		$mapping   = self::mapping( 0, true );
		$started   = microtime( true );
		$processed = [];
		foreach ( array_chunk( $pending, self::CHUNK ) as $chunk ) {
			if ( [] !== $processed && microtime( true ) - $started > $budget ) {
				break;
			}
			self::write_markers( $chunk, $mapping );
			array_push( $processed, ...$chunk );
		}

		$now  = self::mapping( 0, true );
		$redo = array_filter(
			$processed,
			static function ( int $blog ) use ( $mapping, $now ): bool {
				return ( $mapping[ $blog ] ?? '' ) !== ( $now[ $blog ] ?? '' );
			}
		);
		$left = array_values( array_unique( array_merge( array_diff( self::pending( true ), $processed ), $redo ) ) );
		if ( [] === $left ) {
			delete_site_option( self::PENDING_OPTION );
			return;
		}
		update_site_option( self::PENDING_OPTION, $left );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::ACTION_SYNC_MARKERS, [], 'scanfully' );
		}
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
		$state   = self::child_state_options();
		$options = Subsites::read( $blogs, array_merge( self::marker_reads(), $state ) );
		foreach ( $blogs as $blog ) {
			if ( ! isset( $options[ $blog ] ) ) {
				// No options table: the subsite is gone.
				continue;
			}
			if ( isset( $mapping[ $blog ], $ours[ $blog ] ) ) {
				self::keep_marker( $blog, $mapping[ $blog ], $options[ $blog ] );
			} elseif ( [] !== array_intersect_key( $options[ $blog ], array_flip( $state ) ) ) {
				self::remove_marker( $blog );
			}
		}
	}

	/**
	 * The options of a subsite keep_marker() needs, read straight from its
	 * options table (Subsites::read()).
	 *
	 * @return string[]
	 */
	public static function marker_reads(): array {
		return [ 'home', self::MARKER_OPTION, 'scanfully_connect_is_connected', 'scanfully_connect_site_id' ];
	}

	/**
	 * Store a monitored subsite's marker when what is stored differs: a new
	 * child, another child site, or the subsite's address changed. A
	 * subsite connected on its own as the very site it is now monitored as
	 * (it was attached to the network in Scanfully, which ended its own
	 * grant) has that connection ended here too.
	 *
	 * @param int                   $blog    The blog id.
	 * @param string                $site    The child's site id.
	 * @param array<string, string> $options The subsite's options of marker_reads(), as stored.
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
		if ( 'yes' === ( $options['scanfully_connect_is_connected'] ?? '' ) && $site === ( $options['scanfully_connect_site_id'] ?? '' ) ) {
			self::end_attached_connection( $blog, $site );
		}
	}

	/**
	 * End a subsite's own connection to the site it is now monitored as
	 * through the network, as a disconnect would, but from the main site:
	 * Scanfully already ended its grant at the attach, so nothing is revoked.
	 * Its tokens and connection state go now, straight from its options
	 * table (never switch_to_blog()), so it reads as not connected; the
	 * subsite stops the connection's jobs itself on its next request (see
	 * finish_ended_connection()). An own connection to another site (another
	 * account) is left alone.
	 *
	 * @param int    $blog The blog id.
	 * @param string $site The site id of the ended connection.
	 *
	 * @return void
	 */
	private static function end_attached_connection( int $blog, string $site ): void {
		global $wpdb;

		$names      = OptionsController::own_connection_options();
		$table      = $wpdb->get_blog_prefix( $blog ) . 'options';
		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Writes to another site's options without switching to it; the table name comes from WordPress's prefix and an integer, the placeholders from the count of names.
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE option_name IN (" . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ')', ...$names ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES (%s, %s, 'yes') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'yes'", self::ENDED_OPTION, $site ) );
		// phpcs:enable
		$wpdb->suppress_errors( $suppressed );
		self::forget_cached_options( $blog, array_merge( $names, [ self::ENDED_OPTION ] ) );
	}

	/**
	 * On a subsite whose own connection the main site ended (see
	 * end_attached_connection()): finish the disconnect in its own context,
	 * as one from its settings page does, so the connection's jobs stop (the
	 * email checks, the scope checks) and what it kept goes. A connection
	 * made since, to another site, stays. Costs nothing on other requests:
	 * the flag is read from the autoloaded options.
	 *
	 * @return void
	 */
	public static function finish_ended_connection(): void {
		if ( ! Network::multisite() || is_main_site() || ! function_exists( 'wp_load_alloptions' ) ) {
			return;
		}
		$all = wp_load_alloptions();
		if ( ! isset( $all[ self::ENDED_OPTION ] ) ) {
			return;
		}
		$options = OptionsController::get_options();
		if ( ! $options->is_connected || (string) $all[ self::ENDED_OPTION ] === $options->site_id ) {
			OptionsController::clear();
		}
		delete_option( self::ENDED_OPTION );
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
	 * Remove a subsite's marker from its own options table, with what the
	 * subsite kept for its child (see Target::option()): the checkout probe
	 * secret, the last failed request and the PHP errors it sent, so none of
	 * it outlives the child.
	 *
	 * @param int $blog The blog id.
	 *
	 * @return void
	 */
	public static function remove_marker( int $blog ): void {
		global $wpdb;

		$names      = self::child_state_options();
		$table      = $wpdb->get_blog_prefix( $blog ) . 'options';
		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- A write to another site's options without switching to it; the table name comes from WordPress's prefix and an integer, the placeholders from the count of names.
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE option_name IN (" . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ')', ...$names ) );
		$wpdb->suppress_errors( $suppressed );
		self::forget_cached_options( $blog, $names );
	}

	/**
	 * The marker and the options a subsite keeps for its child.
	 *
	 * @return string[]
	 */
	public static function child_state_options(): array {
		$child = Target::child( '', 0 );

		return [
			self::MARKER_OPTION,
			$child->option( \Scanfully\WooCheckout\Controller::OPTION_PROBE_SECRET ),
			$child->option( \Scanfully\API\Request::OPTION_LAST_ERROR ),
			$child->option( \Scanfully\Errors\Store::KNOWN_OPTION ),
			$child->option( \Scanfully\Errors\Store::SENT_OPTION ),
		];
	}

	/**
	 * Drop a site's cached options after a direct write, so its next request
	 * reads the marker as it is now. Only a persistent object cache keeps
	 * another site's options past this request.
	 *
	 * @param int      $blog  The blog id.
	 * @param string[] $names The options written, besides the marker.
	 *
	 * @return void
	 */
	private static function forget_cached_options( int $blog, array $names = [] ): void {
		$current = (int) get_current_blog_id();
		if ( $blog !== $current && ! wp_using_ext_object_cache() ) {
			return;
		}
		$names  = array_unique( array_merge( [ self::MARKER_OPTION ], $names ) );
		$forget = static function () use ( $names ): void {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			foreach ( $names as $name ) {
				wp_cache_delete( $name, 'options' );
			}
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
