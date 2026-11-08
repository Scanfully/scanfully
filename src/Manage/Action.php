<?php
/**
 * The Manage action base class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A JobFailed message is never printed: it is posted to the API as JSON, cleaned by Failure::clean(), and HTML escaping would garble it.

/**
 * What the actions of Scanfully Manage that change the site share: reading
 * and checking the job's parameters, getting WordPress ready to change
 * files, WordPress's upgraders with the silent skin, finding a plugin or a
 * theme and its version on disk, turning an upgrader's failure into an
 * error class, and the result fields every action answers (versions,
 * duration, the inventory after the job, the ids of the activity events the
 * job caused and the log).
 */
abstract class Action {

	/**
	 * Seconds PHP may run for an action that changes files, when the host
	 * lets the plugin say.
	 */
	protected const TIME_LIMIT = 15 * MINUTE_IN_SECONDS;

	/**
	 * What a slug or a stylesheet looks like: letters, digits, dots, dashes
	 * and underscores, never starting with a dot.
	 */
	protected const SLUG = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}$/';

	/**
	 * What a version looks like.
	 */
	protected const VERSION = '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$/';

	/**
	 * For tests: builds the upgrader of a kind (plugin, theme, core,
	 * translations) with the skin. WordPress's own upgraders when null.
	 *
	 * @var callable|null
	 */
	public static $upgraders = null;

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
	protected static function param( array $params, string $name, string $pattern, bool $required ): string {
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
	protected static function prepare(): void {
		if ( function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'scanfully_manage' ) ) {
			throw new JobFailed( 'not_supported', 'WordPress may not change files on this site (DISALLOW_FILE_MODS is set in wp-config.php, or a plugin forbids it), so it cannot install, update or delete anything.' );
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
	 * Load WordPress's admin functions (plugins, themes, files, installs)
	 * and upgraders, as its background updater does.
	 *
	 * @return void
	 */
	protected static function load(): void {
		$needed = [ 'get_core_updates', 'get_plugins', 'request_filesystem_credentials', 'activate_plugin', 'delete_plugins', 'delete_theme', 'plugins_api', 'themes_api' ];
		if ( [] !== array_filter( $needed, static fn( string $name ): bool => ! function_exists( $name ) ) ) {
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
	protected static function upgrader( string $kind, UpgraderSkin $skin ): object {
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
	protected static function plugin_file( string $slug ): ?string {
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( false );
		}
		foreach ( array_keys( get_plugins() ) as $file ) {
			$file = (string) $file;
			if ( self::plugin_slug( $file ) === $slug ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * The slug of a plugin file: its folder, or the file name of a
	 * single-file plugin.
	 *
	 * @param string $file The plugin file, relative to the plugins folder.
	 *
	 * @return string
	 */
	protected static function plugin_slug( string $file ): string {
		$folder = dirname( $file );

		return '.' === $folder ? basename( $file, '.php' ) : $folder;
	}

	/**
	 * A plugin's version, read from its file now; '' when the file is gone.
	 *
	 * @param string $file The plugin file, relative to the plugins folder.
	 *
	 * @return string
	 */
	protected static function plugin_version( string $file ): string {
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
	protected static function theme_version( string $stylesheet ): string {
		$path = get_theme_root( $stylesheet ) . '/' . $stylesheet . '/style.css';
		clearstatcache( true, $path );
		if ( ! is_file( $path ) ) {
			return '';
		}

		return (string) ( get_file_data( $path, [ 'Version' => 'Version' ] )['Version'] ?? '' );
	}

	/**
	 * The error class and message of an upgrader's answer that is not a
	 * success.
	 *
	 * @param mixed        $result The upgrader's answer.
	 * @param UpgraderSkin $skin   The skin.
	 * @param string       $what   What ran, for the message: update, install.
	 *
	 * @return array{class: string, message: string}
	 */
	protected static function failure_of( $result, UpgraderSkin $skin, string $what = 'update' ): array {
		$error = is_wp_error( $result ) ? $result : $skin->error_result();
		if ( null !== $error ) {
			return Failure::of( $error );
		}
		$filesystem = self::filesystem_problem( $what );
		if ( null !== $filesystem ) {
			return $filesystem;
		}
		$messages = $skin->messages();
		$last     = [] === $messages ? '' : (string) end( $messages );

		return [
			'class'   => 'other',
			'message' => '' !== $last ? sprintf( 'The %s did not complete: %s', $what, Failure::clean( $last ) ) : sprintf( 'The %s did not complete, and WordPress gave no reason.', $what ),
		];
	}

	/**
	 * The problem when WordPress cannot write files without asking for
	 * credentials, or null when it writes them directly.
	 *
	 * @param string $what What ran, for the message.
	 *
	 * @return array{class: string, message: string}|null
	 */
	private static function filesystem_problem( string $what ): ?array {
		$method = function_exists( 'get_filesystem_method' ) ? get_filesystem_method() : 'direct';
		if ( 'direct' === $method ) {
			return null;
		}

		return [
			'class'   => 'filesystem',
			'message' => sprintf( 'WordPress cannot write the files directly here and needs %s credentials, which wp-config.php does not define, so the %s could not run.', 'ssh2' === $method ? 'SSH' : 'FTP', $what ),
		];
	}

	/**
	 * Connect WordPress's filesystem as its background updater does, with
	 * the credentials wp-config.php defines and never a form, before a
	 * function that would otherwise print WordPress's credentials form and
	 * stop the request (delete_plugins(), delete_theme()).
	 *
	 * @param string $what What runs, for the message: the delete.
	 *
	 * @return void
	 * @throws JobFailed When WordPress cannot write the files without asking.
	 */
	protected static function connect_filesystem( string $what ): void {
		ob_start();
		$credentials = request_filesystem_credentials( '', '', false, '', null );
		ob_end_clean();
		if ( false !== $credentials && WP_Filesystem( $credentials ) ) {
			return;
		}
		$problem = self::filesystem_problem( $what );
		throw new JobFailed( 'filesystem', null !== $problem ? $problem['message'] : sprintf( 'WordPress could not connect to the site\'s files, so the %s could not run.', $what ) );
	}

	/**
	 * Throw the failure of an upgrader with what is known.
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
	protected static function fail( array $failure, string $previous, string $after, UpgraderSkin $skin, JobRun $run, float $started, array $extra = [] ): void {
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
	protected static function partial( string $previous, string $after, array $log, JobRun $run, float $started, array $extra = [] ): array {
		try {
			$inventory = Inventory::collect();
		} catch ( \Throwable $e ) {
			$inventory = null;
			$log[]     = 'The inventory could not be read after the job: ' . $e->getMessage();
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
