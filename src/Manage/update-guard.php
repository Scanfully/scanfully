<?php
/**
 * Plugin Name: Scanfully update guard
 * Description: Puts the Scanfully plugin back when its update through Scanfully stopped halfway. Scanfully adds this file while it updates itself and removes it afterwards; a copy left behind removes itself once Scanfully runs again.
 * Author: Scanfully
 * Author URI: https://scanfully.com/
 * License: GPL v3
 *
 * A copy of src/Manage/update-guard.php from the Scanfully plugin, placed
 * in the must-use plugins folder by Scanfully\Manage\UpdateGuard. It holds
 * no data: what it guards is the network option scanfully_update_guard. It
 * declares nothing and stays quiet until the update is due to have ended;
 * after that, on each page load until Scanfully runs again:
 *
 * 1. WordPress's backup of the version before the update is still in
 *    wp-content/upgrade-temp-backup: the update never finished, so the
 *    backup goes back in place of whatever the update left.
 * 2. The plugin's files are there but it is switched off: it is switched on
 *    again, before plugins load, so it runs on this very page load.
 * 3. That page load stops with a PHP error in the plugin's own files: the
 *    plugin is switched off again and the guard stops trying.
 * 4. Nothing to run (no files, or switched off after an error): a notice in
 *    WP admin says so.
 *
 * Once Scanfully ran a page load to the end, the guard leaves a note of what
 * it did for the job's report and removes itself, as it does a week after
 * the update when Scanfully never came back.
 *
 * @package Scanfully
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Runs before plugins load, without WordPress's filesystem: plain PHP moves the folders whole.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- A failed move is checked by its result and never shown on the page.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

try {
	( static function (): void {
		$option = 'scanfully_update_guard';
		$self   = __FILE__;
		$state  = get_site_option( $option );
		if ( ! is_array( $state ) || ! isset( $state['basename'], $state['due'], $state['job'] ) ) {
			// The update ended and Scanfully forgot it: a copy left behind.
			wp_delete_file( $self );
			return;
		}
		$now = time();
		if ( $now < (int) $state['due'] ) {
			// The update may still be running.
			return;
		}

		$done     = static function () use ( $option, $self ): void {
			delete_site_option( $option );
			wp_delete_file( $self );
		};
		$basename = (string) $state['basename'];
		$slug     = dirname( $basename );
		if ( $now > (int) $state['due'] + WEEK_IN_SECONDS || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $slug ) || false !== strpos( $slug, '..' ) ) {
			$done();
			return;
		}

		$content = rtrim( (string) WP_CONTENT_DIR, '/\\' );
		$folder  = rtrim( (string) WP_PLUGIN_DIR, '/\\' ) . '/' . $slug;
		$main    = rtrim( (string) WP_PLUGIN_DIR, '/\\' ) . '/' . $basename;
		$backup  = $content . '/upgrade-temp-backup/plugins/' . $slug;
		$network = ! empty( $state['network'] ) && is_multisite();
		$changed = false;

		$version = static function ( string $file ): string {
			clearstatcache( true, $file );
			return is_file( $file ) ? (string) ( get_file_data( $file, [ 'Version' => 'Version' ] )['Version'] ?? '' ) : '';
		};
		$remove  = static function ( string $path ) use ( &$remove ): void {
			if ( is_link( $path ) || is_file( $path ) ) {
				@unlink( $path );
				return;
			}
			if ( ! is_dir( $path ) ) {
				return;
			}
			foreach ( (array) scandir( $path ) as $name ) {
				if ( '.' !== $name && '..' !== $name ) {
					$remove( $path . '/' . $name );
				}
			}
			@rmdir( $path );
		};
		$fresh   = static function ( string $dir ): void {
			if ( ! function_exists( 'opcache_invalidate' ) || ! is_dir( $dir ) ) {
				return;
			}
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				if ( $file instanceof SplFileInfo && 'php' === $file->getExtension() ) {
					@opcache_invalidate( $file->getPathname(), true );
				}
			}
		};
		$is_on   = static function () use ( $network, $basename ): bool {
			return $network
				? array_key_exists( $basename, (array) get_site_option( 'active_sitewide_plugins', [] ) )
				: in_array( $basename, (array) get_option( 'active_plugins', [] ), true );
		};
		$switch  = static function ( bool $on ) use ( $network, $basename ): void {
			if ( $network ) {
				$list = (array) get_site_option( 'active_sitewide_plugins', [] );
				if ( $on ) {
					$list[ $basename ] = time();
				} else {
					unset( $list[ $basename ] );
				}
				update_site_option( 'active_sitewide_plugins', $list );
				return;
			}
			$list = array_values( array_diff( (array) get_option( 'active_plugins', [] ), [ $basename ] ) );
			if ( $on ) {
				$list[] = $basename;
				sort( $list );
			}
			update_option( 'active_plugins', $list );
		};

		// 1. The update never finished: put WordPress's backup of the
		// version before it back. Only that version's backup: an older one
		// left by another update is not this update's.
		$previous = (string) ( $state['previous'] ?? '' );
		if ( empty( $state['gave_up'] ) && ! is_link( $folder ) && '' !== $previous && $version( $backup . '/' . basename( $basename ) ) === $previous ) {
			$aside = '';
			if ( file_exists( $folder ) ) {
				$aside = $content . '/upgrade/' . $slug . '-unfinished-' . $now;
				wp_mkdir_p( dirname( $aside ) );
				if ( ! @rename( $folder, $aside ) ) {
					$aside = null;
				}
			}
			if ( null !== $aside && @rename( $backup, $folder ) ) {
				$state['restored'] = $previous;
				if ( '' !== $aside ) {
					$remove( $aside );
				}
				$fresh( $folder );
			} elseif ( null !== $aside && '' !== $aside ) {
				@rename( $aside, $folder );
			}
			$changed = true;
		}

		// 2. The files are there but the plugin is off: switch it on, for
		// this page load already.
		if ( empty( $state['gave_up'] ) && is_file( $main ) && ! $is_on() ) {
			$switch( true );
			$state['reactivated'] = $now;
			$changed              = true;
		}

		$missing = ! is_file( $main );
		if ( $missing !== ! empty( $state['missing'] ) ) {
			$state['missing'] = $missing;
			$changed          = true;
		}
		if ( $changed ) {
			update_site_option( $option, $state );
		}

		// 3. This page load stops with a PHP error in the plugin's own
		// files: switch it off again, for good. WordPress's fatal error
		// handler ends the request before later shutdown functions run, so
		// its message filter comes first; the shutdown function covers a
		// site where the handler is off.
		$handled  = false;
		$on_fatal = static function ( $error ) use ( &$handled, &$state, $option, $folder, $switch, $is_on ): void {
			if ( $handled || ! is_array( $error ) || ! isset( $error['type'], $error['file'] ) ) {
				return;
			}
			if ( 0 === ( (int) $error['type'] & ( E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR ) ) ) {
				return;
			}
			$handled = true;
			if ( 0 !== strpos( wp_normalize_path( (string) $error['file'] ), wp_normalize_path( $folder ) . '/' ) ) {
				return;
			}
			if ( $is_on() ) {
				$switch( false );
			}
			$state['gave_up'] = substr( (string) ( $error['message'] ?? 'a PHP error' ), 0, 300 );
			update_site_option( $option, $state );
		};
		add_filter(
			'wp_php_error_message',
			static function ( $message, $error = null ) use ( $on_fatal ) {
				$on_fatal( $error );
				return $message;
			},
			1,
			2
		);
		register_shutdown_function(
			static function () use ( $on_fatal ): void {
				$on_fatal( error_get_last() );
			}
		);

		// Scanfully ran this page load to the end: leave a note of what the
		// guard did for the job's report, and go.
		add_action(
			'wp_loaded',
			static function () use ( &$state, $done, $version, $main ): void {
				if ( ! defined( 'SCANFULLY_VERSION' ) ) {
					return;
				}
				$now_running = $version( $main );
				$text        = '';
				if ( ! empty( $state['restored'] ) ) {
					$text = sprintf( 'Scanfully\'s update guard put version %s back on the site', (string) $state['restored'] );
					$text .= ! empty( $state['reactivated'] ) ? ' and switched the plugin on again.' : '.';
				} elseif ( ! empty( $state['reactivated'] ) ) {
					$text = sprintf( 'Scanfully\'s update guard switched the plugin on again, at version %s.', $now_running );
				}
				if ( '' !== $text ) {
					update_site_option(
						'scanfully_update_guard_note',
						[
							'job'  => (string) $state['job'],
							'at'   => time(),
							'text' => $text,
						]
					);
				}
				$done();
			},
			PHP_INT_MAX
		);

		// 4. Nothing runs: say so in WP admin.
		if ( ! empty( $state['gave_up'] ) || $missing ) {
			$notice = static function () use ( &$state, $missing ): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				$text = ! empty( $state['gave_up'] )
					? sprintf( 'The Scanfully plugin stopped with a PHP error after its update through Scanfully, so it was switched off again: %s. Update or reinstall Scanfully under Plugins and activate it; its settings on the site are kept.', (string) $state['gave_up'] )
					: ( $missing ? 'The Scanfully plugin\'s update through Scanfully did not finish, and the plugin\'s files are missing. Install Scanfully again under Plugins and activate it; its settings on the site are kept.' : '' );
				if ( '' !== $text ) {
					echo '<div class="notice notice-error"><p>' . esc_html( $text ) . '</p></div>';
				}
			};
			add_action( 'admin_notices', $notice );
			add_action( 'network_admin_notices', $notice );
		}
	} )();
} catch ( \Throwable $scanfully_update_guard_error ) {
	// Never break the site over the guard.
	unset( $scanfully_update_guard_error );
}
