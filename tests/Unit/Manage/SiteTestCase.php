<?php
/**
 * A fake WordPress site for the tests of the Manage actions that install,
 * switch and delete: plugins and themes on disk, the active ones, and
 * WordPress's functions faked over them.
 *
 * @package Scanfully\Tests\Unit\Manage
 */

namespace Scanfully\Tests\Unit\Manage;

use Brain\Monkey\Functions;
use Scanfully\Manage\Action;
use Scanfully\Manage\Inventory;
use Scanfully\Manage\JobFailed;
use Scanfully\Manage\JobRun;
use Scanfully\Manage\UpgraderSkin;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * The fake site.
 */
abstract class SiteTestCase extends TestCase {

	protected const JOB = 'b2b2b2b2-2222-4222-8222-222222222222';

	/**
	 * Whether the test made the wp-content folder under the test root.
	 *
	 * @var bool
	 */
	private bool $made_content = false;

	/**
	 * A folder for the theme root and temporary files.
	 *
	 * @var string
	 */
	protected string $tmp;

	/**
	 * The plugin folders the test made.
	 *
	 * @var string[]
	 */
	private array $made_plugins = [];

	/**
	 * Active plugins, by file.
	 *
	 * @var array<string, bool>
	 */
	public array $active = [];

	/**
	 * The options: stylesheet and template.
	 *
	 * @var array<string, string>
	 */
	public array $options = [];

	/**
	 * The run of the test's job.
	 *
	 * @var JobRun
	 */
	protected JobRun $run;

	/**
	 * What the fake upgrader's install() does: takes the kind, the package
	 * and the fake upgrader, and returns the upgrader's answer.
	 *
	 * @var callable
	 */
	public $install;

	/**
	 * The packages install() was called with, by kind.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public array $installs = [];

	/**
	 * What plugins_api() and themes_api() answer: takes the kind and the args.
	 *
	 * @var callable
	 */
	public $wporg;

	/**
	 * What activate_plugin() does: takes the file.
	 *
	 * @var callable
	 */
	public $activate;

	/**
	 * Whether WordPress may change files.
	 *
	 * @var bool
	 */
	public bool $file_mods = true;

	/**
	 * Whether WP_Filesystem() connects.
	 *
	 * @var bool
	 */
	public bool $filesystem = true;

	/**
	 * The errors of a theme, by stylesheet.
	 *
	 * @var array<string, \WP_Error>
	 */
	public array $theme_errors = [];

