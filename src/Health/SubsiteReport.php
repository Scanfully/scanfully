<?php
/**
 * The subsite report class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Health;

use Scanfully\API\SiteDataRequest;
use Scanfully\Connect\Children;
use Scanfully\Connect\Network;
use Scanfully\Connect\Target;
use Scanfully\Options\Controller as OptionsController;

/**
 * What the network connection reports about each subsite its owner
 * monitors, to that subsite's own Scanfully site (a child of the network's):
 * its address, name, search engine visibility, language and timezone,
 * permalink structure, active plugins (its own and the network's), theme,
 * and whether WooCommerce is active there. Scanfully builds the child's
 * plugin and theme list from the network's inventory with these flags.
 *
 * Sent from the main site, with the network's token, every three hours and
 * a minute after a change to a monitored subsite (one of the options below,
 * which a plugin activation or a theme switch also changes), in jobs of 25
 * subsites that stop after 20 seconds and go on in the next. The options
 * are read straight from each subsite's own options table (see
 * Subsites::read()), never switch_to_blog() per subsite. Nothing is read or
 * sent for a subsite that is not monitored, and nothing on a copy of the
 * network (see Network::moved()).
 *
 * POST /v1/sites/{child}/health/data, with X-Scanfully-Blog-Id:
 * `{"subsite_report": {...}, "reported_at": "..."}`.
 */
final class SubsiteReport {

	/**
	 * Hook: report subsites, on the main site. Recurring without arguments
	 * (every monitored subsite), or single with the blog ids of a job.
	 */
	public const ACTION = 'scanfully_report_subsites';

	/**
	 * Hook: report the subsites that changed, a minute after the first
	 * change.
	 */
	public const ACTION_CHANGED = 'scanfully_report_changed_subsites';

	/**
	 * Network option: the monitored subsites that changed since the last
	 * report of changes.
	 */
	public const CHANGED_OPTION = 'scanfully_connect_children_changed';

	/**
	 * The options of a subsite whose change sends its report again.
	 */
	public const WATCHED = [ 'home', 'blogname', 'blog_public', 'WPLANG', 'timezone_string', 'gmt_offset', 'permalink_structure', 'active_plugins', 'stylesheet', 'template' ];

	/**
	 * The WooCommerce plugin, as WordPress names it.
	 */
	private const WOOCOMMERCE = 'woocommerce/woocommerce.php';

	/**
	 * Subsites per job.
	 */
	public const CHUNK = 25;

	/**
	 * Seconds a job sends before the rest goes on in the next.
	 */
	private const BUDGET = 20;

	/**
	 * Timeout of one report, in seconds.
	 */
	private const TIMEOUT = 15;

	/**
	 * Seconds between reports of all monitored subsites.
	 */
	private const INTERVAL = 3 * HOUR_IN_SECONDS;

	/**
	 * Seconds the report of changes waits for more changes.
	 */
	private const DELAY = MINUTE_IN_SECONDS;

	/**
	 * The most changed subsites kept; past that, the next full report.
	 */
	private const MAX_CHANGED = 1000;

	/**
	 * The subsites whose change was noted in this request.
	 *
	 * @var array<int, true>
	 */
	private static array $noted = [];

	/**
	 * Add the hooks. Nothing on a single site.
	 *
	 * @return void
	 */
	public static function setup(): void {
		if ( ! Network::multisite() ) {
			return;
		}
		add_action( self::ACTION, [ self::class, 'run' ], 10, 1 );
		add_action( self::ACTION_CHANGED, [ self::class, 'run_changed' ], 10, 0 );
		foreach ( self::WATCHED as $name ) {
			add_action( 'update_option_' . $name, [ self::class, 'changed' ], 10, 0 );
			add_action( 'add_option_' . $name, [ self::class, 'changed' ], 10, 0 );
		}
		// The network's active plugins are in every subsite's report.
		add_action( 'update_site_option_active_sitewide_plugins', [ self::class, 'network_plugins_changed' ], 10, 0 );
		add_action( 'action_scheduler_init', [ self::class, 'maybe_schedule' ] );
		add_action( 'scanfully_network_children_cleared', [ self::class, 'clear' ] );
	}

