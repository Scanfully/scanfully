<?php
/**
 * The PHP error attribution class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Errors;

/**
 * Class Attribution
 *
 * Names what raised an error from the path of the file: a plugin (with its
 * slug and version), a theme, a must-use plugin, a drop-in, WordPress core,
 * or unknown. For an error raised inside core, the first stack frame outside
 * core names the plugin or theme that called it. Also makes paths relative
 * to the WordPress root, so no server path leaves the site.
 *
 * Built from WordPress's constants once per request; the roots can be given
 * directly for tests.
 *
 * @package Scanfully\Errors
 */
final class Attribution {

	/**
	 * The kinds of source, as the API knows them.
	 */
	public const PLUGIN    = 'plugin';
	public const THEME     = 'theme';
	public const MU_PLUGIN = 'mu_plugin';
	public const DROPIN    = 'dropin';
	public const CORE      = 'core';
	public const UNKNOWN   = 'unknown';

	/**
	 * The longest name or version kept.
	 */
	private const MAX_TEXT = 200;

	/**
	 * The roots, each with a trailing slash: `plugins`, `mu_plugins`,
	 * `themes` (a list), `content` and `abspath`, with their real paths
	 * when those differ.
	 *
	 * @var array<string, string[]>
	 */
	private array $roots;

	/**
	 * The WordPress version.
	 *
	 * @var string
	 */
	private string $wp_version;

	/**
	 * Reads the name and version of a source: `callable(string $kind, string $slug, string $base): array{name: string, version: string}`.
	 *
	 * @var callable|null
	 */
	private $headers;

	/**
	 * Sources already looked up, by kind and slug.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, string|string[]> $roots      The roots: `plugins`, `mu_plugins`, `themes` (one or a list), `content` and `abspath`.
	 * @param string                         $wp_version The WordPress version.
	 * @param callable|null                  $headers    Reads the name and version of a source, by kind, slug and where it is.
	 */
	public function __construct( array $roots, string $wp_version = '', ?callable $headers = null ) {
		$this->roots      = [];
		$this->wp_version = $wp_version;
		$this->headers    = $headers;
		foreach ( [ 'plugins', 'mu_plugins', 'themes', 'content', 'abspath' ] as $kind ) {
			$this->roots[ $kind ] = [];
			foreach ( (array) ( $roots[ $kind ] ?? [] ) as $root ) {
				$this->add_root( $kind, (string) $root );
			}
		}
	}

	/**
	 * The attribution for this WordPress site.
	 *
	 * @return self
	 */
	public static function from_wordpress(): self {
		$themes = [];
		if ( function_exists( 'get_theme_root' ) ) {
			$themes[] = get_theme_root();
		}
		foreach ( (array) ( $GLOBALS['wp_theme_directories'] ?? [] ) as $directory ) {
			$themes[] = (string) $directory;
		}
		$content = defined( 'WP_CONTENT_DIR' ) ? (string) WP_CONTENT_DIR : ABSPATH . 'wp-content';
		if ( [] === $themes ) {
			$themes[] = $content . '/themes';
		}

		return new self(
			[
				'plugins'    => defined( 'WP_PLUGIN_DIR' ) ? (string) WP_PLUGIN_DIR : $content . '/plugins',
				'mu_plugins' => defined( 'WPMU_PLUGIN_DIR' ) ? (string) WPMU_PLUGIN_DIR : $content . '/mu-plugins',
				'themes'     => $themes,
				'content'    => $content,
				'abspath'    => (string) ABSPATH,
			],
			(string) ( $GLOBALS['wp_version'] ?? '' ),
			[ self::class, 'wordpress_headers' ]
		);
	}

