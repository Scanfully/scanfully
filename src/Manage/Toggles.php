<?php
/**
 * The Manage toggles class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

use Scanfully\Main;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A JobFailed message is never printed: it is posted to the API as JSON, cleaned by Failure::clean(), and HTML escaping would garble it.

/**
 * The three actions of Scanfully Manage that change what runs without
 * writing files: switching a plugin on, switching it off, and switching the
 * site to another theme, each through WordPress's own functions
 * (activate_plugin(), deactivate_plugins(), switch_theme()), so the
 * plugins' own activation and deactivation code runs as it does in
 * WordPress's admin.
 *
 * A plugin that fails while WordPress switches it on stays switched off,
 * and the job fails with class fatal. The Scanfully plugin itself is never
 * switched off by a job. A plugin or theme that is in the state asked for
 * already is not an error: the result says nothing changed.
 */
final class Toggles extends Action {

	/**
	 * The codes of activate_plugin()'s errors that say the plugin cannot run
	 * on this site: its WordPress or PHP requirements, or plugins it needs.
	 */
	private const REQUIREMENT_CODES = [
		'plugin_wp_php_incompatible',
		'plugin_wp_incompatible',
		'plugin_php_incompatible',
		'plugin_missing_dependencies',
	];

	/**
	 * The codes of activate_plugin()'s errors that say the plugin file is
	 * not a plugin WordPress can load.
	 */
	private const INVALID_CODES = [ 'plugin_invalid', 'no_plugin_header' ];

	/**
	 * Switch a plugin on, by its slug.
	 *
	 * @param array    $params The job's parameters: slug.
	 * @param callable $beat   Sends a heartbeat when one is due.
	 * @param JobRun   $run    The run.
	 *
	 * @return array The result, with active.
	 * @throws JobFailed When the plugin is not on afterwards.
	 */
	public static function activate_plugin( array $params, callable $beat, JobRun $run ): array {
		unset( $beat );
		$started = microtime( true );
		$slug    = self::param( $params, 'slug', self::SLUG, true );
		self::load();

		$file    = self::installed_plugin( $slug );
		$version = self::plugin_version( $file );
		$extra   = [ 'slug' => $slug ];
		if ( self::active( $file ) ) {
			return self::partial( $version, $version, [ 'The plugin is switched on already: nothing to do.' ], $run, $started, $extra + [ 'active' => true ] );
		}

		// activate_plugin() loads the plugin's file inside an output
		// buffer: an error thrown there leaves the buffer open.
		$level = ob_get_level();
		try {
			$result = activate_plugin( $file, '', false, false );
		} catch ( \Throwable $e ) {
			self::close_buffers( $level );
			$off = self::keep_off( $file );
			throw new JobFailed(
				'fatal',
				'The plugin stopped with a PHP error while WordPress switched it on: ' . $e->getMessage() . ' ' . $off,
				self::partial( $version, $version, [], $run, $started, $extra + [ 'active' => self::active( $file ) ] )
			);
		}
		self::close_buffers( $level );

		if ( is_wp_error( $result ) ) {
			$code    = (string) $result->get_error_code();
			$failure = Failure::of( $result );
			if ( in_array( $code, self::REQUIREMENT_CODES, true ) ) {
				$class = 'not_supported';
			} elseif ( 'plugin_not_found' === $code ) {
				$class = 'not_available';
			} elseif ( in_array( $code, self::INVALID_CODES, true ) ) {
				$class = 'package';
			} else {
				// WordPress's sandbox caught the plugin failing on activation
				// (unexpected output, an error it reported): it stays off.
				$class               = 'fatal';
				$failure['message'] .= ' ' . self::keep_off( $file );
			}
			throw new JobFailed(
				$class,
				'WordPress did not switch the plugin on: ' . $failure['message'],
				self::partial( $version, $version, [], $run, $started, $extra + [ 'active' => self::active( $file ) ] )
			);
		}
		if ( ! self::active( $file ) ) {
			throw new JobFailed(
				'other',
				'WordPress reported the plugin switched on, but it is not active: another plugin may have switched it off again.',
				self::partial( $version, $version, [], $run, $started, $extra + [ 'active' => false ] )
			);
		}

		return self::partial( $version, $version, [ 'WordPress switched the plugin on.' ], $run, $started, $extra + [ 'active' => true ] );
	}

	/**
	 * Switch a plugin off, by its slug. Never the Scanfully plugin.
	 *
	 * @param array    $params The job's parameters: slug.
	 * @param callable $beat   Sends a heartbeat when one is due.
	 * @param JobRun   $run    The run.
	 *
	 * @return array The result, with active.
	 * @throws JobFailed When the plugin is still on afterwards.
	 */
	public static function deactivate_plugin( array $params, callable $beat, JobRun $run ): array {
		unset( $beat );
		$started = microtime( true );
		$slug    = self::param( $params, 'slug', self::SLUG, true );
		self::load();

		$file = self::installed_plugin( $slug );
		if ( Main::is_own_plugin( $file ) ) {
			throw new JobFailed( 'not_supported', 'Scanfully never switches its own plugin off through a job: that would end Scanfully Manage on the site. Switch it off in WordPress.' );
		}
		$version = self::plugin_version( $file );
		$extra   = [ 'slug' => $slug ];
		if ( ! self::active( $file ) ) {
			return self::partial( $version, $version, [ 'The plugin is switched off already: nothing to do.' ], $run, $started, $extra + [ 'active' => false ] );
		}

		try {
			deactivate_plugins( $file, false, false );
		} catch ( \Throwable $e ) {
			$active = self::active( $file );
			throw new JobFailed(
				'fatal',
				'The plugin stopped with a PHP error while WordPress switched it off: ' . $e->getMessage() . ( $active ? ' It is still switched on.' : ' It is switched off.' ),
				self::partial( $version, $version, [], $run, $started, $extra + [ 'active' => $active ] )
			);
		}
		if ( self::active( $file ) ) {
			throw new JobFailed(
				'other',
				'WordPress did not switch the plugin off: it is still active.',
				self::partial( $version, $version, [], $run, $started, $extra + [ 'active' => true ] )
			);
		}

		return self::partial( $version, $version, [ 'WordPress switched the plugin off.' ], $run, $started, $extra + [ 'active' => false ] );
	}