	protected function setUp(): void {
		parent::setUp();
		$this->made_content = ! is_dir( ABSPATH . 'wp-content' );
		$this->tmp          = sys_get_temp_dir() . '/scanfully-site-' . uniqid( '', true );
		mkdir( $this->tmp . '/themes', 0777, true );
		if ( ! is_dir( WP_PLUGIN_DIR ) ) {
			mkdir( WP_PLUGIN_DIR, 0777, true );
		}
		$this->write_theme( 'twentyfive', '1.3' );
		$this->options = [
			'stylesheet' => 'twentyfive',
			'template'   => 'twentyfive',
		];
		$this->run     = new JobRun( self::JOB, null, $this->tmp . '/.maintenance' );

		Inventory::$source = fn(): array => [
			'plugins'      => array_map(
				fn( string $file ): array => [
					'slug'   => dirname( $file ),
					'active' => $this->active[ $file ] ?? false,
				],
				array_keys( $this->plugins() )
			),
			'themes'       => array_map( static fn( string $s ): array => [ 'stylesheet' => $s ], $this->themes() ),
			'core'         => [ 'installed' => '7.1.4' ],
			'translations' => [],
		];
		$this->install    = static fn() => true;
		$this->wporg      = static fn() => new \WP_Error( 'plugins_api_failed', 'Plugin not found.' );
		$this->activate   = function ( string $file ) {
			$this->active[ $file ] = true;
			$this->run->tag_event( [ 'slug' => $file ], 'PluginActivate' );
			return null;
		};
		Action::$upgraders = function ( string $kind, UpgraderSkin $skin ) {
			return new FakeInstaller( $kind, $skin, $this );
		};

		Functions\when( 'wp_is_file_mod_allowed' )->alias( fn() => $this->file_mods );
		Functions\when( 'get_plugins' )->alias( [ $this, 'plugins' ] );
		Functions\when( 'get_core_updates' )->justReturn( [] );
		Functions\when( 'request_filesystem_credentials' )->justReturn( true );
		Functions\when( 'WP_Filesystem' )->alias( fn() => $this->filesystem );
		Functions\when( 'wp_update_plugins' )->justReturn( null );
		Functions\when( 'wp_clean_plugins_cache' )->justReturn( null );
		Functions\when( 'wp_clean_themes_cache' )->justReturn( null );
		Functions\when( 'wp_raise_memory_limit' )->justReturn( '256M' );
		Functions\when( 'get_site_transient' )->justReturn( (object) [ 'response' => [] ] );
		Functions\when( 'get_file_data' )->alias(
			static function ( string $file ): array {
				preg_match( '/^[ \t\/*#@]*Version:(.*)$/mi', (string) file_get_contents( $file ), $match );
				return [ 'Version' => trim( $match[1] ?? '' ) ];
			}
		);
		Functions\when( 'is_plugin_active' )->alias( fn( string $file ) => $this->active[ $file ] ?? false );
		Functions\when( 'is_plugin_active_for_network' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'activate_plugin' )->alias( fn( string $file ) => ( $this->activate )( $file ) );
		Functions\when( 'deactivate_plugins' )->alias(
			function ( $files ): void {
				foreach ( (array) $files as $file ) {
					unset( $this->active[ $file ] );
					$this->run->tag_event( [ 'slug' => $file ], 'PluginDeactivate' );
				}
			}
		);
		Functions\when( 'get_option' )->alias( fn( string $name ) => $this->options[ $name ] ?? false );
		Functions\when( 'switch_theme' )->alias(
			function ( string $stylesheet ): void {
				$this->options['stylesheet'] = $stylesheet;
				$this->options['template']   = $stylesheet;
				$this->run->tag_event( [ 'slug' => $stylesheet ], 'ThemeSwitched' );
			}
		);
		Functions\when( 'wp_get_theme' )->alias( fn( string $stylesheet ) => new FakeTheme( $stylesheet, is_dir( $this->tmp . '/themes/' . $stylesheet ), $this->theme_errors[ $stylesheet ] ?? false ) );
		Functions\when( 'wp_get_themes' )->alias( fn() => array_flip( $this->themes() ) );
		Functions\when( 'validate_theme_requirements' )->justReturn( true );
		Functions\when( 'delete_plugins' )->alias(
			function ( array $files ) {
				foreach ( $files as $file ) {
					$this->remove( WP_PLUGIN_DIR . '/' . dirname( $file ) );
					$this->run->tag_event( [ 'slug' => $file ], 'PluginDeleted' );
				}
				return true;
			}
		);
		Functions\when( 'delete_theme' )->alias(
			function ( string $stylesheet ) {
				$this->remove( $this->tmp . '/themes/' . $stylesheet );
				$this->run->tag_event( [ 'slug' => $stylesheet ], 'ThemeDeleted' );
				return true;
			}
		);
		Functions\when( 'plugins_api' )->alias( fn( string $action, array $args ) => ( $this->wporg )( 'plugin', $args ) );
		Functions\when( 'themes_api' )->alias( fn( string $action, array $args ) => ( $this->wporg )( 'theme', $args ) );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'get_filesystem_method' )->justReturn( 'direct' );
		Functions\when( 'get_theme_root' )->alias( fn() => $this->tmp . '/themes' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'plugin_basename' )->justReturn( 'scanfully/scanfully.php' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_generate_uuid4' )->alias( fn() => 'e1e1e1e1-1111-4111-8111-' . bin2hex( random_bytes( 6 ) ) );
		Functions\when( 'wp_delete_file' )->alias(
			static function ( string $file ): void {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
		);
	}

	protected function tearDown(): void {
		Action::$upgraders = null;
		Inventory::$source = null;
		foreach ( $this->made_plugins as $slug ) {
			$this->remove( WP_PLUGIN_DIR . '/' . $slug );
		}
		if ( $this->made_content ) {
			$this->remove( ABSPATH . 'wp-content' );
		}
		$this->remove( $this->tmp );
		parent::tearDown();
	}

	/**
	 * Remove a folder and what is in it.
	 *
	 * @param string $dir The folder.
	 */
	public function remove( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$path = $dir . '/' . $name;
				is_dir( $path ) ? $this->remove( $path ) : unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Put a plugin on disk.
	 *
	 * @param string $slug    The folder.
	 * @param string $version The version.
	 */
	public function write_plugin( string $slug, string $version ): void {
		if ( ! is_dir( WP_PLUGIN_DIR . '/' . $slug ) ) {
			mkdir( WP_PLUGIN_DIR . '/' . $slug );
			$this->made_plugins[] = $slug;
		}
		file_put_contents( WP_PLUGIN_DIR . "/$slug/$slug.php", "<?php\n/**\n * Plugin Name: Fixture $slug\n * Version: $version\n */\n" );
	}

	/**
	 * Put a theme on disk.
	 *
	 * @param string $stylesheet The folder.
	 * @param string $version    The version.
	 */
	public function write_theme( string $stylesheet, string $version ): void {
		if ( ! is_dir( $this->tmp . '/themes/' . $stylesheet ) ) {
			mkdir( $this->tmp . '/themes/' . $stylesheet );
		}
		file_put_contents( $this->tmp . "/themes/$stylesheet/style.css", "/*\nTheme Name: Fixture\nVersion: $version\n*/\n" );
	}

	/**
	 * The installed plugins the test made, as get_plugins() lists them.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function plugins(): array {
		$out = [];
		foreach ( $this->made_plugins as $slug ) {
			if ( is_file( WP_PLUGIN_DIR . "/$slug/$slug.php" ) ) {
				$out[ "$slug/$slug.php" ] = [ 'Name' => "Fixture $slug" ];
			}
		}
		return $out;
	}

	/**
	 * The installed themes.
	 *
	 * @return string[]
	 */
	public function themes(): array {
		return array_values( array_diff( (array) scandir( $this->tmp . '/themes' ), [ '.', '..' ] ) );
	}

	/**
	 * Run an action and give the JobFailed it threw.
	 *
	 * @param callable $action The action.
	 */
	protected function failure( callable $action ): JobFailed {
		try {
			$action();
		} catch ( JobFailed $e ) {
			return $e;
		}
		$this->fail( 'The action did not fail.' );
	}
}

/**
 * Stands in for Plugin_Upgrader and Theme_Upgrader's install().
 */
final class FakeInstaller {

	/**
	 * The kind.
	 *
	 * @var string
	 */
	public string $kind;

	/**
	 * The skin.
	 *
	 * @var UpgraderSkin
	 */
	public UpgraderSkin $skin;

	/**
	 * The site.
	 *
	 * @var SiteTestCase
	 */
	private SiteTestCase $site;

	/**
	 * What plugin_info() or theme_info() names after the install.
	 *
	 * @var string
	 */
	public string $installed = '';

	/**
	 * Constructor.
	 *
	 * @param string       $kind The kind.
	 * @param UpgraderSkin $skin The skin.
	 * @param SiteTestCase $site The site.
	 */
	public function __construct( string $kind, UpgraderSkin $skin, SiteTestCase $site ) {
		$this->kind = $kind;
		$this->skin = $skin;
		$this->site = $site;
	}

	/**
	 * Install a package.
	 *
	 * @param string $package The package.
	 *
	 * @return mixed
	 */
	public function install( string $package ) {
		$this->site->installs[] = [ $this->kind, $package ];
		return ( $this->site->install )( $this->kind, $package, $this );
	}

	/**
	 * The plugin the install put in place.
	 *
	 * @return string|false
	 */
	public function plugin_info() {
		return '' === $this->installed ? false : $this->installed;
	}

	/**
	 * The theme the install put in place.
	 *
	 * @return FakeTheme|false
	 */
	public function theme_info() {
		return '' === $this->installed ? false : new FakeTheme( $this->installed, true, false );
	}
}

/**
 * Stands in for WP_Theme.
 */
final class FakeTheme {

	/**
	 * The stylesheet.
	 *
	 * @var string
	 */
	private string $stylesheet;

	/**
	 * Whether it is installed.
	 *
	 * @var bool
	 */
	private bool $exists;

	/**
	 * Its errors.
	 *
	 * @var \WP_Error|false
	 */
	private $errors;

	/**
	 * Constructor.
	 *
	 * @param string          $stylesheet The stylesheet.
	 * @param bool            $exists     Whether it is installed.
	 * @param \WP_Error|false $errors     Its errors.
	 */
	public function __construct( string $stylesheet, bool $exists, $errors ) {
		$this->stylesheet = $stylesheet;
		$this->exists     = $exists;
		$this->errors     = $errors;
	}

	/**
	 * Whether it is installed.
	 *
	 * @return bool
	 */
	public function exists(): bool {
		return $this->exists;
	}

	/**
	 * Its errors.
	 *
	 * @return \WP_Error|false
	 */
	public function errors() {
		return $this->errors;
	}

	/**
	 * The stylesheet.
	 *
	 * @return string
	 */
	public function get_stylesheet(): string {
		return $this->stylesheet;
	}
}
