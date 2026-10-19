<?php
/**
 * The settings change collector class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\SettingsChanges;

use Scanfully\Events\Content\Limiter;
use Scanfully\Events\ContentEvent;
use Scanfully\Options;

/**
 * Class Collector
 *
 * Notes the settings a request changes and reports them at the end of the
 * request, one SiteOptionChanged row each. Every way a setting is saved
 * (the settings screens, the Customizer, the REST API, WP-CLI, plugins)
 * goes through update_option(), whose hooks fire only on a real change, so
 * the request only compares values WordPress passes; page and category
 * names and format examples are added by the background job.
 *
 * Put together here: the two timezone options (one `timezone` row) and the
 * admin email request (`new_admin_email`), which WordPress stores with a
 * confirmation hash that is never sent.
 *
 * On multisite a network admin changes another site's settings after
 * switch_to_blog(); those are noted per site and reported on that site.
 *
 * @package Scanfully\Events\SettingsChanges
 */
final class Collector {

	/**
	 * The settings that need attention: they are reported first and never
	 * dropped by the per-minute cap.
	 */
	public const KEY_SETTINGS = [ 'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role', 'blog_public', 'new_admin_email' ];

	/**
	 * The settings changed in this request, per site: the first old and the
	 * last new value per option, and who changed it.
	 *
	 * @var array<int, array<string, array{old: mixed, new: mixed, user: int}>>
	 */
	private static array $changes = [];

