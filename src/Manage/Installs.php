<?php
/**
 * The Manage installs class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A JobFailed message is never printed: it is posted to the API as JSON, cleaned by Failure::clean(), and HTML escaping would garble it.

/**
 * The four install actions of Scanfully Manage: a plugin or a theme from
 * wordpress.org, by its slug and a version, and a plugin or a theme from a
 * ZIP file uploaded to Scanfully, each through WordPress's own upgrader with
 * the silent skin. Nothing is switched on: activation is a job of its own.
 *
 * An install from wordpress.org asks WordPress's plugins_api() or
 * themes_api() for the package of the slug and the version, never a URL
 * from the job. An upload is downloaded from the single-use link the pull
 * gave, and installed only when its SHA-256 is the one Scanfully checked
 * (Package). The result names the host the package came from (source),
 * never a URL.
 *
 * Software already installed at the version asked for is not an error: the
 * job's goal holds, and the result says nothing changed.
 */
final class Installs extends Action {

	/**
	 * What an upload id looks like.
	 */
	private const UPLOAD_ID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

	/**
	 * Install a plugin from wordpress.org: slug, and version (the newest
	 * when none is named).
	 *
	 * @param array    $params The job's parameters: slug, version.
	 * @param callable $beat   Sends a heartbeat when one is due.
	 * @param JobRun   $run    The run.
	 *
	 * @return array The result.
	 * @throws JobFailed When the install did not happen.
	 */
	public static function plugin( array $params, callable $beat, JobRun $run ): array {
		unset( $beat );
		$started = microtime( true );
		$slug    = self::param( $params, 'slug', self::SLUG, true );
		$version = self::param( $params, 'version', self::VERSION, false );
		self::prepare();

		$file = self::plugin_file( $slug );
		if ( null !== $file ) {
			return self::present( 'plugin', self::plugin_version( $file ), $version, $run, $started, [ 'slug' => $slug ] );
		}
		$offer = self::wporg( 'plugin', $slug, $version );

		return self::install_plugin( $offer['package'], self::host( $offer['package'] ), $slug, $offer['version'], $run, $started );
	}

	/**
	 * Install a theme from wordpress.org: slug, and version (the newest
	 * when none is named).
	 *
	 * @param array    $params The job's parameters: slug, version.
	 * @param callable $beat   Sends a heartbeat when one is due.
	 * @param JobRun   $run    The run.
	 *
	 * @return array The result.
	 * @throws JobFailed When the install did not happen.
	 */
	public static function theme( array $params, callable $beat, JobRun $run ): array {
		unset( $beat );
		$started = microtime( true );
		$slug    = self::param( $params, 'slug', self::SLUG, true );
		$version = self::param( $params, 'version', self::VERSION, false );
		self::prepare();

		$installed = self::theme_version( $slug );
		if ( '' !== $installed ) {
			return self::present( 'theme', $installed, $version, $run, $started, [ 'stylesheet' => $slug ] );
		}
		$offer = self::wporg( 'theme', $slug, $version );

		return self::install_theme( $offer['package'], self::host( $offer['package'] ), $slug, $offer['version'], $run, $started );
	}

	/**
	 * Install a plugin from a ZIP file uploaded to Scanfully.
	 *
	 * @param array    $params The job's parameters: upload_id.
	 * @param callable $beat   Sends a heartbeat when one is due.
	 * @param JobRun   $run    The run, with the download the pull gave.
	 *
	 * @return array The result.
	 * @throws JobFailed When the install did not happen.
	 */
	public static function plugin_upload( array $params, callable $beat, JobRun $run ): array {
		unset( $beat );
		$started = microtime( true );
		self::param( $params, 'upload_id', self::UPLOAD_ID, true );
		self::prepare();

		$download = Package::fetch( $run );
		try {
			return self::install_plugin( $download['file'], $download['source'], '', '', $run, $started );
		} finally {
			Package::remove( $download['file'] );
		}
	}

	/**
	 * Install a theme from a ZIP file uploaded to Scanfully.
	 *
	 * @param array    $params The job's parameters: upload_id.
	 * @param callable $beat   Sends a heartbeat when one is due.
	 * @param JobRun   $run    The run, with the download the pull gave.
	 *
	 * @return array The result.
	 * @throws JobFailed When the install did not happen.
	 */
	public static function theme_upload( array $params, callable $beat, JobRun $run ): array {
		unset( $beat );
		$started = microtime( true );
		self::param( $params, 'upload_id', self::UPLOAD_ID, true );
		self::prepare();

		$download = Package::fetch( $run );
		try {
			return self::install_theme( $download['file'], $download['source'], '', '', $run, $started );
		} finally {
			Package::remove( $download['file'] );
		}
	}

