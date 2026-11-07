<?php
/**
 * The capabilities class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Health;

/**
 * Class Capabilities
 *
 * What this site can do for Scanfully, sent with the site data sync (so on
 * connect and every sync after): the plugin version, the job actions the
 * plugin runs, whether WordPress may change files and update itself, how
 * WordPress would write files, whether the site is a network, whether Action
 * Scheduler runs and whether the must-use plugins folder is writable.
 * Scanfully reads it before it queues a job for the site.
 *
 * Every check is cheap and asks for no credentials. A check that fails is
 * sent as null, which Scanfully reads as unknown, so the sync itself never
 * fails on it.
 *
 * @package Scanfully\Health
 */
final class Capabilities {

	/**
	 * The job actions this plugin version runs. Empty until the command
	 * channel adds its first action; the key is sent already.
	 *
	 * @var string[]
	 */
	private const ACTIONS = [];

	/**
	 * The capabilities object of the site data.
	 *
	 * @return array{plugin_version: string, actions: string[], file_mods_allowed: bool|null, auto_updater_enabled: bool|null, fs_method: string|null, multisite: bool|null, action_scheduler: bool|null, mu_plugin_writable: bool|null}
	 */
	public static function get(): array {
		return [
			'plugin_version' => defined( 'SCANFULLY_VERSION' ) ? (string) SCANFULLY_VERSION : '',
			'actions' => self::ACTIONS,
			'file_mods_allowed' => self::check( [ self::class, 'file_mods_allowed' ] ),
			'auto_updater_enabled' => self::check( [ self::class, 'auto_updater_enabled' ] ),
			'fs_method' => self::check( [ self::class, 'fs_method' ] ),
			'multisite' => self::check( 'is_multisite' ),
			'action_scheduler' => self::check( [ self::class, 'action_scheduler' ] ),
			'mu_plugin_writable' => self::check(
				static function (): bool {
					return self::can_write_folder( defined( 'WPMU_PLUGIN_DIR' ) ? (string) WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' );
				}
			),
		];
	}

	/**
	 * Run one check; null when it throws.
	 *
	 * @param callable $check The check.
	 *
	 * @return mixed
	 */
	private static function check( callable $check ) {
		try {
			return $check();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Whether WordPress may install, update and delete plugins, themes and
	 * core: false when DISALLOW_FILE_MODS is set or the file_mod_allowed
	 * filter says no.
	 *
	 * @return bool
	 */
	private static function file_mods_allowed(): bool {
		return (bool) wp_is_file_mod_allowed( 'scanfully_capabilities' );
	}

	/**
	 * Whether WordPress updates itself in the background, as
	 * WP_Automatic_Updater::is_disabled() decides it: not when file changes
	 * are not allowed, AUTOMATIC_UPDATER_DISABLED is set or the
	 * automatic_updater_disabled filter says so.
	 *
	 * @return bool
	 */
	private static function auto_updater_enabled(): bool {
		if ( ! wp_is_file_mod_allowed( 'automatic_updater' ) ) {
			return false;
		}

		$disabled = defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter.
		return ! apply_filters( 'automatic_updater_disabled', $disabled );
	}

	/**
	 * How WordPress would write files: direct, or a method that needs FTP or
	 * SSH credentials (ftpext, ftpsockets, ssh2). This is WordPress's own
	 * get_filesystem_method(), which honours FS_METHOD and the
	 * filesystem_method filter; it never asks for credentials. Without
	 * FS_METHOD it opens and removes an empty test file in wp-content, as
	 * WordPress does on its own update screens.
	 *
	 * @return string|null Null when WordPress names no method.
	 */
	private static function fs_method(): ?string {
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$method = get_filesystem_method();

		return is_string( $method ) && '' !== $method ? $method : null;
	}

	/**
	 * Whether Action Scheduler is loaded and its data store is ready, so the
	 * plugin can run a job in the background.
	 *
	 * @return bool
	 */
	private static function action_scheduler(): bool {
		return function_exists( 'as_schedule_single_action' )
			&& class_exists( 'ActionScheduler', false )
			&& \ActionScheduler::is_initialized();
	}

	/**
	 * Whether a file can be written to a folder (the mu-plugins folder): it
	 * is writable, or when it does not exist, the folder it would be made in
	 * is. Reads the permissions (WordPress tests with a file only on Windows).
	 *
	 * @param string $dir The folder.
	 *
	 * @return bool
	 */
	private static function can_write_folder( string $dir ): bool {
		return is_dir( $dir ) ? wp_is_writable( $dir ) : wp_is_writable( dirname( $dir ) );
	}
}