	/**
	 * Per site, the admin email request pending before this request deleted
	 * it.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $request_deleted = [];

	/**
	 * Per site, the update services as stored before this request's update,
	 * while WordPress reads them as empty (see note_updating()); null when
	 * that value wasn't cached.
	 *
	 * @var array<int, string|null>
	 */
	private static array $stored_ping_sites = [];

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_action( 'update_option', [ self::class, 'note_updating' ], 10, 1 );
		add_action( 'updated_option', [ self::class, 'note_updated' ], 10, 3 );
		add_action( 'added_option', [ self::class, 'note_added' ], 10, 2 );
		add_action( 'delete_option', [ self::class, 'note_deleting' ], 10, 1 );
		add_action( 'shutdown', [ self::class, 'flush' ], 1, 0 );
	}

	/**
	 * Forget what was noted (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$changes           = [];
		self::$request_deleted   = [];
		self::$stored_ping_sites = [];
	}

	/**
	 * Whether an option is one this collector notes.
	 *
	 * @param string $option The option name.
	 *
	 * @return bool
	 */
	private static function is_noted( string $option ): bool {
		return Settings::is_reported( $option )
			|| in_array( $option, Settings::TIMEZONE_OPTIONS, true )
			|| Settings::ADMIN_EMAIL_REQUEST === $option;
	}

	/**
	 * Whether this request's option changes are left out: installs and
	 * database upgrades (a core update is its own row) and imports.
	 *
	 * @return bool
	 */
	private static function is_quiet(): bool {
		return wp_installing() || ( defined( 'WP_IMPORTING' ) && WP_IMPORTING );
	}

	/**
	 * Whether WordPress reads the update services as empty: it does while
	 * the site discourages search engines (privacy_ping_filter()).
	 *
	 * @return bool
	 */
	private static function hides_ping_sites(): bool {
		return false !== has_filter( 'option_ping_sites', 'privacy_ping_filter' ) && '0' === get_option( 'blog_public' );
	}

	/**
	 * The update services as stored, from the options cache that
	 * get_option() filled (no query); null when they aren't cached.
	 *
	 * @return string|null
	 */
	private static function stored_ping_sites(): ?string {
		$all   = wp_load_alloptions();
		$value = $all['ping_sites'] ?? wp_cache_get( 'ping_sites', 'options' );

		return is_string( $value ) ? $value : null;
	}

	/**
	 * `update_option` (before the update): while WordPress reads the update
	 * services as empty, the old value update_option() passes on is empty
	 * too, so every service would show as added. The stored value is read
	 * now, before the update replaces it in the cache.
	 *
	 * @param string $option The option name.
	 *
	 * @return void
	 */
	public static function note_updating( $option ): void {
		if ( 'ping_sites' !== $option ) {
			return;
		}

		// An update that changed nothing in the database left one behind.
		unset( self::$stored_ping_sites[ get_current_blog_id() ] );
		if ( ! self::is_quiet() && self::hides_ping_sites() ) {
			self::$stored_ping_sites[ get_current_blog_id() ] = self::stored_ping_sites();
		}
	}

	/**
	 * `updated_option`: note a changed setting.
	 *
	 * @param string $option    The option name.
	 * @param mixed  $old_value The value before.
	 * @param mixed  $value     The value after.
	 *
	 * @return void
	 */
	public static function note_updated( $option, $old_value, $value ): void {
		if ( ! is_string( $option ) || ! self::is_noted( $option ) || self::is_quiet() ) {
			return;
		}

		$blog = get_current_blog_id();
		if ( 'ping_sites' === $option && array_key_exists( $blog, self::$stored_ping_sites ) ) {
			$old_value = self::$stored_ping_sites[ $blog ];
			unset( self::$stored_ping_sites[ $blog ] );
			if ( null === $old_value ) {
				// What it was can't be known: no row rather than a wrong one.
				return;
			}
		}

		self::note( $option, $old_value, $value );
	}

	/**
	 * `added_option`: a setting saved for the first time changed from
	 * nothing.
	 *
	 * @param string $option The option name.
	 * @param mixed  $value  The value.
	 *
	 * @return void
	 */
	public static function note_added( $option, $value ): void {
		if ( ! is_string( $option ) || ! self::is_noted( $option ) || self::is_quiet() ) {
			return;
		}

		self::note( $option, null, $value );
	}

	/**
	 * `delete_option` (before the delete): a setting is emptied (deleting
	 * the site icon's file deletes `site_icon`; REST and WP-CLI can delete
	 * any), or the admin email request is withdrawn or confirmed. The value
	 * is autoloaded, so reading it costs nothing.
	 *
	 * @param string $option The option name.
	 *
	 * @return void
	 */
	public static function note_deleting( $option ): void {
		if ( ! is_string( $option ) || self::is_quiet() ) {
			return;
		}

		if ( Settings::is_reported( $option ) ) {
			$old_value = 'ping_sites' === $option && self::hides_ping_sites() ? self::stored_ping_sites() : get_option( $option );
			self::note( $option, $old_value, null );
			return;
		}

		if ( Settings::ADMIN_EMAIL_REQUEST !== $option ) {
			return;
		}

		$blog    = get_current_blog_id();
		$pending = get_option( Settings::ADMIN_EMAIL_REQUEST );
		if ( is_array( $pending ) && '' !== (string) ( $pending['newemail'] ?? '' ) ) {
			self::$request_deleted[ $blog ] = $pending + [ 'user' => get_current_user_id() ];
		}
		unset( self::$changes[ $blog ][ Settings::ADMIN_EMAIL_REQUEST ] );
	}

	/**
	 * Keep the first old and the last new value of an option.
	 *
	 * @param string $option    The option name.
	 * @param mixed  $old_value The value before.
	 * @param mixed  $value     The value after.
	 *
	 * @return void
	 */
	private static function note( string $option, $old_value, $value ): void {
		$blog = get_current_blog_id();
		if ( isset( self::$changes[ $blog ][ $option ] ) ) {
			self::$changes[ $blog ][ $option ]['new'] = $value;
			return;
		}

		self::$changes[ $blog ][ $option ] = [
			'old'  => $old_value,
			'new'  => $value,
			// A scheduled Customizer change is published by cron as the
			// person who made it, who is signed out again by shutdown.
			'user' => get_current_user_id(),
		];
	}

	/**
	 * The rows for what this request changed on the current site: the key
	 * settings first, then the rest in the order of the settings screens.
	 * A row made by someone other than the signed-in user names them in
	 * `_actor`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows(): array {
		$blog    = get_current_blog_id();
		$changes = self::$changes[ $blog ] ?? [];
		$deleted = self::$request_deleted[ $blog ] ?? null;
		if ( [] === $changes && null === $deleted ) {
			return [];
		}

		$rows = [];
		foreach ( Settings::KINDS as $option => $kind ) {
			if ( ! isset( $changes[ $option ] ) ) {
				continue;
			}
			$row = Settings::change( $option, $changes[ $option ]['old'], $changes[ $option ]['new'] );
			if ( [] !== $row ) {
				$rows[] = self::with_actor( $row, $changes[ $option ]['user'] );
			}
		}

		$timezone = self::timezone_row( $changes );
		if ( [] !== $timezone ) {
			$rows[] = $timezone;
		}

		$request = self::admin_email_row( $changes, $deleted );
		if ( [] !== $request ) {
			$rows[] = $request;
		}

		usort(
			$rows,
			static fn( array $a, array $b ): int => (int) ! in_array( $a['option'], self::KEY_SETTINGS, true ) <=> (int) ! in_array( $b['option'], self::KEY_SETTINGS, true )
		);

		return $rows;
	}

	/**
	 * Report what this request changed, once, at the end of the request,
	 * on each site it changed.
	 *
	 * @return void
	 */
	public static function flush(): void {
		$blogs = array_unique( array_merge( array_keys( self::$changes ), array_keys( self::$request_deleted ) ) );
		if ( [] === $blogs ) {
			return;
		}

		$current = get_current_blog_id();
		foreach ( $blogs as $blog ) {
			$switched = $blog !== $current && function_exists( 'switch_to_blog' ) && switch_to_blog( $blog );
			if ( $blog === $current || $switched ) {
				self::report( self::rows() );
			}
			if ( $switched ) {
				restore_current_blog();
			}
		}
		self::reset();
	}

	/**
	 * Fire the rows of the current site: the key settings always, the rest
	 * within the per-minute cap.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return void
	 */
	private static function report( array $rows ): void {
		if ( [] === $rows || ! Options\Controller::get_options()->is_connected ) {
			return;
		}

		$key   = array_values( array_filter( $rows, static fn( array $row ): bool => in_array( $row['option'], self::KEY_SETTINGS, true ) ) );
		$other = array_values( array_filter( $rows, static fn( array $row ): bool => ! in_array( $row['option'], self::KEY_SETTINGS, true ) ) );
		$room  = [] !== $other ? Limiter::take( Limiter::KIND_SETTING, count( $other ) ) : 0;

		foreach ( array_merge( $key, array_slice( $other, 0, $room ) ) as $row ) {
			do_action( ContentEvent::hook( 'SiteOptionChanged' ), $row ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The hooks start with scanfully_.
		}
	}

	/**
	 * A row naming the person who made the change when that isn't the
	 * signed-in user.
	 *
	 * @param array<string, mixed> $row  The row.
	 * @param int                  $user The user who made the change.
	 *
	 * @return array<string, mixed>
	 */
	private static function with_actor( array $row, int $user ): array {
		if ( $user > 0 && get_current_user_id() !== $user ) {
			$row['_actor'] = $user;
		}

		return $row;
	}

	/**
	 * One row for the timezone, from whichever of its two options changed.
	 *
	 * @param array<string, array{old: mixed, new: mixed, user: int}> $changes The site's changes.
	 *
	 * @return array<string, mixed>
	 */
	private static function timezone_row( array $changes ): array {
		[ $zone_option, $offset_option ] = Settings::TIMEZONE_OPTIONS;
		if ( ! isset( $changes[ $zone_option ] ) && ! isset( $changes[ $offset_option ] ) ) {
			return [];
		}

		$value = static function ( string $option, string $side ) use ( $changes ) {
			return isset( $changes[ $option ] ) ? $changes[ $option ][ $side ] : get_option( $option );
		};

		$old = Settings::timezone( $value( $zone_option, 'old' ), $value( $offset_option, 'old' ) );
		$new = Settings::timezone( $value( $zone_option, 'new' ), $value( $offset_option, 'new' ) );
		if ( $old === $new ) {
			return [];
		}

		return self::with_actor(
			[
				'option' => 'timezone',
				'old'    => $old,
				'new'    => $new,
			],
			( $changes[ $zone_option ] ?? $changes[ $offset_option ] )['user']
		);
	}

	/**
	 * The row for an admin email change someone asked for (WordPress mailed
	 * a confirmation link) or withdrew. A request that is confirmed shows
	 * as the admin email changing.
	 *
	 * @param array<string, array{old: mixed, new: mixed, user: int}> $changes The site's changes.
	 * @param array<string, mixed>|null                               $deleted The request withdrawn or confirmed, or null.
	 *
	 * @return array<string, mixed>
	 */
	private static function admin_email_row( array $changes, ?array $deleted ): array {
		if ( isset( $changes[ Settings::ADMIN_EMAIL_REQUEST ] ) ) {
			$request = $changes[ Settings::ADMIN_EMAIL_REQUEST ]['new'];
			$address = is_array( $request ) ? (string) ( $request['newemail'] ?? '' ) : '';
			if ( '' === $address ) {
				return [];
			}

			return self::with_actor(
				[
					'option' => 'new_admin_email',
					'old'    => (string) get_option( 'admin_email' ),
					'new'    => $address,
				],
				$changes[ Settings::ADMIN_EMAIL_REQUEST ]['user']
			);
		}

		if ( null !== $deleted && ! isset( $changes['admin_email'] ) ) {
			return self::with_actor(
				[
					'option'    => 'new_admin_email',
					'old'       => (string) get_option( 'admin_email' ),
					'new'       => (string) $deleted['newemail'],
					'cancelled' => true,
				],
				(int) $deleted['user']
			);
		}

		return [];
	}
}
