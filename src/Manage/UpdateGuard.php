<?php
/**
 * The Manage update guard class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The guard file is written before WordPress's filesystem is connected for the update, and written whole with a rename.

/**
 * The guard of an update of the Scanfully plugin itself: a small must-use
 * plugin, a copy of update-guard.php next to this file, that is in the
 * must-use plugins folder only while the update runs.
 *
 * The job removes it when the update ended with the plugin in a known
 * state. When the job never got that far (PHP stopped halfway, so nothing
 * after that point ran), the guard is still there once the update is due to
 * have ended, and on the next page load it puts the plugin back: see
 * update-guard.php. It is due a minute after the job's request ended or
 * PHP stopped in it, and at the latest when no job can still be running.
 *
 * What it guards is a network option, so the file holds no data. Once
 * Scanfully runs again the guard leaves a note of what it did, which the
 * job's report carries, and removes itself.
 */
final class UpdateGuard {

	/**
	 * The file in the must-use plugins folder. WordPress loads those in the
	 * order of their names, so this one comes before the others, Scanfully's
	 * own included: it puts the plugin's files back before anything loads
	 * from them.
	 */
	public const FILE = '0-scanfully-update-guard.php';

	/**
	 * Text in the file that marks it as Scanfully's, so only Scanfully's own
	 * file is ever removed.
	 */
	public const MARKER = 'Plugin Name: Scanfully update guard';

	/**
	 * The network option with what the guard guards.
	 */
	public const OPTION = 'scanfully_update_guard';

	/**
	 * The network option with the note of what the guard did, for the job's
	 * report.
	 */
	public const NOTE_OPTION = 'scanfully_update_guard_note';

	/**
	 * Seconds after which an update can no longer be running: a job that
	 * runs longer is stopped.
	 */
	public const DUE_AFTER = Controller::STALE_RUN;

	/**
	 * Seconds after the job's request ended, or PHP stopped in it, that the
	 * guard is due: WordPress's own clean-up at the end of that request goes
	 * first.
	 */
	public const DUE_SOON = MINUTE_IN_SECONDS;

	/**
	 * For tests: the must-use plugins folder. WPMU_PLUGIN_DIR when null.
	 *
	 * @var string|null
	 */
	public static $dir = null;

	/**
	 * For tests: registers a shutdown function. register_shutdown_function
	 * when null.
	 *
	 * @var callable|null
	 */
	public static $on_shutdown = null;

	/**
	 * The must-use plugins folder.
	 *
	 * @return string
	 */
	public static function dir(): string {
		if ( null !== self::$dir ) {
			return self::$dir;
		}
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			return rtrim( (string) WPMU_PLUGIN_DIR, '/\\' );
		}

		return rtrim( defined( 'WP_CONTENT_DIR' ) ? (string) WP_CONTENT_DIR : ABSPATH . 'wp-content', '/\\' ) . '/mu-plugins';
	}

	/**
	 * The guard file.
	 *
	 * @return string
	 */
	public static function path(): string {
		return self::dir() . '/' . self::FILE;
	}

	/**
	 * Put the guard in place for an update of the plugin.
	 *
	 * @param string $job      The job id.
	 * @param string $basename The plugin file, relative to the plugins folder.
	 * @param string $previous The version before the update.
	 * @param string $target   The version the update installs.
	 * @param bool   $network  Whether the plugin is active for the whole network.
	 *
	 * @return string|null Why the update runs without a guard; null when the guard is in place.
	 */
	public static function arm( string $job, string $basename, string $previous, string $target, bool $network ): ?string {
		$template = __DIR__ . '/update-guard.php';
		$code     = is_readable( $template ) ? (string) file_get_contents( $template ) : '';
		if ( false === strpos( $code, self::MARKER ) ) {
			return 'the guard file is missing from the plugin';
		}
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return 'the must-use plugins folder does not exist and could not be made';
		}
		if ( ! wp_is_writable( $dir ) ) {
			return 'the must-use plugins folder is not writable';
		}

		$now = time();
		update_site_option(
			self::OPTION,
			[
				'job'      => $job,
				'basename' => $basename,
				'previous' => $previous,
				'target'   => $target,
				'network'  => $network,
				'armed'    => $now,
				'due'      => $now + self::DUE_AFTER,
			]
		);
		// Written under a name WordPress never loads, then renamed: a guard
		// file is whole or not there, as a broken one would stop every page.
		$temp    = $dir . '/.' . self::FILE . '.tmp';
		$written = file_put_contents( $temp, $code );
		if ( strlen( $code ) !== $written || ! rename( $temp, self::path() ) ) {
			if ( file_exists( $temp ) ) {
				wp_delete_file( $temp );
			}
			delete_site_option( self::OPTION );
			return 'the guard file could not be written';
		}

		// The guard is due soon after this request ends without releasing
		// it. WordPress's fatal error handler ends the request before later
		// shutdown functions run, so its message filter is where a PHP stop
		// is noticed; the shutdown function covers every other end.
		add_filter(
			'wp_php_error_message',
			static function ( $message ) use ( $job ) {
				self::due_soon( $job );
				return $message;
			},
			1
		);
		call_user_func(
			self::$on_shutdown ?? 'register_shutdown_function',
			static function () use ( $job ): void {
				self::due_soon( $job );
			}
		);

		return null;
	}

	/**
	 * Remove the guard of a job: its update ended with the plugin in a known
	 * state.
	 *
	 * @param string $job The job id.
	 *
	 * @return void
	 */
	public static function release( string $job ): void {
		$state = get_site_option( self::OPTION );
		if ( is_array( $state ) && ( $state['job'] ?? '' ) !== $job ) {
			return;
		}
		$path = self::path();
		if ( is_file( $path ) && false !== strpos( (string) file_get_contents( $path ), self::MARKER ) ) {
			wp_delete_file( $path );
		}
		delete_site_option( self::OPTION );
	}

	/**
	 * Make the guard of a job due a minute from now, when it is due later.
	 *
	 * @param string $job The job id.
	 *
	 * @return void
	 */
	public static function due_soon( string $job ): void {
		$state = get_site_option( self::OPTION );
		if ( ! is_array( $state ) || ( $state['job'] ?? '' ) !== $job ) {
			return;
		}
		$soon = time() + self::DUE_SOON;
		if ( (int) ( $state['due'] ?? 0 ) <= $soon ) {
			return;
		}
		$state['due'] = $soon;
		update_site_option( self::OPTION, $state );
	}

	/**
	 * What the guard did after the job, for the job's report; null when it
	 * did nothing.
	 *
	 * @param string $job The job id.
	 *
	 * @return string|null
	 */
	public static function note( string $job ): ?string {
		$note = get_site_option( self::NOTE_OPTION );
		if ( ! is_array( $note ) || ( $note['job'] ?? '' ) !== $job || ! is_string( $note['text'] ?? null ) || '' === $note['text'] ) {
			return null;
		}

		return $note['text'];
	}

	/**
	 * Forget the note of a job, once the API took the report.
	 *
	 * @param string $job The job id.
	 *
	 * @return void
	 */
	public static function forget_note( string $job ): void {
		if ( null !== self::note( $job ) ) {
			delete_site_option( self::NOTE_OPTION );
		}
	}
}
