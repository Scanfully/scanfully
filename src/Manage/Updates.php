<?php
/**
 * The Manage updates class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

use Scanfully\Main;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A JobFailed message is never printed: it is posted to the API as JSON, cleaned by Failure::clean(), and HTML escaping would garble it.

/**
 * The four update actions of Scanfully Manage: a plugin, a theme, WordPress
 * itself and the translations, each through WordPress's own upgrader, so
 * premium plugins update wherever WordPress's update check knows them.
 *
 * Each action refreshes WordPress's update data first, finds the version
 * asked for among what WordPress offers (the newest when none is named; for
 * WordPress, same_line is the newest release of the installed major.minor),
 * never guesses a package URL, runs the upgrader with a silent skin, and
 * answers the versions before and after, the post-action inventory, the
 * ids of the activity events the update caused and the upgrader's log. A
 * failure throws JobFailed with one of the API's error classes and what is
 * known at that point.
 *
 * A version already installed is not an error: the job's goal holds, and the
 * result says nothing changed.
 */
final class Updates {

	/**
	 * Seconds PHP may run for an update, when the host lets the plugin say.
	 */
	private const TIME_LIMIT = 15 * MINUTE_IN_SECONDS;

	/**
	 * For tests: builds the upgrader of a kind (plugin, theme, core,
	 * translations) with the skin. WordPress's own upgraders when null.
	 *
	 * @var callable|null
	 */
	public static $upgraders = null;

	/**
	 * For tests: WordPress's version file. wp-includes/version.php when null.
	 *
	 * @var string|null
	 */
	public static $version_file = null;

	/**
	 * Update a plugin, by its slug (the folder, or the file name of a
	 * single-file plugin), to target_version or the newest version offered.
	 *
	 * @param array    $params The job's parameters: slug, target_version.
	 * @param callable $beat   Sends a heartbeat when one is due.
	 * @param JobRun   $run    The run.
	 *
	 * @return array The result.
	 * @throws JobFailed When the update did not happen.
	 */
	public static function plugin( array $params, callable $beat, JobRun $run ): array {
		unset( $beat );
		$started = microtime( true );
		$slug    = self::param( $params, 'slug', '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}$/', true );
		$target  = self::param( $params, 'target_version', '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$/', false );
		self::prepare();

		$file = self::plugin_file( $slug );
		if ( null === $file ) {
			throw new JobFailed( 'not_available', sprintf( 'The plugin "%s" is not installed on the site.', $slug ) );
		}
		if ( Main::is_own_plugin( $file ) ) {
			throw new JobFailed( 'not_supported', 'Scanfully does not update its own plugin through a job yet: update it in WordPress.' );
		}
		$previous = self::plugin_version( $file );
		if ( '' !== $target && $target === $previous ) {
			return self::done( $previous, $previous, [ sprintf( 'The plugin is at version %s already: nothing to do.', $previous ) ], $run, $started );
		}

		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}
		wp_update_plugins();
		$offer   = self::offer( get_site_transient( 'update_plugins' ), $file );
		$offered = (string) ( $offer['new_version'] ?? '' );
		self::check_offer( $offer, $target, $previous, $run, $started, 'the plugin' );
		if ( null === $offer || '' === $offered ) {
			return self::done( $previous, $previous, [ sprintf( 'WordPress offers no newer version of the plugin than %s: nothing to do.', $previous ) ], $run, $started );
		}

		// The upgrader switches an active plugin off for the update outside
		// of WP-Cron and does not switch it on again (WordPress's update
		// screen does that from the browser): Scanfully does, once the new
		// files are complete, also after a fatal.
		$active  = is_plugin_active( $file );
		$network = $active && is_multisite() && is_plugin_active_for_network( $file );
		$restore = self::restorer( $file, $active, $network, [ $offered, $previous ], $run );
		$run->on_exit( $restore );