	/**
	 * The result of an install of software that is on the site already:
	 * nothing to do at the version asked for (or when none was), else a
	 * failure, as Scanfully installs only what is not there.
	 *
	 * @param string $kind      plugin or theme.
	 * @param string $installed The installed version.
	 * @param string $version   The version asked for, or ''.
	 * @param JobRun $run       The run.
	 * @param float  $started   When the action started.
	 * @param array  $extra     The slug or stylesheet.
	 *
	 * @return array
	 * @throws JobFailed When another version is installed.
	 */
	private static function present( string $kind, string $installed, string $version, JobRun $run, float $started, array $extra ): array {
		if ( '' === $version || $version === $installed ) {
			return self::partial( $installed, $installed, [ sprintf( 'The %s is installed already, at version %s: nothing to do.', $kind, $installed ) ], $run, $started, $extra );
		}
		throw new JobFailed(
			'other',
			sprintf( 'The %1$s is installed already, at version %2$s, not %3$s: Scanfully installs only what is not on the site. Update the %1$s instead.', $kind, $installed, $version ),
			self::partial( $installed, $installed, [], $run, $started, $extra )
		);
	}

	/**
	 * The package of a plugin or theme on wordpress.org, as WordPress's own
	 * plugins_api() or themes_api() gives it, for the version asked for or
	 * the newest.
	 *
	 * @param string $kind    plugin or theme.
	 * @param string $slug    The slug.
	 * @param string $version The version asked for, or ''.
	 *
	 * @return array{package: string, version: string}
	 * @throws JobFailed When wordpress.org has no such software or version, or cannot be reached.
	 */
	private static function wporg( string $kind, string $slug, string $version ): array {
		$args = [
			'slug'   => $slug,
			'fields' => [
				'versions'          => true,
				'sections'          => false,
				'description'       => false,
				'short_description' => false,
				'reviews'           => false,
				'banners'           => false,
				'icons'             => false,
				'screenshots'       => false,
				'tags'              => false,
				'contributors'      => false,
				'ratings'           => false,
				'downloaded'        => false,
				'active_installs'   => false,
			],
		];
		if ( 'plugin' === $kind ) {
			if ( ! function_exists( 'plugins_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			}
			$info = plugins_api( 'plugin_information', $args );
		} else {
			if ( ! function_exists( 'themes_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/theme.php';
			}
			$info = themes_api( 'theme_information', $args );
		}
		if ( is_wp_error( $info ) ) {
			$message = $info->get_error_message();
			$class   = Failure::class_of( (string) $info->get_error_code(), $message );
			if ( 'timeout' !== $class ) {
				$class = false !== stripos( $message, 'not found' ) ? 'not_available' : 'package';
			}
			throw new JobFailed( $class, sprintf( 'wordpress.org gave no package for the %s "%s": %s', $kind, $slug, Failure::clean( $message ) ) );
		}
		$info     = is_object( $info ) ? get_object_vars( $info ) : ( is_array( $info ) ? $info : [] );
		$latest   = (string) ( $info['version'] ?? '' );
		$versions = isset( $info['versions'] ) && ( is_array( $info['versions'] ) || is_object( $info['versions'] ) ) ? (array) $info['versions'] : [];

		$wanted  = '' === $version ? $latest : $version;
		$package = '';
		if ( 'trunk' !== $wanted && '' !== $wanted ) {
			$package = $wanted === $latest ? (string) ( $info['download_link'] ?? '' ) : (string) ( $versions[ $wanted ] ?? '' );
		}
		if ( '' === $package || 1 !== preg_match( '#^https?://#i', $package ) ) {
			throw new JobFailed(
				'not_available',
				'' === $version
					? sprintf( 'wordpress.org offers no download of the %s "%s".', $kind, $slug )
					: sprintf( 'wordpress.org offers no version %s of the %s "%s".', $version, $kind, $slug )
			);
		}

		return [
			'package' => $package,
			'version' => $wanted,
		];
	}

	/**
	 * The host of a URL, for the result's source.
	 *
	 * @param string $url The URL.
	 *
	 * @return string
	 */
	private static function host( string $url ): string {
		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/**
	 * Install a plugin package through WordPress's upgrader and check what
	 * it put in place.
	 *
	 * @param string $package The package: a URL, or a local file.
	 * @param string $source  The host it came from.
	 * @param string $slug    The slug it must have, or '' for an upload.
	 * @param string $version The version it must have, or '' for an upload.
	 * @param JobRun $run     The run.
	 * @param float  $started When the action started.
	 *
	 * @return array The result.
	 * @throws JobFailed When the install did not happen.
	 */
	private static function install_plugin( string $package, string $source, string $slug, string $version, JobRun $run, float $started ): array {
		$before   = self::plugin_files();
		$skin     = new UpgraderSkin( $run );
		$upgrader = self::upgrader( 'plugin', $skin );
		$result   = $upgrader->install( $package );

		$info  = method_exists( $upgrader, 'plugin_info' ) ? $upgrader->plugin_info() : false;
		$file  = self::installed( is_string( $info ) ? $info : '', $before, self::plugin_files() );
		$after = null === $file ? '' : self::plugin_version( $file );
		$extra = [
			'slug'   => null === $file ? $slug : self::plugin_slug( $file ),
			'source' => $source,
		];

		return self::checked( 'plugin', $result, $skin, null !== $file, $slug, $version, $after, $run, $started, $extra );
	}

	/**
	 * Install a theme package through WordPress's upgrader and check what
	 * it put in place.
	 *
	 * @param string $package The package: a URL, or a local file.
	 * @param string $source  The host it came from.
	 * @param string $slug    The stylesheet it must have, or '' for an upload.
	 * @param string $version The version it must have, or '' for an upload.
	 * @param JobRun $run     The run.
	 * @param float  $started When the action started.
	 *
	 * @return array The result.
	 * @throws JobFailed When the install did not happen.
	 */
	private static function install_theme( string $package, string $source, string $slug, string $version, JobRun $run, float $started ): array {
		$before   = self::theme_folders();
		$skin     = new UpgraderSkin( $run );
		$upgrader = self::upgrader( 'theme', $skin );
		$result   = $upgrader->install( $package );

		$info       = method_exists( $upgrader, 'theme_info' ) ? $upgrader->theme_info() : false;
		$stylesheet = self::installed( is_object( $info ) && method_exists( $info, 'get_stylesheet' ) ? (string) $info->get_stylesheet() : '', $before, self::theme_folders() );
		$after      = null === $stylesheet ? '' : self::theme_version( $stylesheet );
		$extra      = [
			'stylesheet' => $stylesheet ?? $slug,
			'source'     => $source,
		];

		return self::checked( 'theme', $result, $skin, null !== $stylesheet, $slug, $version, $after, $run, $started, $extra );
	}

	/**
	 * The result of an install, checked: the upgrader answered success, the
	 * software is there, under the slug and at the version asked for.
	 *
	 * @param string       $kind    plugin or theme.
	 * @param mixed        $result  The upgrader's answer.
	 * @param UpgraderSkin $skin    The skin.
	 * @param bool         $found   Whether the installed software was found.
	 * @param string       $slug    The slug asked for, or ''.
	 * @param string       $version The version asked for, or ''.
	 * @param string       $after   The installed version.
	 * @param JobRun       $run     The run.
	 * @param float        $started When the action started.
	 * @param array        $extra   The slug or stylesheet, and the source.
	 *
	 * @return array
	 * @throws JobFailed When the install did not happen as asked.
	 */
	private static function checked( string $kind, $result, UpgraderSkin $skin, bool $found, string $slug, string $version, string $after, JobRun $run, float $started, array $extra ): array {
		$name = (string) ( $extra['slug'] ?? $extra['stylesheet'] ?? '' );
		if ( true !== $result ) {
			$failure = self::failure_of( $result, $skin, 'install' );
			$error   = is_wp_error( $result ) ? $result : $skin->error_result();
			if ( null !== $error && 'folder_exists' === $error->get_error_code() ) {
				// Not a filesystem problem: the folder is someone's software.
				$failure = [
					'class'   => 'other',
					'message' => sprintf( 'A %s in the same folder is installed already, so WordPress did not install this one. Delete it first, or update it instead.', $kind ),
				];
			}
			self::fail( $failure, '', $after, $skin, $run, $started, $extra );
		}
		if ( ! $found ) {
			self::fail(
				[
					'class'   => 'other',
					'message' => sprintf( 'WordPress reported the install done, but no new %s is on the site.', $kind ),
				],
				'',
				'',
				$skin,
				$run,
				$started,
				$extra
			);
		}
		if ( '' !== $slug && $name !== $slug ) {
			self::fail(
				[
					'class'   => 'package',
					'message' => sprintf( 'The package installed the %s "%s" instead of "%s".', $kind, $name, $slug ),
				],
				'',
				$after,
				$skin,
				$run,
				$started,
				$extra
			);
		}
		if ( '' !== $version && $after !== $version ) {
			self::fail(
				[
					'class'   => 'package',
					'message' => sprintf( 'WordPress reported the install done, but the files say version %s instead of %s.', '' === $after ? '(none)' : $after, $version ),
				],
				'',
				$after,
				$skin,
				$run,
				$started,
				$extra
			);
		}

		return self::partial( '', $after, $skin->messages(), $run, $started, $extra );
	}

	/**
	 * What an install put in place: what the upgrader names when it is
	 * there, else the one new entry.
	 *
	 * @param string   $named  What the upgrader names, or ''.
	 * @param string[] $before The plugin files or theme folders before.
	 * @param string[] $after  The plugin files or theme folders after.
	 *
	 * @return string|null
	 */
	private static function installed( string $named, array $before, array $after ): ?string {
		if ( '' !== $named && in_array( $named, $after, true ) ) {
			return $named;
		}
		$new = array_values( array_diff( $after, $before ) );

		return 1 === count( $new ) ? (string) $new[0] : null;
	}

	/**
	 * The plugin files on the site, read fresh.
	 *
	 * @return string[]
	 */
	private static function plugin_files(): array {
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( false );
		}

		return array_map( 'strval', array_keys( get_plugins() ) );
	}

	/**
	 * The theme folders (stylesheets) on the site, read fresh, broken ones
	 * included.
	 *
	 * @return string[]
	 */
	private static function theme_folders(): array {
		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			wp_clean_themes_cache( false );
		}

		return array_map( 'strval', array_keys( wp_get_themes( [ 'errors' => null ] ) ) );
	}
}