	/**
	 * Add a root and, when it differs, its real path (a symbolic link to
	 * the site or to `wp-content` resolves to the real path in error
	 * messages).
	 *
	 * @param string $kind The kind of root.
	 * @param string $root The root.
	 *
	 * @return void
	 */
	private function add_root( string $kind, string $root ): void {
		$root = rtrim( self::normalize( $root ), '/' );
		// A root of "/" would match everything.
		if ( strlen( $root ) < 2 ) {
			return;
		}
		$this->roots[ $kind ][] = $root . '/';

		$real = @realpath( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A root may not exist, or open_basedir may refuse it.
		if ( is_string( $real ) ) {
			$real = rtrim( self::normalize( $real ), '/' );
			if ( strlen( $real ) >= 2 && $real !== $root ) {
				$this->roots[ $kind ][] = $real . '/';
			}
		}
	}

	/**
	 * A path with forward slashes.
	 *
	 * @param string $path The path.
	 *
	 * @return string
	 */
	private static function normalize( string $path ): string {
		return str_replace( '\\', '/', $path );
	}

	/**
	 * The part of a path under a root, or null when it isn't under it.
	 *
	 * @param string $path  The path, normalized.
	 * @param string $kind  The kind of root.
	 * @param string $found The root it was found under; set when found.
	 *
	 * @return string|null
	 */
	private function under( string $path, string $kind, string &$found = '' ): ?string {
		foreach ( $this->roots[ $kind ] as $root ) {
			if ( 0 === strpos( $path, $root ) ) {
				$found = $root;

				return substr( $path, strlen( $root ) );
			}
		}

		return null;
	}

	/**
	 * A path relative to the WordPress root: `wp-content/plugins/akismet/akismet.php`.
	 * A path under `wp-content` outside the root (Bedrock) gets its logical
	 * name; any other path keeps its last three parts.
	 *
	 * @param string $path The path.
	 *
	 * @return string
	 */
	public function relative( string $path ): string {
		$path = self::normalize( self::plain_file( $path ) );
		if ( '' === $path ) {
			return '';
		}

		$rest = $this->under( $path, 'abspath' );
		if ( null !== $rest ) {
			return $rest;
		}
		foreach ( [ 'plugins', 'mu_plugins', 'themes', 'content' ] as $kind ) {
			$rest = $this->under( $path, $kind );
			if ( null !== $rest ) {
				return self::label( $kind ) . $rest;
			}
		}
		if ( '/' !== $path[0] && 1 !== preg_match( '#^[A-Za-z]:/#', $path ) ) {
			// Already relative, or no path at all.
			return $path;
		}
		$parts = explode( '/', trim( $path, '/' ) );

		return '…/' . implode( '/', array_slice( $parts, -3 ) );
	}

	/**
	 * Replace the server paths in a message by their relative form.
	 *
	 * @param string $text The message.
	 *
	 * @return string
	 */
	public function relative_in_text( string $text ): string {
		$roots = [];
		foreach ( [ 'plugins', 'mu_plugins', 'themes', 'content', 'abspath' ] as $kind ) {
			foreach ( $this->roots[ $kind ] as $root ) {
				$roots[ $root ] = self::label( $kind );
			}
		}
		uksort(
			$roots,
			static function ( string $a, string $b ): int {
				return strlen( $b ) - strlen( $a );
			}
		);

		foreach ( $roots as $root => $label ) {
			$text = str_replace( [ $root, str_replace( '/', '\\', $root ) ], $label, $text );
		}

		// Any other absolute path keeps only its last part.
		$text = (string) preg_replace( '#(?<![\w/.~\-])/(?:[^\s\'"<>/\\\\]+/)+([^\s\'"<>/\\\\]*)#', '…/$1', $text );

		return (string) preg_replace( '#(?<![\w:])[A-Za-z]:[/\\\\](?:[^\s\'"<>/\\\\]+[/\\\\])*([^\s\'"<>/\\\\]*)#', '…/$1', $text );
	}

	/**
	 * The relative name of a root.
	 *
	 * @param string $kind The kind of root.
	 *
	 * @return string
	 */
	private static function label( string $kind ): string {
		switch ( $kind ) {
			case 'plugins':
				return 'wp-content/plugins/';
			case 'mu_plugins':
				return 'wp-content/mu-plugins/';
			case 'themes':
				return 'wp-content/themes/';
			case 'content':
				return 'wp-content/';
		}

		return '';
	}

	/**
	 * The file of an error without PHP's decorations: `eval()'d code` and
	 * `runtime-created function` suffixes.
	 *
	 * @param string $file The file as PHP reports it.
	 *
	 * @return string
	 */
	private static function plain_file( string $file ): string {
		$plain = preg_replace( '/\(\d+\) : (?:eval\(\)\'d code|runtime-created function|assert code|regexp code).*$/', '', $file );

		return is_string( $plain ) ? $plain : $file;
	}

	/**
	 * What raised an error: its kind and slug, with its name and version
	 * when known. For an error inside core, the first frame of the trace
	 * outside core names the source.
	 *
	 * @param string                           $file  The file the error was raised in.
	 * @param array<int, array<string, mixed>> $trace The stack frames, each with a `file`.
	 *
	 * @return array{kind: string, slug: string, name: string, version: string}
	 */
	public function source( string $file, array $trace = [] ): array {
		$located = $this->locate( $file );
		if ( self::CORE === $located['kind'] ) {
			foreach ( $trace as $frame ) {
				$candidate = $this->locate( (string) ( $frame['file'] ?? '' ) );
				if ( in_array( $candidate['kind'], [ self::PLUGIN, self::THEME, self::MU_PLUGIN, self::DROPIN ], true ) ) {
					$located = $candidate;
					break;
				}
			}
		}

		return $this->describe( $located['kind'], $located['slug'], $located['base'] );
	}

	/**
	 * The kind and slug of the source of a file, and where the source is:
	 * its folder, or its file for a single-file plugin, must-use plugin or
	 * drop-in.
	 *
	 * @param string $file The file.
	 *
	 * @return array{kind: string, slug: string, base: string}
	 */
	public function locate( string $file ): array {
		$path    = self::normalize( self::plain_file( $file ) );
		$unknown = [
			'kind' => self::UNKNOWN,
			'slug' => '',
			'base' => '',
		];
		if ( '' === $path ) {
			return $unknown;
		}

		// A plugin in its own folder is identified by the folder; a
		// single-file plugin (hello.php) by its file name, as the site data
		// sync does. A theme by its folder; a must-use plugin and a drop-in
		// by their file name (or the folder a loader includes code from).
		foreach ( [
			'plugins' => self::PLUGIN,
			'mu_plugins' => self::MU_PLUGIN,
			'themes' => self::THEME,
		] as $root_kind => $kind ) {
			$root = '';
			$rest = $this->under( $path, $root_kind, $root );
			if ( null === $rest || '' === $rest ) {
				continue;
			}
			$slash = strpos( $rest, '/' );
			$name  = false === $slash ? $rest : substr( $rest, 0, $slash );
			$slug  = self::MU_PLUGIN === $kind || false !== $slash ? $name : (string) preg_replace( '/\.php$/i', '', $name );

			return [
				'kind' => $kind,
				'slug' => $slug,
				'base' => $root . $name,
			];
		}

		$root = '';
		$rest = $this->under( $path, 'content', $root );
		if ( null !== $rest && '' !== $rest && false === strpos( $rest, '/' ) ) {
			return [
				'kind' => self::DROPIN,
				'slug' => $rest,
				'base' => $root . $rest,
			];
		}

		$root = '';
		$rest = $this->under( $path, 'abspath', $root );
		if ( null !== $rest && '' !== $rest && ( 0 === strpos( $rest, 'wp-admin/' ) || 0 === strpos( $rest, 'wp-includes/' ) || false === strpos( $rest, '/' ) ) ) {
			return [
				'kind' => self::CORE,
				'slug' => 'wordpress',
				'base' => $root,
			];
		}

		return $unknown;
	}

	/**
	 * A source with its name and version.
	 *
	 * @param string $kind The kind.
	 * @param string $slug The slug.
	 * @param string $base Where the source is: its folder or file.
	 *
	 * @return array{kind: string, slug: string, name: string, version: string}
	 */
	private function describe( string $kind, string $slug, string $base ): array {
		$key = $kind . ':' . $slug;
		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}

		$name    = '';
		$version = '';
		if ( self::CORE === $kind ) {
			$name    = 'WordPress';
			$version = $this->wp_version;
		} elseif ( self::UNKNOWN !== $kind && null !== $this->headers ) {
			try {
				$header  = (array) call_user_func( $this->headers, $kind, $slug, $base );
				$name    = (string) ( $header['name'] ?? '' );
				$version = (string) ( $header['version'] ?? '' );
			} catch ( \Throwable $e ) {
				// A header that can't be read leaves the name empty.
				$name = '';
			}
		}

		$this->cache[ $key ] = [
			'kind'    => $kind,
			'slug'    => $slug,
			'name'    => self::text( $name ),
			'version' => self::text( $version ),
		];

		return $this->cache[ $key ];
	}