		$skin   = new UpgraderSkin( $run );
		$result = self::upgrader( 'plugin', $skin )->upgrade( $file );
		$after  = self::plugin_version( $file );
		$back   = $restore();
		if ( true !== $result ) {
			$failure = self::failure_of( $result, $skin );
			if ( '' === $after ) {
				$failure['message'] .= ' The plugin\'s files are missing after the failed update.';
			}
			self::fail( $failure, $previous, $after, $skin, $run, $started );
		}
		if ( null !== $back ) {
			self::fail( $back, $previous, $after, $skin, $run, $started );
		}
		if ( $after !== $offered ) {
			self::fail( self::wrong_version( $offered, $after ), $previous, $after, $skin, $run, $started );
		}

		return self::done( $previous, $after, $skin->messages(), $run, $started );
	}

	/**
	 * Update a theme, by its stylesheet (folder), to target_version or the
	 * newest version offered.
	 *
	 * @param array    $params The job's parameters: stylesheet, target_version.
	 * @param callable $beat   Sends a heartbeat when one is due.
	 * @param JobRun   $run    The run.
	 *
	 * @return array The result.
	 * @throws JobFailed When the update did not happen.
	 */
	public static function theme( array $params, callable $beat, JobRun $run ): array {
		unset( $beat );
		$started    = microtime( true );
		$stylesheet = self::param( $params, 'stylesheet', '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}(\/[A-Za-z0-9][A-Za-z0-9._-]{0,199})?$/', true );
		$target     = self::param( $params, 'target_version', '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$/', false );
		self::prepare();

		$previous = self::theme_version( $stylesheet );
		if ( '' === $previous ) {
			throw new JobFailed( 'not_available', sprintf( 'The theme "%s" is not installed on the site.', $stylesheet ) );
		}
		if ( '' !== $target && $target === $previous ) {
			return self::done( $previous, $previous, [ sprintf( 'The theme is at version %s already: nothing to do.', $previous ) ], $run, $started );
		}

		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			wp_clean_themes_cache( true );
		}
		wp_update_themes();
		$offer   = self::offer( get_site_transient( 'update_themes' ), $stylesheet );
		$offered = (string) ( $offer['new_version'] ?? '' );
		self::check_offer( $offer, $target, $previous, $run, $started, 'the theme' );
		if ( null === $offer || '' === $offered ) {
			return self::done( $previous, $previous, [ sprintf( 'WordPress offers no newer version of the theme than %s: nothing to do.', $previous ) ], $run, $started );
		}

		$skin   = new UpgraderSkin( $run );
		$result = self::upgrader( 'theme', $skin )->upgrade( $stylesheet );
		$after  = self::theme_version( $stylesheet );
		if ( true !== $result ) {
			$failure = self::failure_of( $result, $skin );
			if ( '' === $after ) {
				$failure['message'] .= ' The theme\'s files are missing after the failed update.';
			}
			self::fail( $failure, $previous, $after, $skin, $run, $started );
		}
		if ( $after !== $offered ) {
			self::fail( self::wrong_version( $offered, $after ), $previous, $after, $skin, $run, $started );
		}

		return self::done( $previous, $after, $skin->messages(), $run, $started );
	}

	/**
	 * Update WordPress: target latest (the default), same_line (the newest
	 * release of the installed major.minor) or a version WordPress offers.
	 *
	 * @param array    $params The job's parameters: target.
	 * @param callable $beat   Sends a heartbeat when one is due.
	 * @param JobRun   $run    The run.
	 *
	 * @return array The result.
	 * @throws JobFailed When the update did not happen.
	 */
	public static function core( array $params, callable $beat, JobRun $run ): array {
		unset( $beat );
		$started = microtime( true );
		$target  = self::param( $params, 'target', '/^(latest|same_line|[0-9]+\.[0-9]+(\.[0-9]+)?([.+_-][0-9A-Za-z]+)*)$/', false );
		$target  = '' === $target ? 'latest' : $target;
		self::prepare();

		$previous = self::core_version();
		wp_version_check( [], true );
		$offers = get_core_updates( [ 'dismissed' => true ] );
		$offer  = self::core_offer( is_array( $offers ) ? $offers : [], $target, $previous, (string) get_locale() );
		$wanted = null === $offer ? '' : (string) ( $offer->current ?? '' );
		if ( '' === $wanted || $wanted === $previous ) {
			if ( $wanted === $previous || $target === $previous ) {
				return self::done( $previous, $previous, [ sprintf( 'WordPress is at version %s already: nothing to do.', $previous ) ], $run, $started );
			}
			throw new JobFailed(
				'not_available',
				'same_line' === $target
					? sprintf( 'WordPress offers no newer release of %s than %s.', self::major_minor( $previous ), $previous )
					: ( 'latest' === $target ? 'WordPress offers no update of itself for this site right now.' : sprintf( 'WordPress offers no update to %s for this site.', $target ) ),
				self::partial( $previous, $previous, [], $run, $started )
			);
		}
		if ( version_compare( $wanted, $previous, '<' ) ) {
			throw new JobFailed( 'not_available', sprintf( 'WordPress %s is older than the installed %s: Scanfully does not downgrade WordPress.', $wanted, $previous ), self::partial( $previous, $previous, [], $run, $started ) );
		}

		$skin   = new UpgraderSkin( $run );
		$result = self::upgrader( 'core', $skin )->upgrade( $offer );
		$after  = self::core_version();
		if ( ! is_string( $result ) || '' === $result ) {
			self::fail( self::failure_of( $result, $skin ), $previous, $after, $skin, $run, $started );
		}
		if ( $after !== $wanted ) {
			self::fail( self::wrong_version( $wanted, $after ), $previous, $after, $skin, $run, $started );
		}

		return self::done( $previous, $after, $skin->messages(), $run, $started );
	}

	/**
	 * Update every language pack WordPress offers, for WordPress itself, the
	 * plugins and the themes.
	 *
	 * @param array    $params The job's parameters: none.
	 * @param callable $beat   Sends a heartbeat when one is due.
	 * @param JobRun   $run    The run.
	 *
	 * @return array The result, with the packs under translations.
	 * @throws JobFailed When a pack did not update.
	 */
	public static function translations( array $params, callable $beat, JobRun $run ): array {
		unset( $params, $beat );
		$started = microtime( true );
		self::prepare();

		wp_version_check( [], true );
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}
		wp_update_plugins();
		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			wp_clean_themes_cache( true );
		}
		wp_update_themes();
		$updates = wp_get_translation_updates();
		if ( [] === $updates ) {
			return self::done( '', '', [ 'WordPress offers no translation updates: nothing to do.' ], $run, $started, [ 'translations' => [] ] );
		}

		$skin    = new UpgraderSkin( $run );
		$results = self::upgrader( 'translations', $skin )->bulk_upgrade( $updates, [ 'clear_update_cache' => true ] );
		if ( ! is_array( $results ) ) {
			self::fail( self::failure_of( $results, $skin ), '', '', $skin, $run, $started, [ 'translations' => [] ] );
		}

		$packs   = [];
		$failure = null;
		foreach ( array_values( $updates ) as $i => $update ) {
			$outcome = $results[ $i ] ?? null;
			$ok      = null !== $outcome && false !== $outcome && ! is_wp_error( $outcome );
			$pack    = self::translation_of( $update );
			$pack['updated'] = $ok;
			if ( ! $ok && null === $failure ) {
				$failure = self::failure_of( $outcome, $skin );
			}
			$packs[] = $pack;
		}
		if ( null !== $failure ) {
			$failure['message'] = sprintf( '%d of %d language packs did not update. ', count( array_filter( $packs, static fn( array $p ): bool => ! $p['updated'] ) ), count( $packs ) ) . $failure['message'];
			self::fail( $failure, '', '', $skin, $run, $started, [ 'translations' => $packs ] );
		}

		return self::done( '', '', $skin->messages(), $run, $started, [ 'translations' => $packs ] );
	}

	/**
	 * A language pack as the result and the inventory list it.
	 *
	 * @param mixed $update A language pack update from wp_get_translation_updates().
	 *
	 * @return array{type: string, slug: string, language: string, version: string}
	 */
	public static function translation_of( $update ): array {
		$update = is_object( $update ) ? (array) $update : ( is_array( $update ) ? $update : [] );

		return [
			'type'     => (string) ( $update['type'] ?? '' ),
			'slug'     => (string) ( $update['slug'] ?? '' ),
			'language' => (string) ( $update['language'] ?? '' ),
			'version'  => (string) ( $update['version'] ?? '' ),
		];
	}

	/**
	 * WordPress's version as its version file says now, so a core update
	 * made in this request shows the new one.
	 *
	 * @return string
	 */
	public static function core_version(): string {
		$file = self::$version_file ?? ( defined( 'ABSPATH' ) ? ABSPATH : '' ) . ( defined( 'WPINC' ) ? WPINC : 'wp-includes' ) . '/version.php';
		if ( is_readable( $file ) ) {
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
			if ( 1 === preg_match( '/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $match ) ) {
				return $match[1];
			}
		}

		return function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '';
	}

	/**
	 * The core update to run among WordPress's offers, preferring the site's
	 * language and then en_US.
	 *
	 * @param array  $offers   The offers of get_core_updates().
	 * @param string $target   latest, same_line or a version.
	 * @param string $previous The installed version.
	 * @param string $locale   The site's language.
	 *
	 * @return object|null
	 */
	public static function core_offer( array $offers, string $target, string $previous, string $locale ): ?object {
		$best = null;
		foreach ( $offers as $offer ) {
			if ( ! is_object( $offer ) || ! isset( $offer->current ) || ! in_array( $offer->response ?? '', [ 'upgrade', 'latest', 'autoupdate' ], true ) ) {
				continue;
			}
			$version = (string) $offer->current;
			if ( 'same_line' === $target ) {
				if ( self::major_minor( $version ) !== self::major_minor( $previous ) || version_compare( $version, $previous, '<' ) ) {
					continue;
				}
			} elseif ( 'latest' !== $target && $version !== $target ) {
				continue;
			}
			if ( null === $best || version_compare( $version, (string) $best->current, '>' )
				|| ( $version === (string) $best->current && self::locale_rank( $offer, $locale ) < self::locale_rank( $best, $locale ) ) ) {
				$best = $offer;
			}
		}

		return $best;
	}

	/**
	 * How well an offer's language fits: the site's first, then en_US.
	 *
	 * @param object $offer  The offer.
	 * @param string $locale The site's language.
	 *
	 * @return int
	 */
	private static function locale_rank( object $offer, string $locale ): int {
		$language = (string) ( $offer->locale ?? '' );
		if ( $language === $locale ) {
			return 0;
		}

		return 'en_US' === $language ? 1 : 2;
	}

	/**
	 * The major.minor of a version: 6.9 of 6.9.3.
	 *
	 * @param string $version The version.
	 *
	 * @return string
	 */
	private static function major_minor( string $version ): string {
		return implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );
	}

	/**
	 * A parameter of the job, checked.
	 *
	 * @param array  $params   The parameters.
	 * @param string $name     The name.
	 * @param string $pattern  What it must look like.
	 * @param bool   $required Whether it must be there.
	 *
	 * @return string The value, or '' when it is optional and absent.
	 * @throws JobFailed When it is missing or malformed.
	 */
	private static function param( array $params, string $name, string $pattern, bool $required ): string {
		$value = $params[ $name ] ?? null;
		if ( null === $value || '' === $value ) {
			if ( $required ) {
				throw new JobFailed( 'other', sprintf( 'The job has no %s.', $name ) );
			}
			return '';
		}
		if ( ! is_string( $value ) || 1 !== preg_match( $pattern, $value ) || false !== strpos( $value, '..' ) ) {
			throw new JobFailed( 'other', sprintf( 'The job\'s %s is not valid.', $name ) );
		}

		return $value;
	}

	/**
	 * Get WordPress ready to change files: allowed to, its upgrader code
	 * loaded, and as much time and memory as the host lets the plugin ask.
	 *
	 * @return void
	 * @throws JobFailed When WordPress may not change files on the site.
	 */
	private static function prepare(): void {
		if ( function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'scanfully_manage' ) ) {
			throw new JobFailed( 'not_supported', 'WordPress may not change files on this site (DISALLOW_FILE_MODS is set in wp-config.php, or a plugin forbids it), so it cannot update anything.' );
		}
		self::load();
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( self::TIME_LIMIT );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
	}

	/**
	 * Load WordPress's admin functions and upgraders, as its background
	 * updater does.
	 *
	 * @return void
	 */
	private static function load(): void {
		if ( ! function_exists( 'get_core_updates' ) || ! function_exists( 'get_plugins' ) || ! function_exists( 'request_filesystem_credentials' ) ) {
			include_once ABSPATH . 'wp-admin/includes/admin.php';
		}
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-includes/update.php';
		}
		if ( ! class_exists( 'WP_Upgrader', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
	}

	/**
	 * The upgrader of a kind, with the skin.
	 *
	 * @param string       $kind plugin, theme, core or translations.
	 * @param UpgraderSkin $skin The skin.
	 *
	 * @return object
	 */
	private static function upgrader( string $kind, UpgraderSkin $skin ): object {
		if ( null !== self::$upgraders ) {
			return call_user_func( self::$upgraders, $kind, $skin );
		}
		switch ( $kind ) {
			case 'plugin':
				return new \Plugin_Upgrader( $skin );
			case 'theme':
				return new \Theme_Upgrader( $skin );
			case 'core':
				return new \Core_Upgrader( $skin );
			default:
				return new \Language_Pack_Upgrader( $skin );
		}
	}

	/**
	 * The file of a plugin, relative to the plugins folder, by its slug: the
	 * folder, or the file name of a single-file plugin, as the site data
	 * report names it.
	 *
	 * @param string $slug The slug.
	 *
	 * @return string|null
	 */
	private static function plugin_file( string $slug ): ?string {
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( false );
		}
		foreach ( array_keys( get_plugins() ) as $file ) {
			$file   = (string) $file;
			$folder = dirname( $file );
			if ( ( '.' === $folder ? basename( $file, '.php' ) : $folder ) === $slug ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * A plugin's version, read from its file now; '' when the file is gone.
	 *
	 * @param string $file The plugin file, relative to the plugins folder.
	 *
	 * @return string
	 */
	private static function plugin_version( string $file ): string {
		$path = WP_PLUGIN_DIR . '/' . $file;
		clearstatcache( true, $path );
		if ( ! is_file( $path ) ) {
			return '';
		}

		return (string) ( get_file_data( $path, [ 'Version' => 'Version' ] )['Version'] ?? '' );
	}

	/**
	 * A theme's version, read from its style.css now; '' when it is gone.
	 *
	 * @param string $stylesheet The stylesheet.
	 *
	 * @return string
	 */
	private static function theme_version( string $stylesheet ): string {
		$path = get_theme_root( $stylesheet ) . '/' . $stylesheet . '/style.css';
		clearstatcache( true, $path );
		if ( ! is_file( $path ) ) {
			return '';
		}

		return (string) ( get_file_data( $path, [ 'Version' => 'Version' ] )['Version'] ?? '' );
	}

	/**
	 * The update WordPress offers for a plugin or theme, as an array, or
	 * null when it offers none.
	 *
	 * @param mixed  $transient The update_plugins or update_themes site transient.
	 * @param string $key       The plugin file or theme stylesheet.
	 *
	 * @return array|null
	 */
	private static function offer( $transient, string $key ): ?array {
		if ( ! is_object( $transient ) || ! isset( $transient->response ) || ! is_array( $transient->response ) || ! isset( $transient->response[ $key ] ) ) {
			return null;
		}
		$offer = $transient->response[ $key ];

		return is_object( $offer ) ? get_object_vars( $offer ) : ( is_array( $offer ) ? $offer : null );
	}

	/**
	 * Check an offer against the version asked for: an explicit version must
	 * be the one offered, and the offer must come with a package.
	 *
	 * @param array|null $offer    The offer.
	 * @param string     $target   The version asked for, or ''.
	 * @param string     $previous The installed version.
	 * @param JobRun     $run      The run.
	 * @param float      $started  When the action started.
	 * @param string     $what     What is updated, for the message.
	 *
	 * @return void
	 * @throws JobFailed When the version asked for is not offered or has no package.
	 */
	private static function check_offer( ?array $offer, string $target, string $previous, JobRun $run, float $started, string $what ): void {
		$offered = (string) ( $offer['new_version'] ?? '' );
		if ( '' !== $target && $offered !== $target ) {
			throw new JobFailed(
				'not_available',
				'' === $offered
					? sprintf( 'WordPress offers no update of %s to %s.', $what, $target )
					: sprintf( 'WordPress offers %s of %s, not %s.', $offered, $what, $target ),
				self::partial( $previous, $previous, [], $run, $started )
			);
		}
		if ( null !== $offer && '' !== $offered && empty( $offer['package'] ) ) {
			throw new JobFailed(
				'license',
				sprintf( 'WordPress knows version %s of %s but has no download for it: the update needs a license key or a sign-in the site does not have.', $offered, $what ),
				self::partial( $previous, $previous, [], $run, $started )
			);
		}
	}

	/**
	 * The clean-up that switches a plugin on again when the upgrader
	 * switched it off: only when it was active before, is inactive now, and
	 * its files are complete (its version is the new one, or the old one
	 * when WordPress put the old files back). Runs once; returns a problem
	 * when the plugin stays off.
	 *
	 * @param string   $file     The plugin file.
	 * @param bool     $active   Whether it was active before.
	 * @param bool     $network  Whether it was active network-wide.
	 * @param string[] $versions The versions that mean complete files.
	 * @param JobRun   $run      The run.
	 *
	 * @return callable
	 */
	private static function restorer( string $file, bool $active, bool $network, array $versions, JobRun $run ): callable {
		$done = false;

		return static function () use ( &$done, $file, $active, $network, $versions, $run ): ?array {
			if ( $done || ! $active ) {
				return null;
			}
			$done = true;
			if ( $network ? is_plugin_active_for_network( $file ) : is_plugin_active( $file ) ) {
				return null;
			}
			$version = self::plugin_version( $file );
			if ( '' === $version ) {
				return [
					'class'   => 'filesystem',
					'message' => 'The plugin\'s files are missing after the update, so it is no longer active.',
				];
			}
			if ( ! in_array( $version, $versions, true ) ) {
				return [
					'class'   => 'package',
					'message' => sprintf( 'The plugin was switched off for the update, and its files now say version %s, neither the old nor the new one, so Scanfully did not switch it on again.', $version ),
				];
			}
			$result = activate_plugin( $file, '', $network, true );
			if ( is_wp_error( $result ) ) {
				return [
					'class'   => 'fatal',
					'message' => 'The plugin was switched off for the update and could not be switched on again: ' . $result->get_error_message(),
				];
			}
			$run->log( 'Scanfully switched the plugin on again after the update, as it was active before.' );

			return null;
		};
	}

	/**
	 * The error class and message of an upgrader's answer that is not a
	 * success.
	 *
	 * @param mixed        $result The upgrader's answer.
	 * @param UpgraderSkin $skin   The skin.
	 *
	 * @return array{class: string, message: string}
	 */
	private static function failure_of( $result, UpgraderSkin $skin ): array {
		$error = is_wp_error( $result ) ? $result : $skin->error_result();
		if ( null !== $error ) {
			return Failure::of( $error );
		}
		$method = function_exists( 'get_filesystem_method' ) ? get_filesystem_method() : 'direct';
		if ( 'direct' !== $method ) {
			return [
				'class'   => 'filesystem',
				'message' => sprintf( 'WordPress cannot write the files directly here and needs %s credentials, which wp-config.php does not define, so it could not update.', 'ssh2' === $method ? 'SSH' : 'FTP' ),
			];
		}
		$messages = $skin->messages();
		$last     = [] === $messages ? '' : (string) end( $messages );

		return [
			'class'   => 'other',
			'message' => '' !== $last ? 'The update did not complete: ' . Failure::clean( $last ) : 'The update did not complete, and WordPress gave no reason.',
		];
	}

	/**
	 * The problem of an update WordPress reported done whose files say
	 * another version.
	 *
	 * @param string $wanted The version asked for.
	 * @param string $after  The version now.
	 *
	 * @return array{class: string, message: string}
	 */
	private static function wrong_version( string $wanted, string $after ): array {
		return [
			'class'   => 'package',
			'message' => sprintf( 'WordPress reported the update done, but the files say version %s instead of %s.', '' === $after ? '(none)' : $after, $wanted ),
		];
	}

	/**
	 * The result of an update: the versions, how long it took, the
	 * inventory now, the activity events it caused and the log.
	 *
	 * @param string   $previous The version before.
	 * @param string   $after    The version now.
	 * @param string[] $log      The upgrader's messages.
	 * @param JobRun   $run      The run.
	 * @param float    $started  When the action started.
	 * @param array    $extra    More fields.
	 *
	 * @return array
	 */
	private static function done( string $previous, string $after, array $log, JobRun $run, float $started, array $extra = [] ): array {
		return self::partial( $previous, $after, $log, $run, $started, $extra );
	}

	/**
	 * Throw the failure of an update with what is known.
	 *
	 * @param array{class: string, message: string} $failure  The failure.
	 * @param string                                $previous The version before.
	 * @param string                                $after    The version now.
	 * @param UpgraderSkin                          $skin     The skin.
	 * @param JobRun                                $run      The run.
	 * @param float                                 $started  When the action started.
	 * @param array                                 $extra    More fields.
	 *
	 * @return void
	 * @throws JobFailed Always.
	 */
	private static function fail( array $failure, string $previous, string $after, UpgraderSkin $skin, JobRun $run, float $started, array $extra = [] ): void {
		throw new JobFailed( $failure['class'], $failure['message'], self::partial( $previous, $after, $skin->messages(), $run, $started, $extra ) );
	}

	/**
	 * The result fields: versions, duration, inventory, activity event ids
	 * and log. The inventory is read last, after the action's changes; when
	 * it cannot be read the result says so instead of failing.
	 *
	 * @param string   $previous The version before.
	 * @param string   $after    The version now.
	 * @param string[] $log      The upgrader's messages.
	 * @param JobRun   $run      The run.
	 * @param float    $started  When the action started.
	 * @param array    $extra    More fields.
	 *
	 * @return array
	 */
	private static function partial( string $previous, string $after, array $log, JobRun $run, float $started, array $extra = [] ): array {
		try {
			$inventory = Inventory::collect();
		} catch ( \Throwable $e ) {
			$inventory = null;
			$log[]     = 'The inventory could not be read after the update: ' . $e->getMessage();
		}

		return array_merge(
			[
				'previous_version'   => $previous,
				'new_version'        => $after,
				'duration_ms'        => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'inventory'          => $inventory,
				'activity_event_ids' => $run->activity_ids(),
				'log'                => Failure::log( $log ),
			],
			$extra
		);
	}
}
