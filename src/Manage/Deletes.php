<?php
/**
 * The Manage deletes class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

use Scanfully\Main;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A JobFailed message is never printed: it is posted to the API as JSON, cleaned by Failure::clean(), and HTML escaping would garble it.

/**
 * The two delete actions of Scanfully Manage: a plugin and a theme, through
 * WordPress's own delete_plugins() and delete_theme(), so a plugin's
 * uninstall code runs and its translations go with it, as in WordPress's
 * admin.
 *
 * Only what is not in use is deleted, checked on the site when the job
 * runs (Scanfully checked it when the job was queued, and the site may have
 * changed since): a plugin that is switched on is refused as plugin_active,
 * a theme the site uses, or the parent of the theme it uses, as
 * theme_in_use. The Scanfully plugin itself is never deleted by a job.
 * Software that is gone already is not an error: the result says nothing
 * changed.
 */
final class Deletes extends Action {

	/**
	 * Delete a plugin that is switched off, by its slug.
	 *
	 * @param array    $params The job's parameters: slug.
	 * @param callable $beat   Sends a heartbeat when one is due.
	 * @param JobRun   $run    The run.
	 *
	 * @return array The result.
	 * @throws JobFailed When the plugin is still there afterwards, or may not be deleted.
	 */
	public static function plugin( array $params, callable $beat, JobRun $run ): array {
		unset( $beat );
		$started = microtime( true );
		$slug    = self::param( $params, 'slug', self::SLUG, true );
		self::prepare();

		$extra = [ 'slug' => $slug ];
		$file  = self::plugin_file( $slug );
		if ( null === $file ) {
			return self::partial( '', '', [ 'The plugin is not installed: nothing to do.' ], $run, $started, $extra );
		}
		if ( Main::is_own_plugin( $file ) ) {
			throw new JobFailed( 'not_supported', 'Scanfully never deletes its own plugin through a job: that would end Scanfully Manage on the site. Delete it in WordPress.' );
		}
		$version = self::plugin_version( $file );
		if ( is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ) ) {
			throw new JobFailed( 'plugin_active', 'The plugin is switched on, so Scanfully did not delete it. Switch it off first.', self::partial( $version, $version, [], $run, $started, $extra ) );
		}
		self::connect_filesystem( 'delete' );

		try {
			$result = delete_plugins( [ $file ] );
		} catch ( \Throwable $e ) {
			$after = self::plugin_version( $file );
			throw new JobFailed(
				'fatal',
				'A PHP error stopped the delete (the plugin\'s uninstall code runs first): ' . $e->getMessage() . ( '' === $after ? '' : ' The plugin\'s files are still there.' ),
				self::partial( $version, $after, [], $run, $started, $extra )
			);
		}

		$folder = '.' === dirname( $file ) ? WP_PLUGIN_DIR . '/' . $file : WP_PLUGIN_DIR . '/' . dirname( $file );

		return self::deleted( $result, $folder, $version, 'plugin', $run, $started, $extra );
	}

	/**
	 * Delete a theme the site does not use, by its stylesheet.
	 *
	 * @param array    $params The job's parameters: stylesheet.
	 * @param callable $beat   Sends a heartbeat when one is due.
	 * @param JobRun   $run    The run.
	 *
	 * @return array The result.
	 * @throws JobFailed When the theme is still there afterwards, or may not be deleted.
	 */
	public static function theme( array $params, callable $beat, JobRun $run ): array {
		unset( $beat );
		$started    = microtime( true );
		$stylesheet = self::param( $params, 'stylesheet', self::SLUG, true );
		self::prepare();

		$extra   = [ 'stylesheet' => $stylesheet ];
		$version = self::theme_version( $stylesheet );
		$folder  = get_theme_root( $stylesheet ) . '/' . $stylesheet;
		if ( '' === $version && ! is_dir( $folder ) ) {
			return self::partial( '', '', [ 'The theme is not installed: nothing to do.' ], $run, $started, $extra );
		}
		if ( (string) get_option( 'stylesheet' ) === $stylesheet ) {
			throw new JobFailed( 'theme_in_use', 'The site uses the theme, so Scanfully did not delete it. Switch to another theme first.', self::partial( $version, $version, [], $run, $started, $extra ) );
		}
		if ( (string) get_option( 'template' ) === $stylesheet ) {
			throw new JobFailed( 'theme_in_use', 'The theme is the parent of the theme the site uses, so Scanfully did not delete it.', self::partial( $version, $version, [], $run, $started, $extra ) );
		}
		self::connect_filesystem( 'delete' );

		try {
			$result = delete_theme( $stylesheet );
		} catch ( \Throwable $e ) {
			throw new JobFailed( 'fatal', 'A PHP error stopped the delete: ' . $e->getMessage(), self::partial( $version, self::theme_version( $stylesheet ), [], $run, $started, $extra ) );
		}

		return self::deleted( $result, $folder, $version, 'theme', $run, $started, $extra );
	}

	/**
	 * The result of a delete, checked: WordPress reported no error and the
	 * folder is gone.
	 *
	 * @param mixed  $result   What delete_plugins() or delete_theme() answered.
	 * @param string $folder   The software's folder (or a single-file plugin's file).
	 * @param string $previous The version before.
	 * @param string $kind     plugin or theme.
	 * @param JobRun $run      The run.
	 * @param float  $started  When the action started.
	 * @param array  $extra    The slug or stylesheet.
	 *
	 * @return array
	 * @throws JobFailed When the delete did not happen.
	 */
	private static function deleted( $result, string $folder, string $previous, string $kind, JobRun $run, float $started, array $extra ): array {
		clearstatcache( true, $folder );
		$gone = ! file_exists( $folder );
		if ( is_wp_error( $result ) ) {
			$failure = Failure::of( $result );
			throw new JobFailed( $failure['class'], sprintf( 'WordPress did not delete the %s: %s', $kind, $failure['message'] ), self::partial( $previous, $gone ? '' : $previous, [], $run, $started, $extra ) );
		}
		if ( ! $gone ) {
			throw new JobFailed( 'filesystem', sprintf( 'WordPress did not delete the %s: its files are still there.', $kind ), self::partial( $previous, $previous, [], $run, $started, $extra ) );
		}

		return self::partial( $previous, '', [ sprintf( 'WordPress deleted the %s.', $kind ) ], $run, $started, $extra );
	}
}