	/**
	 * The name and version of a plugin, theme, must-use plugin or drop-in
	 * from its header. Reads one file: the main file of a plugin (found in
	 * the active plugins, which WordPress has in memory), the stylesheet of
	 * a theme, or the file itself. Runs at shutdown, once per source.
	 *
	 * @param string $kind The kind.
	 * @param string $slug The slug.
	 * @param string $base Where the source is: its folder or file.
	 *
	 * @return array{name: string, version: string}
	 */
	public static function wordpress_headers( string $kind, string $slug, string $base = '' ): array {
		$empty = [
			'name'    => '',
			'version' => '',
		];
		if ( '' === $slug || '' === $base || ! function_exists( 'get_file_data' ) ) {
			return $empty;
		}

		$file  = '';
		$names = [
			'name'    => 'Plugin Name',
			'version' => 'Version',
		];
		if ( self::THEME === $kind ) {
			$file          = $base . '/style.css';
			$names['name'] = 'Theme Name';
		} elseif ( self::PLUGIN === $kind && is_dir( $base ) ) {
			$file = self::plugin_main_file( $slug, $base );
		} elseif ( is_file( $base ) ) {
			$file = $base;
		}
		if ( '' === $file || ! is_readable( $file ) ) {
			return $empty;
		}

		$header = get_file_data( $file, $names );

		return [
			'name'    => (string) ( $header['name'] ?? '' ),
			'version' => (string) ( $header['version'] ?? '' ),
		];
	}

	/**
	 * The main file of a plugin in a folder: the active plugin in that
	 * folder, else the file named after the folder.
	 *
	 * @param string $slug The folder name.
	 * @param string $base The folder.
	 *
	 * @return string
	 */
	private static function plugin_main_file( string $slug, string $base ): string {
		$active = function_exists( 'get_option' ) ? (array) get_option( 'active_plugins', [] ) : [];
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
		}
		foreach ( $active as $basename ) {
			$basename = (string) $basename;
			if ( 0 === strpos( $basename, $slug . '/' ) && false === strpos( substr( $basename, strlen( $slug ) + 1 ), '/' ) ) {
				return $base . '/' . substr( $basename, strlen( $slug ) + 1 );
			}
		}

		return $base . '/' . $slug . '.php';
	}

	/**
	 * Header text as plain text, cut to the longest kept.
	 *
	 * @param string $value The header value.
	 *
	 * @return string
	 */
	private static function text( string $value ): string {
		$value = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $value ) : strip_tags( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Only without WordPress.
		$value = trim( html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ) );

		return Scrubber::cap( $value, self::MAX_TEXT );
	}
}
