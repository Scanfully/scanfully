<?php
/**
 * The health class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Health;

use Scanfully\API\HealthRequest;
use Scanfully\API\SiteDataRequest;
use Scanfully\API\SiteDirectoriesRequest;

/**
 * Health Controller. This handles everything related to the health.
 */
class Controller {

	/**
	 * The PHP memory limit when the plugin booted, before Action Scheduler or
	 * wp-admin raised it. Null until recorded.
	 *
	 * @var string|null
	 */
	private static ?string $boot_memory_limit = null;

	/**
	 * Record the PHP memory limit as it is on a normal page load.
	 *
	 * Health data is collected inside Action Scheduler jobs, which raise the
	 * limit to the admin value first. Reading it then would hide a low limit
	 * from the Scanfully health check, so it is recorded here, at boot.
	 *
	 * @return void
	 */
	public static function record_boot_memory_limit(): void {
		$limit                   = ini_get( 'memory_limit' );
		self::$boot_memory_limit = false === $limit ? null : (string) $limit;
	}


	/**
	 * Detect if the site is using SSL/HTTPS.
	 *
	 * This improves upon is_ssl() by also checking common headers
	 * set by reverse proxies and load balancers.
	 *
	 * @return bool
	 */
	private static function detect_ssl(): bool {
		// First check WordPress's built-in function.
		if ( is_ssl() ) {
			return true;
		}

		// Check X-Forwarded-Proto header (common for reverse proxies/load balancers).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ) {
			return true;
		}