	/**
	 * Whether the reports are sent here: the main site under the network
	 * connection.
	 *
	 * @return bool
	 */
	public static function applies(): bool {
		return Network::multisite() && is_main_site() && OptionsController::network_scope();
	}

	/**
	 * Keep the three-hourly report scheduled on the main site under the
	 * network connection, where checking it is cheap enough: wp-admin, cron
	 * runs and WP-CLI.
	 *
	 * @return void
	 */
	public static function maybe_schedule(): void {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! self::applies() || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::ACTION, [], 'scanfully' ) ) {
			as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::ACTION, [], 'scanfully', true );
		}
	}

	/**
	 * The network disconnected: no more reports.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_site_option( self::CHANGED_OPTION );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION, [], 'scanfully' );
			as_unschedule_all_actions( self::ACTION_CHANGED, [], 'scanfully' );
		}
	}

	/**
	 * Report some subsites soon, in jobs of CHUNK.
	 *
	 * @param int[] $blogs The blog ids.
	 *
	 * @return void
	 */
	public static function schedule( array $blogs ): void {
		if ( [] === $blogs || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		foreach ( array_chunk( array_values( array_unique( array_map( 'intval', $blogs ) ) ), self::CHUNK ) as $chunk ) {
			as_enqueue_async_action( self::ACTION, [ 'blogs' => $chunk ], 'scanfully' );
		}
	}

	/**
	 * A watched option of this site changed: on a monitored subsite, its
	 * report goes again with the next report of changes, from the main site.
	 *
	 * @return void
	 */
	public static function changed(): void {
		// The address may be the option that changed: only the blog id counts here.
		if ( null === Children::marker_here( false ) ) {
			return;
		}
		$blog = (int) get_current_blog_id();
		if ( isset( self::$noted[ $blog ] ) ) {
			return;
		}
		self::$noted[ $blog ] = true;

		$changed = self::changed_blogs();
		if ( ! in_array( $blog, $changed, true ) && count( $changed ) < self::MAX_CHANGED ) {
			$changed[] = $blog;
			update_site_option( self::CHANGED_OPTION, $changed );
		}
		self::schedule_changed();
	}

	/**
	 * The network's active plugins changed: every monitored subsite's report
	 * goes again with the next report of changes.
	 *
	 * @return void
	 */
	public static function network_plugins_changed(): void {
		if ( ! Network::applies_on( (int) get_current_network_id() ) || [] === Children::mapping() ) {
			return;
		}
		update_site_option( self::CHANGED_OPTION, [ 0 ] );
		self::schedule_changed();
	}

	/**
	 * The subsites noted as changed; 0 stands for all of them.
	 *
	 * @return int[]
	 */
	private static function changed_blogs(): array {
		$raw = get_site_option( self::CHANGED_OPTION, [] );

		return is_array( $raw ) ? array_values( array_map( 'intval', $raw ) ) : [];
	}

	/**
	 * Schedule the report of changes on the main site, a minute from now,
	 * unless one is waiting already, so a busy subsite does not keep moving
	 * it on.
	 *
	 * @return void
	 */
	private static function schedule_changed(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! did_action( 'action_scheduler_init' ) ) {
			return;
		}
		$main = (int) get_main_site_id();
		if ( $main <= 0 ) {
			return;
		}
		$switched = (int) get_current_blog_id() !== $main;
		if ( $switched ) {
			switch_to_blog( $main );
		}
		try {
			if ( ! as_has_scheduled_action( self::ACTION_CHANGED, [], 'scanfully' ) ) {
				as_schedule_single_action( time() + self::DELAY, self::ACTION_CHANGED, [], 'scanfully' );
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Report the subsites that changed.
	 *
	 * @return void
	 */
	public static function run_changed(): void {
		$changed = self::changed_blogs();
		delete_site_option( self::CHANGED_OPTION );
		if ( ! self::applies() || [] === $changed ) {
			return;
		}
		self::schedule( in_array( 0, $changed, true ) ? array_keys( Children::mapping() ) : $changed );
	}

	/**
	 * Report subsites: without blog ids (the three-hourly run), every
	 * monitored subsite in jobs of CHUNK; with them, those of them that are
	 * monitored.
	 *
	 * @param mixed $blogs The blog ids of a job, or null.
	 *
	 * @return void
	 */
	public static function run( $blogs = null ): void {
		if ( ! self::applies() ) {
			return;
		}
		$mapping = Children::mapping();
		if ( ! is_array( $blogs ) ) {
			self::schedule( array_keys( $mapping ) );
			return;
		}
		$main  = (int) get_main_site_id();
		$blogs = array_values(
			array_filter(
				array_unique( array_map( 'intval', $blogs ) ),
				static fn( int $blog ): bool => $blog !== $main && isset( $mapping[ $blog ] )
			)
		);
		if ( [] === $blogs || Network::moved() ) {
			return;
		}
		\Scanfully\Cron\Controller::refresh_access_token_if_needed();
		if ( '' === Network::access_token() ) {
			return;
		}

		$rows    = self::blogs( $blogs );
		$options = Subsites::read( $blogs, array_merge( array_diff( self::WATCHED, [ 'blog_public' ] ), [ Children::MARKER_OPTION, Target::child( '', 0 )->option( \Scanfully\WooCheckout\Controller::OPTION_PROBE_SECRET ) ] ) );
		$context = [
			'network_plugins' => self::network_plugins(),
			'locale'          => self::network_locale(),
			'scheme'          => 'https' === wp_parse_url( Controller::site_address(), PHP_URL_SCHEME ) ? 'https' : 'http',
		];
		$started = microtime( true );
		foreach ( $blogs as $index => $blog ) {
			if ( microtime( true ) - $started > self::BUDGET ) {
				self::schedule( array_slice( $blogs, $index ) );
				return;
			}
			// Gone, or in another network now.
			if ( ! isset( $rows[ $blog ], $options[ $blog ] ) ) {
				continue;
			}
			Children::keep_marker( $blog, $mapping[ $blog ], $options[ $blog ] );
			$report = self::report( $blog, $rows[ $blog ], $options[ $blog ], $context );
			$status = ( new SiteDataRequest() )
				->to( Target::child( $mapping[ $blog ], $blog ) )
				->do_request(
					'',
					[
						'subsite_report' => $report,
						'reported_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
					],
					self::TIMEOUT
				);
			if ( 404 === $status || 409 === $status ) {
				// The child site is gone, or is not this subsite: the API's
				// next answer about the network says what is monitored.
				Children::forget( $blog );
				continue;
			}
			if ( 401 === $status ) {
				// The network's token was refused: the main site refreshes it
				// first, and the rest goes in a while.
				\Scanfully\Cron\Controller::refresh_access_token_soon();
				if ( function_exists( 'as_schedule_single_action' ) ) {
					as_schedule_single_action( time() + 5 * MINUTE_IN_SECONDS, self::ACTION, [ 'blogs' => array_slice( $blogs, $index ) ], 'scanfully' );
				}
				return;
			}
			if ( $report['woocommerce_active'] && '' === ( $options[ $blog ][ Target::child( '', 0 )->option( \Scanfully\WooCheckout\Controller::OPTION_PROBE_SECRET ) ] ?? '' ) ) {
				self::nudge_woocommerce( $blog );
			}
		}
	}

	/**
	 * A subsite's report.
	 *
	 * @param int                   $blog    The blog id.
	 * @param array<string, string> $row     Its row of the sites table: domain, path and public.
	 * @param array<string, string> $options Its options, as stored.
	 * @param array<string, mixed>  $context The network's active plugins, its language and the main site's scheme.
	 *
	 * @return array{blog_id: int, home_url: string, name: string, public: bool, locale: string, timezone_string: string, gmt_offset: float, permalink_structure: string, active_plugins: string[], network_active_plugins: string[], stylesheet: string, template: string, woocommerce_active: bool}
	 */
	public static function report( int $blog, array $row, array $options, array $context ): array {
		$active  = self::plugin_list( maybe_unserialize( $options['active_plugins'] ?? '' ) );
		$network = is_array( $context['network_plugins'] ?? null ) ? $context['network_plugins'] : [];
		$locale  = trim( $options['WPLANG'] ?? '' );
		$offset  = trim( $options['gmt_offset'] ?? '' );

		return [
			'blog_id'                => $blog,
			'home_url'               => Subsites::address( $options['home'] ?? '', (string) ( $context['scheme'] ?? 'https' ), (string) ( $row['domain'] ?? '' ), (string) ( $row['path'] ?? '/' ) ),
			'name'                   => Subsites::name( $options['blogname'] ?? '' ),
			// Below 0 is a privacy plugin's "members only" and the like.
			'public'                 => (int) ( $row['public'] ?? 1 ) > 0,
			'locale'                 => '' !== $locale ? $locale : (string) ( $context['locale'] ?? 'en_US' ),
			'timezone_string'        => (string) ( $options['timezone_string'] ?? '' ),
			'gmt_offset'             => is_numeric( $offset ) ? (float) $offset : 0.0,
			'permalink_structure'    => (string) ( $options['permalink_structure'] ?? '' ),
			'active_plugins'         => $active,
			'network_active_plugins' => $network,
			'stylesheet'             => (string) ( $options['stylesheet'] ?? '' ),
			'template'               => (string) ( $options['template'] ?? '' ),
			'woocommerce_active'     => in_array( self::WOOCOMMERCE, $active, true ) || in_array( self::WOOCOMMERCE, $network, true ),
		];
	}

	/**
	 * A list of plugin files, checked.
	 *
	 * @param mixed $raw The stored value.
	 *
	 * @return string[]
	 */
	private static function plugin_list( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		return array_values( array_unique( array_filter( $raw, static fn( $file ): bool => is_string( $file ) && '' !== $file ) ) );
	}

	/**
	 * The plugins active for the whole network.
	 *
	 * @return string[]
	 */
	private static function network_plugins(): array {
		$active = get_site_option( 'active_sitewide_plugins', [] );

		return is_array( $active ) ? self::plugin_list( array_keys( $active ) ) : [];
	}

	/**
	 * The network's default language, for a subsite without its own.
	 *
	 * @return string
	 */
	private static function network_locale(): string {
		$locale = get_site_option( 'WPLANG', '' );

		return is_string( $locale ) && '' !== trim( $locale ) ? trim( $locale ) : 'en_US';
	}

	/**
	 * The rows of the sites table of some subsites of this network.
	 *
	 * @param int[] $blogs The blog ids.
	 *
	 * @return array<int, array<string, string>> By blog id: domain, path and public.
	 */
	private static function blogs( array $blogs ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One indexed read of the sites table, without caching every site; the placeholders are built from the count of ids.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT blog_id, domain, path, public FROM {$wpdb->blogs} WHERE site_id = %d AND blog_id IN (" . implode( ', ', array_fill( 0, count( $blogs ), '%d' ) ) . ')',
				(int) get_current_network_id(),
				...$blogs
			),
			ARRAY_A
		);
		// phpcs:enable

		$out = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[ (int) $row['blog_id'] ] = [
				'domain' => (string) $row['domain'],
				'path'   => (string) $row['path'],
				'public' => (string) $row['public'],
			];
		}

		return $out;
	}

	/**
	 * Have a monitored subsite with WooCommerce and no checkout probe secret
	 * for its child site yet sync its WooCommerce config (in its own
	 * context, see WooCheckout\Controller::report()), from its own job
	 * queue. One switch to the subsite, only for this.
	 *
	 * @param int $blog The blog id.
	 *
	 * @return void
	 */
	private static function nudge_woocommerce( int $blog ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		switch_to_blog( $blog );
		try {
			as_enqueue_async_action( \Scanfully\Cron\Controller::ACTION_SYNC_WOOCHECKOUT_CONFIG, [], 'scanfully', true );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Forget what this request noted (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$noted = [];
	}
}