	/**
	 * Switch the site to another installed theme, by its stylesheet. The
	 * versions in the result are the new theme's.
	 *
	 * @param array    $params The job's parameters: stylesheet.
	 * @param callable $beat   Sends a heartbeat when one is due.
	 * @param JobRun   $run    The run.
	 *
	 * @return array The result, with stylesheet and previous_theme.
	 * @throws JobFailed When the site does not use the theme afterwards.
	 */
	public static function switch_theme( array $params, callable $beat, JobRun $run ): array {
		unset( $beat );
		$started    = microtime( true );
		$stylesheet = self::param( $params, 'stylesheet', self::SLUG, true );
		self::load();

		$previous = (string) get_option( 'stylesheet' );
		$version  = self::theme_version( $stylesheet );
		$extra    = [
			'stylesheet'     => $stylesheet,
			'previous_theme' => $previous,
		];
		$theme    = wp_get_theme( $stylesheet );
		if ( '' === $version || ! $theme->exists() ) {
			throw new JobFailed( 'not_available', sprintf( 'The theme "%s" is not installed on the site.', $stylesheet ), self::partial( '', '', [], $run, $started, $extra ) );
		}
		if ( $previous === $stylesheet ) {
			return self::partial( $version, $version, [ 'The site uses the theme already: nothing to do.' ], $run, $started, $extra );
		}
		$errors = $theme->errors();
		if ( is_wp_error( $errors ) ) {
			throw new JobFailed(
				'theme_no_parent' === $errors->get_error_code() ? 'not_available' : 'package',
				'WordPress cannot use the theme: ' . Failure::of( $errors )['message'],
				self::partial( $version, $version, [], $run, $started, $extra )
			);
		}
		if ( function_exists( 'validate_theme_requirements' ) ) {
			$requirements = validate_theme_requirements( $stylesheet );
			if ( is_wp_error( $requirements ) ) {
				throw new JobFailed( 'not_supported', 'The theme cannot run on this site: ' . Failure::of( $requirements )['message'], self::partial( $version, $version, [], $run, $started, $extra ) );
			}
		}

		try {
			switch_theme( $stylesheet );
		} catch ( \Throwable $e ) {
			throw new JobFailed(
				'fatal',
				'A PHP error stopped the theme switch: ' . $e->getMessage(),
				self::partial( $version, $version, [], $run, $started, $extra )
			);
		}
		if ( (string) get_option( 'stylesheet' ) !== $stylesheet ) {
			throw new JobFailed(
				'other',
				'WordPress reported the theme switched, but the site does not use it: another plugin may have switched it back.',
				self::partial( $version, $version, [], $run, $started, $extra )
			);
		}

		return self::partial( $version, $version, [ sprintf( 'WordPress switched the site from the theme "%s" to "%s".', $previous, $stylesheet ) ], $run, $started, $extra );
	}

	/**
	 * Whether a plugin is switched on now: read again after every change.
	 *
	 * @phpstan-impure
	 *
	 * @param string $file The plugin file.
	 *
	 * @return bool
	 */
	private static function active( string $file ): bool {
		return is_plugin_active( $file );
	}

	/**
	 * The file of an installed plugin by its slug.
	 *
	 * @param string $slug The slug.
	 *
	 * @return string
	 * @throws JobFailed When the plugin is not installed.
	 */
	private static function installed_plugin( string $slug ): string {
		$file = self::plugin_file( $slug );
		if ( null === $file ) {
			throw new JobFailed( 'not_available', sprintf( 'The plugin "%s" is not installed on the site.', $slug ) );
		}

		return $file;
	}

	/**
	 * Close the output buffers activate_plugin() left open, and drop what
	 * the plugin printed: nothing of it is sent.
	 *
	 * @param int $level The buffer level before.
	 *
	 * @return void
	 */
	private static function close_buffers( int $level ): void {
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
	}

	/**
	 * Leave a plugin that failed on activation switched off: WordPress
	 * keeps one that printed output switched on. Returns what the message
	 * says about it.
	 *
	 * @param string $file The plugin file.
	 *
	 * @return string
	 */
	private static function keep_off( string $file ): string {
		if ( ! self::active( $file ) ) {
			return 'The plugin stays switched off.';
		}
		try {
			deactivate_plugins( $file, true, false );
		} catch ( \Throwable $e ) {
			unset( $e ); // Read back below.
		}

		return self::active( $file )
			? 'Scanfully could not switch it off again: it is still active.'
			: 'Scanfully switched it off again.';
	}
}