		// Check X-Forwarded-SSL header.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_SERVER['HTTP_X_FORWARDED_SSL'] ) && 'on' === strtolower( wp_unslash( $_SERVER['HTTP_X_FORWARDED_SSL'] ) ) ) {
			return true;
		}

		// Check X-Forwarded-Scheme header.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_SERVER['HTTP_X_FORWARDED_SCHEME'] ) && 'https' === strtolower( wp_unslash( $_SERVER['HTTP_X_FORWARDED_SCHEME'] ) ) ) {
			return true;
		}

		// Check if home URL uses HTTPS (use home_url for Bedrock compatibility).
		if ( 0 === strpos( home_url(), 'https://' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the server architecture
	 *
	 * @return string|null
	 */
	private static function get_server_arch(): ?string {
		if ( function_exists( 'php_uname' ) ) {
			return sprintf( '%s %s %s', php_uname( 's' ), php_uname( 'r' ), php_uname( 'm' ) );
		}

		return null;
	}

	/**
	 * Parse /etc/os-release into an associative array of its key/value pairs.
	 *
	 * Values are unquoted. Returns an empty array when the file cannot be read
	 * (e.g. non-Linux hosts, restrictive open_basedir, or disabled functions).
	 *
	 * @return array<string, string>
	 */
	private static function get_os_release(): array {
		static $os_release = null;
		if ( null !== $os_release ) {
			return $os_release;
		}

		$os_release = [];

		// The spec reads /etc/os-release first, then falls back to /usr/lib/os-release.
		$path = null;
		foreach ( [ '/etc/os-release', '/usr/lib/os-release' ] as $candidate ) {
			if ( is_readable( $candidate ) ) {
				$path = $candidate;
				break;
			}
		}
		if ( null === $path ) {
			return $os_release;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return $os_release;
		}

		foreach ( preg_split( '/\R/', $contents ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] || false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $key, $value ) = explode( '=', $line, 2 );
			$os_release[ strtolower( trim( $key ) ) ] = trim( $value, " \t\"'" );
		}

		return $os_release;
	}

	/**
	 * Get the operating system distribution ID (e.g. "ubuntu", "debian").
	 *
	 * @return string|null
	 */
	private static function get_os_id(): ?string {
		$os_release = self::get_os_release();

		return $os_release['id'] ?? null;
	}

	/**
	 * Get the operating system distribution family list (os-release ID_LIKE).
	 *
	 * @return string|null
	 */
	private static function get_os_id_like(): ?string {
		$os_release = self::get_os_release();

		return $os_release['id_like'] ?? null;
	}

	/**
	 * Get the operating system distribution version (os-release VERSION_ID).
	 *
	 * @return string|null
	 */
	private static function get_os_version(): ?string {
		$os_release = self::get_os_release();

		return $os_release['version_id'] ?? null;
	}

	/**
	 * Get the PHP version
	 *
	 * @return string
	 */
	private static function get_php_version(): string {
		return sprintf(
			'%s %s',
			PHP_VERSION,
			( PHP_INT_SIZE * 8 === 64 ) ? 'x64' : 'x86'
		);
	}

	/**
	 * Get the curl version
	 *
	 * @return string|null
	 */
	private static function get_curl_version(): ?string {
		if ( function_exists( 'curl_version' ) ) {
			$curl = curl_version();

			return sprintf( '%s %s', $curl['version'], $curl['ssl_version'] );
		}

		return null;
	}

	/**
	 * Get the PHP SAPI
	 *
	 * @return string|null
	 */
	private static function get_php_sapi(): ?string {
		if ( function_exists( 'php_sapi_name' ) ) {
			return php_sapi_name();
		}

		return null;
	}

	/**
	 * Get various php settings
	 *
	 * @return array<string, string|null>
	 */
	private static function get_php_settings(): array {
		$ini_values = [
			'memory_limit' => null,
			'max_input_time' => null,
			'max_execution_time' => null,
			'upload_max_filesize' => null,
			'post_max_size' => null,
		];

		// get actual values if ini_get is available.
		if ( function_exists( 'ini_get' ) ) {
			foreach ( $ini_values as $ini_key => $default_value ) {
				$v = ini_get( $ini_key );

				// ini_get returns false if the ini key is not set. We set it to null in this case.
				if ( false === $v ) {
					$v = null;
				}

				$ini_values[ $ini_key ] = $v;
			}
		}

		return $ini_values;
	}

	/**
	 * Get the database extension used
	 *
	 * @return string|null
	 */
	private static function get_db_extension(): ?string {
		global $wpdb;
		$extension = null;
		if ( is_resource( $wpdb->dbh ) ) {
			// Old mysql extension.
			$extension = 'mysql';
		} elseif ( is_object( $wpdb->dbh ) ) {
			// mysqli or PDO.
			$extension = get_class( $wpdb->dbh );
		}

		return $extension;
	}

	/**
	 * Get the database server version
	 *
	 * @return string|null
	 */
	private static function get_db_server_version(): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( 'SELECT VERSION()' );
	}

	/**
	 * Get the database client version
	 *
	 * @return string|null
	 */
	private static function get_db_client_version(): ?string {
		global $wpdb;
		$client_version = null;
		if ( isset( $wpdb->use_mysqli ) && $wpdb->use_mysqli ) {
			$client_version = $wpdb->dbh->client_info;
		} elseif ( function_exists( 'mysql_get_client_info' ) ) {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysql_get_client_info,PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved
			if ( preg_match( '|[0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,2}|', mysql_get_client_info(), $matches ) ) {
				$client_version = $matches[0];
			}
		}

		return $client_version;
	}

	/**
	 * Get the database user
	 *
	 * @return string
	 */
	private static function get_db_user(): string {
		global $wpdb;

		return $wpdb->dbuser;
	}

	/**
	 * Get the maximum number of connections allowed by the database server
	 *
	 * @return int|null
	 */
	private static function get_db_max_connections(): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_row(
			$wpdb->prepare( 'SHOW VARIABLES LIKE %s', 'max_connections' ),
			ARRAY_A
		);

		if ( ! empty( $result ) && array_key_exists( 'Value', $result ) ) {
			return (int) $result['Value'];
		}

		return null;
	}

	/**
	 * Get database charset
	 *
	 * @return string
	 */
	private static function get_db_charset(): string {
		global $wpdb;

		return $wpdb->charset;
	}

	/**
	 * Get database charset
	 *
	 * @return string
	 */
	private static function get_db_collate(): string {
		global $wpdb;

		return $wpdb->collate;
	}

	/**
	 * Gets the size of the database in bytes
	 *
	 * @return int
	 */
	private static function get_db_size(): int {
		global $wpdb;
		$size = 0;
		// Only this install's tables: other installs can share the database.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ), ARRAY_A );

		if ( $wpdb->num_rows > 0 ) {
			foreach ( $rows as $row ) {
				$size += $row['Data_length'] + $row['Index_length'];
			}
		}

		return (int) $size;
	}

	/**
	 * Get the list of plugins
	 *
	 * @return array
	 */
	private static function get_plugins(): array {
		$plugins = get_plugins();

		$map = [];

		foreach ( $plugins as $plugin_path => $plugin ) {
			// A plugin in its own folder is identified by the folder; a
			// single-file plugin (e.g. hello.php) by its file name.
			$basename = plugin_basename( $plugin_path );
			$folder   = dirname( $basename );

			$map[] = [
				'active' => is_plugin_active( $plugin_path ),
				'name' => $plugin['Name'],
				'slug' => '.' === $folder ? basename( $basename, '.php' ) : $folder,
				'url' => $plugin['PluginURI'],
				'version' => $plugin['Version'],
				'description' => $plugin['Description'],
				'author' => $plugin['Author'],
				'author_uri' => $plugin['AuthorURI'],
			];
		}

		return $map;
	}

	/**
	 * Get the site data sent to Scanfully.
	 *
	 * @return array
	 */
	public static function get_site_data(): array {
		// load wp_site_health class if not loaded, this is not loaded by default.
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// get php settings array.
		$php_settings = self::get_php_settings();

		$data = [
			'data' => [
				'wp_version' => get_bloginfo( 'version' ),
				'wp_multisite' => is_multisite(),
				'wp_user_registration' => (bool) get_option( 'users_can_register' ),
				'wp_blog_public' => (bool) get_option( 'blog_public' ),
				'https' => self::detect_ssl(),

				'wp_cache' => (bool) WP_CACHE,
				'wp_debug' => (bool) WP_DEBUG,
				'wp_debug_display' => (bool) WP_DEBUG_DISPLAY,
				'wp_debug_log' => (bool) WP_DEBUG_LOG,
				'wp_script_debug' => (bool) SCRIPT_DEBUG,
				'wp_memory_limit' => WP_MEMORY_LIMIT,
				'wp_max_memory_limit' => WP_MAX_MEMORY_LIMIT,
				'wp_environment_type' => wp_get_environment_type(),
				'permalink_structure' => get_option( 'permalink_structure' ),
				'locale' => get_locale(),
				// get_user_count() is cached; count_users() scans every user's
				// capabilities. On multisite count_users() is kept, because it
				// counts this site's users rather than the whole network's.
				'user_count' => is_multisite() ? (int) count_users()['total_users'] : (int) get_user_count(),
				'site_url' => home_url(),

				'server_arch' => self::get_server_arch(),
				'os_id' => self::get_os_id(),
				'os_id_like' => self::get_os_id_like(),
				'os_version' => self::get_os_version(),
				// Not set under WP-CLI or a system cron running Action Scheduler.
				'web_server' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : null,
				'curl_version' => self::get_curl_version(),
				'imagick_available' => extension_loaded( 'imagick' ),

				'php_version' => self::get_php_version(),
				'php_sapi' => self::get_php_sapi(),
				'php_memory_limit' => self::$boot_memory_limit ?? \WP_Site_Health::get_instance()->php_memory_limit,
				'php_memory_limit_admin' => $php_settings['memory_limit'],
				'php_max_input_time' => (int) $php_settings['max_input_time'],
				'php_max_execution_time' => (int) $php_settings['max_execution_time'],
				'php_upload_max_filesize' => $php_settings['upload_max_filesize'],
				'php_post_max_size' => $php_settings['post_max_size'],

				'db_extension' => self::get_db_extension(),
				'db_server_version' => self::get_db_server_version(),
				'db_client_version' => self::get_db_client_version(),
				'db_user' => self::get_db_user(),
				'db_max_connections' => self::get_db_max_connections(),
				'db_charset' => self::get_db_charset(),
				'db_collate' => self::get_db_collate(),
			],
			'plugins' => self::get_plugins(),
		];

		// filter data.
		$data = apply_filters( 'scanfully_health_data', $data );

		return $data;
	}

	/**
	 * Send the health data to the API
	 *
	 * @return void
	 */
	public static function send_site_data(): void {

		// todo add a transient last sent time to prevent sending too many requests.

		// send event.
		$request = new SiteDataRequest();
		$request->send( self::get_site_data() );
	}

	/**
	 * Send the directory data to the API
	 *
	 * Sizes are measured fresh on every run, because WordPress caches
	 * directory sizes indefinitely and never clears them for plugin or theme
	 * changes. When a size can't be measured in time, nothing is sent: the API
	 * would otherwise store a size of 0. One retry is queued an hour later,
	 * which usually runs in a request with more time left.
	 *
	 * @return void
	 */
	public static function send_directories_data(): void {
		delete_transient( 'dirsize_cache' );

		// directories to check. Content first: it caches the sizes of the
		// directories inside it, so the others are read from that cache.
		$dirs = [
			'content' => WP_CONTENT_DIR,
			'plugins' => WP_PLUGIN_DIR,
			'themes' => get_theme_root( get_template() ),
			'uploads' => wp_upload_dir( null, false )['basedir'],
		];

		// data array.
		$data = [
			'data' => [
				'db_size' => round( self::get_db_size() / 1000000, 2 ),
			],
		];

		// add data for each directory.
		foreach ( $dirs as $key => $dir ) {
			// No time limit argument: WordPress then stops safely before the
			// PHP time limit (and has no limit under WP-CLI).
			$size = recurse_dirsize( $dir );
			if ( null === $size ) {
				self::schedule_directories_retry();
				return;
			}

			$data['data'][ $key . '_size' ] = (float) round( $size / 1000000, 2 );
			$data['data'][ $key . '_writable' ] = wp_is_writable( $dir );
			$data['data'][ $key . '_dir' ] = $dir;
		}

		// send event.
		$request = new SiteDirectoriesRequest();
		$request->send( $data );
	}

	/**
	 * Queue one retry of the directory sync an hour from now.
	 *
	 * The retry gets its own arguments: the daily recurring sync uses the same
	 * hook with no arguments, and the unique flag would otherwise refuse it.
	 *
	 * @return void
	 */
	private static function schedule_directories_retry(): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + HOUR_IN_SECONDS, \Scanfully\Cron\Controller::ACTION_SYNC_DIRECTORIES, [ 'retry' => 1 ], 'scanfully', true );
		}
	}
}
