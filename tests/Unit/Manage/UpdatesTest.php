<?php
/**
 * Manage update action unit tests, with WordPress's upgraders faked: a
 * plugin, a theme, WordPress itself and the translations.
 *
 * @package Scanfully\Tests\Unit\Manage
 */

namespace Scanfully\Tests\Unit\Manage;

use Brain\Monkey\Functions;
use Scanfully\Manage\Inventory;
use Scanfully\Manage\JobFailed;
use Scanfully\Manage\JobRun;
use Scanfully\Manage\Updates;
use Scanfully\Manage\UpgraderSkin;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Manage\Updates
 * @covers \Scanfully\Manage\UpgraderSkin
 * @covers \Scanfully\Manage\Inventory
 * @covers \Scanfully\Manage\Failure
 * @covers \Scanfully\Manage\JobFailed
 */
final class UpdatesTest extends TestCase {

	private const JOB = 'a1a1a1a1-1111-4111-8111-111111111111';

	/**
	 * Whether the test made the wp-content folder under the test root.
	 *
	 * @var bool
	 */
	private bool $made_content = false;

	/**
	 * A folder for the theme root and the version file.
	 *
	 * @var string
	 */
	private string $tmp;

	/**
	 * The update_plugins, update_themes transients.
	 *
	 * @var array<string, object>
	 */
	private array $transients = [];

	/**
	 * Active plugins, by file.
	 *
	 * @var array<string, bool>
	 */
	private array $active = [];

	/**
	 * Plugins activate_plugin() was called for.
	 *
	 * @var string[]
	 */
	private array $activated = [];

	/**
	 * The language packs WordPress offers.
	 *
	 * @var array<int, object>
	 */
	private array $language_updates = [];

	/**
	 * The core offers.
	 *
	 * @var array<int, object>
	 */
	private array $core_offers = [];

	/**
	 * Whether WordPress may change files.
	 *
	 * @var bool
	 */
	private bool $file_mods = true;

	/**
	 * What the fake upgrader does: takes the kind, the skin and the argument.
	 *
	 * @var callable
	 */
	private $upgrade;

	/**
	 * The upgrader calls: kind and argument.
	 *
	 * @var \ArrayObject<int, array{0: string, 1: mixed}>
	 */
	private \ArrayObject $upgrades;

	protected function setUp(): void {
		parent::setUp();
		$this->made_content = ! is_dir( ABSPATH . 'wp-content' );
		$this->tmp          = sys_get_temp_dir() . '/scanfully-updates-' . uniqid( '', true );
		mkdir( $this->tmp . '/themes', 0777, true );
		if ( ! is_dir( WP_PLUGIN_DIR ) ) {
			mkdir( WP_PLUGIN_DIR, 0777, true );
		}
		$this->write_plugin( 'hello-fixture', '1.0.0' );
		$this->write_theme( 'fixture-theme', '2.0' );
		$this->write_core( '7.1.3' );
		$GLOBALS['wpdb'] = new FakeWpdb();

		Updates::$version_file = $this->tmp . '/version.php';
		Inventory::$source     = fn(): array => [
			'data'    => [ 'wp_version' => '7.1.3' ],
			'plugins' => [
				[
					'slug'        => 'hello-fixture',
					'version'     => $this->plugin_version( 'hello-fixture' ),
					'active'      => $this->active['hello-fixture/hello-fixture.php'] ?? false,
					'description' => str_repeat( 'long text ', 50 ),
				],
			],
			'themes'  => [],
			'core'    => [ 'version' => Updates::core_version() ],
		];
		$this->upgrade         = static fn() => true;
		$this->upgrades        = new \ArrayObject();
		Updates::$upgraders    = function ( string $kind, UpgraderSkin $skin ) {
			return new FakeUpgrader( $kind, $skin, $this->upgrade, $this->upgrades );
		};

		Functions\when( 'wp_is_file_mod_allowed' )->alias( fn() => $this->file_mods );
		Functions\when( 'get_core_updates' )->alias( fn() => $this->core_offers );
		Functions\when( 'get_plugins' )->alias( [ $this, 'plugins' ] );
		Functions\when( 'request_filesystem_credentials' )->justReturn( true );
		Functions\when( 'wp_update_plugins' )->justReturn( null );
		Functions\when( 'wp_update_themes' )->justReturn( null );
		Functions\when( 'wp_version_check' )->justReturn( null );
		Functions\when( 'wp_clean_plugins_cache' )->justReturn( null );
		Functions\when( 'wp_clean_themes_cache' )->justReturn( null );
		Functions\when( 'wp_raise_memory_limit' )->justReturn( '256M' );
		Functions\when( 'get_site_transient' )->alias( fn( string $name ) => $this->transients[ $name ] ?? false );
		Functions\when( 'get_file_data' )->alias(
			static function ( string $file ): array {
				preg_match( '/^[ \t\/*#@]*Version:(.*)$/mi', (string) file_get_contents( $file ), $match );
				return [ 'Version' => trim( $match[1] ?? '' ) ];
			}
		);
		Functions\when( 'is_plugin_active' )->alias( fn( string $file ) => $this->active[ $file ] ?? false );
		Functions\when( 'is_plugin_active_for_network' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'activate_plugin' )->alias(
			function ( string $file ) {
				$this->activated[]     = $file;
				$this->active[ $file ] = true;
				return null;
			}
		);
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'get_filesystem_method' )->justReturn( 'direct' );
		Functions\when( 'get_theme_root' )->alias( fn() => $this->tmp . '/themes' );
		Functions\when( 'wp_get_translation_updates' )->alias( fn() => $this->language_updates );
		Functions\when( 'get_locale' )->justReturn( 'nl_NL' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'plugin_basename' )->justReturn( 'scanfully/scanfully.php' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_generate_uuid4' )->alias( fn() => 'e1e1e1e1-1111-4111-8111-' . bin2hex( random_bytes( 6 ) ) );
	}

	protected function tearDown(): void {
		Updates::$upgraders    = null;
		Updates::$version_file = null;
		Inventory::$source     = null;
		unset( $GLOBALS['wpdb'] );
		$this->remove( $this->made_content ? ABSPATH . 'wp-content' : WP_PLUGIN_DIR . '/hello-fixture' );
		$this->remove( $this->tmp );
		parent::tearDown();
	}

	/**
	 * Remove a folder and what is in it.
	 *
	 * @param string $dir The folder.
	 */
	private function remove( string $dir ): void {
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

	public function write_plugin( string $slug, string $version ): void {
		if ( ! is_dir( WP_PLUGIN_DIR . '/' . $slug ) ) {
			mkdir( WP_PLUGIN_DIR . '/' . $slug );
		}
		file_put_contents( WP_PLUGIN_DIR . "/$slug/$slug.php", "<?php\n/**\n * Plugin Name: Hello Fixture\n * Version: $version\n */\n" );
	}

	public function plugin_version( string $slug ): string {
		$file = WP_PLUGIN_DIR . "/$slug/$slug.php";
		return is_file( $file ) && preg_match( '/Version: (\S+)/', (string) file_get_contents( $file ), $m ) ? $m[1] : '';
	}

	public function write_theme( string $stylesheet, string $version ): void {
		if ( ! is_dir( $this->tmp . '/themes/' . $stylesheet ) ) {
			mkdir( $this->tmp . '/themes/' . $stylesheet );
		}
		file_put_contents( $this->tmp . "/themes/$stylesheet/style.css", "/*\nTheme Name: Fixture\nVersion: $version\n*/\n" );
	}

	public function write_core( string $version ): void {
		file_put_contents( $this->tmp . '/version.php', "<?php\n\$wp_version = '$version';\n" );
	}

	/**
	 * The installed plugins, as get_plugins() lists them.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function plugins(): array {
		return [ 'hello-fixture/hello-fixture.php' => [ 'Name' => 'Hello Fixture' ] ];
	}

	/**
	 * WordPress offers a plugin update.
	 *
	 * @param string $version The version.
	 * @param string $package The package URL.
	 */
	private function offer_plugin( string $version, string $package = 'https://downloads.wordpress.org/plugin/hello-fixture.zip' ): void {
		$this->transients['update_plugins'] = (object) [
			'response' => [
				'hello-fixture/hello-fixture.php' => (object) [
					'new_version' => $version,
					'package'     => $package,
				],
			],
		];
	}

	private function job_run(): JobRun {
		return new JobRun( self::JOB, null, $this->tmp . '/.maintenance' );
	}

	/**
	 * Run an action and give the JobFailed it threw.
	 *
	 * @param callable $action The action.
	 */
	private function failure( callable $action ): JobFailed {
		try {
			$action();
		} catch ( JobFailed $e ) {
			return $e;
		}
		$this->fail( 'The action did not fail.' );
	}

	public function test_a_plugin_is_updated_to_the_version_offered(): void {
		$this->offer_plugin( '1.1.0' );
		$this->active['hello-fixture/hello-fixture.php'] = true;
		$run           = $this->job_run();
		$this->upgrade = function ( string $kind, UpgraderSkin $skin, $file ) use ( $run ): bool {
			// WordPress switches the plugin off outside of WP-Cron, as here.
			$this->active[ $file ] = false;
			$skin->feedback( 'downloading_package', 'https://downloads.wordpress.org/plugin/hello-fixture.zip?token=SECRET' );
			$skin->feedback( 'unpack_package' );
			$this->write_plugin( 'hello-fixture', '1.1.0' );
			$run->tag_event( [ 'slug' => $file ], 'PluginUpdate' );
			$skin->feedback( 'process_success' );
			return true;
		};

		$result = Updates::plugin(
			[
				'slug'           => 'hello-fixture',
				'target_version' => '1.1.0',
			],
			static function (): void {},
			$run
		);

		$this->assertSame( [ [ 'plugin', 'hello-fixture/hello-fixture.php' ] ], $this->upgrades->getArrayCopy() );
		$this->assertSame( '1.0.0', $result['previous_version'] );
		$this->assertSame( '1.1.0', $result['new_version'] );
		$this->assertIsInt( $result['duration_ms'] );
		$this->assertSame( [ 'hello-fixture/hello-fixture.php' ], $this->activated, 'switched on again, as it was active' );
		$this->assertTrue( $result['inventory']['plugins'][0]['active'], 'the inventory is read after the plugin is on again' );
		$this->assertSame( '1.1.0', $result['inventory']['plugins'][0]['version'] );
		$this->assertArrayNotHasKey( 'description', $result['inventory']['plugins'][0], 'long text stays out of the result' );
		$this->assertSame( [ 'version' => '7.1.3' ], $result['inventory']['core'] );
		$this->assertCount( 1, $result['activity_event_ids'] );
		$this->assertStringNotContainsString( 'SECRET', implode( ' ', $result['log'] ) );
		$this->assertSame( 'Downloading update from https://downloads.wordpress.org/plugin/hello-fixture.zip', $result['log'][0] );
		$this->assertSame( 'Scanfully switched the plugin on again after the update, as it was active before.', $run->log_lines()[0] );
	}

	public function test_a_plugin_with_no_target_takes_the_newest_version_offered(): void {
		$this->offer_plugin( '2.0.0' );
		$this->upgrade = function (): bool {
			$this->write_plugin( 'hello-fixture', '2.0.0' );
			return true;
		};

		$result = Updates::plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->job_run() );

		$this->assertSame( '2.0.0', $result['new_version'] );
		$this->assertSame( [], $this->activated, 'an inactive plugin stays inactive' );
	}

	public function test_a_version_already_installed_changes_nothing(): void {
		$result = Updates::plugin(
			[
				'slug'           => 'hello-fixture',
				'target_version' => '1.0.0',
			],
			static function (): void {},
			$this->job_run()
		);

		$this->assertSame( [], $this->upgrades->getArrayCopy() );
		$this->assertSame( '1.0.0', $result['new_version'] );
		$this->assertStringContainsString( 'nothing to do', $result['log'][0] );
	}

	public function test_a_version_wordpress_does_not_offer_is_not_available_and_no_url_is_guessed(): void {
		$this->offer_plugin( '1.1.0' );

		$e = $this->failure(
			fn() => Updates::plugin(
				[
					'slug'           => 'hello-fixture',
					'target_version' => '1.2.0',
				],
				static function (): void {},
				$this->job_run()
			)
		);

		$this->assertSame( 'not_available', $e->error_class() );
		$this->assertStringContainsString( 'offers 1.1.0', $e->getMessage() );
		$this->assertSame( [], $this->upgrades->getArrayCopy() );
		$this->assertSame( '1.0.0', $e->result()['previous_version'] );
	}

	public function test_an_offer_without_a_package_needs_a_license(): void {
		$this->offer_plugin( '1.1.0', '' );

		$e = $this->failure( fn() => Updates::plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->job_run() ) );

		$this->assertSame( 'license', $e->error_class() );
		$this->assertSame( [], $this->upgrades->getArrayCopy() );
	}

	public function test_a_plugin_that_is_not_installed_or_scanfully_itself(): void {
		$e = $this->failure( fn() => Updates::plugin( [ 'slug' => 'akismet' ], static function (): void {}, $this->job_run() ) );
		$this->assertSame( 'not_available', $e->error_class() );

		Functions\when( 'plugin_basename' )->justReturn( 'hello-fixture/hello-fixture.php' );
		$e = $this->failure( fn() => Updates::plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->job_run() ) );
		$this->assertSame( 'not_supported', $e->error_class() );
	}

	public function test_malformed_params_are_refused(): void {
		$e = $this->failure( fn() => Updates::plugin( [ 'slug' => '../wp-config' ], static function (): void {}, $this->job_run() ) );
		$this->assertSame( 'other', $e->error_class() );

		$e = $this->failure( fn() => Updates::plugin( [], static function (): void {}, $this->job_run() ) );
		$this->assertStringContainsString( 'no slug', $e->getMessage() );
	}

	public function test_disallowed_file_mods_run_nothing(): void {
		$this->file_mods = false;
		$this->offer_plugin( '1.1.0' );

		$e = $this->failure( fn() => Updates::plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->job_run() ) );

		$this->assertSame( 'not_supported', $e->error_class() );
		$this->assertStringContainsString( 'DISALLOW_FILE_MODS', $e->getMessage() );
		$this->assertSame( [], $this->upgrades->getArrayCopy() );
	}

	/**
	 * @dataProvider provide_upgrader_failures
	 *
	 * @param mixed  $answer The upgrader's answer.
	 * @param string $method The filesystem method.
	 * @param string $class  The error class.
	 */
	public function test_an_upgrader_failure_maps_to_a_class( $answer, string $method, string $class ): void {
		Functions\when( 'get_filesystem_method' )->justReturn( $method );
		$this->offer_plugin( '1.1.0' );
		$this->upgrade = static fn() => $answer;

		$e = $this->failure( fn() => Updates::plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->job_run() ) );

		$this->assertSame( $class, $e->error_class() );
		$this->assertSame( '1.0.0', $e->result()['new_version'], 'the old version stays' );
		$this->assertArrayHasKey( 'inventory', $e->result() );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string, 2: string}>
	 */
	public static function provide_upgrader_failures(): array {
		return [
			'license'         => [ new \WP_Error( 'download_failed', 'Download failed.', 'Unauthorized' ), 'direct', 'license' ],
			'not writable'    => [ new \WP_Error( 'mkdir_failed_destination', 'Could not create directory.' ), 'direct', 'filesystem' ],
			'ftp credentials' => [ null, 'ftpext', 'filesystem' ],
			'timeout'         => [ new \WP_Error( 'download_failed', 'Download failed.', 'cURL error 28: Operation timed out' ), 'direct', 'timeout' ],
			'no reason'       => [ false, 'direct', 'other' ],
		];
	}

	public function test_a_failed_update_that_removed_the_plugin_says_so(): void {
		$this->offer_plugin( '1.1.0' );
		$this->active['hello-fixture/hello-fixture.php'] = true;
		$this->upgrade = function ( string $kind, UpgraderSkin $skin, $file ) {
			$this->active[ $file ] = false;
			unlink( WP_PLUGIN_DIR . '/hello-fixture/hello-fixture.php' );
			return new \WP_Error( 'copy_failed_copy_dir', 'Could not copy file.', ABSPATH . 'wp-content/plugins/hello-fixture/hello-fixture.php' );
		};

		$e = $this->failure( fn() => Updates::plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->job_run() ) );

		$this->assertSame( 'filesystem', $e->error_class() );
		$this->assertStringContainsString( 'files are missing', $e->getMessage() );
		$this->assertStringNotContainsString( ABSPATH, $e->getMessage() );
		$this->assertSame( [], $this->activated, 'nothing to switch on' );
		$this->assertSame( '', $e->result()['new_version'] );
	}

	public function test_a_plugin_that_cannot_be_switched_on_again_fails_the_job(): void {
		$this->offer_plugin( '1.1.0' );
		$this->active['hello-fixture/hello-fixture.php'] = true;
		Functions\when( 'activate_plugin' )->justReturn( new \WP_Error( 'plugin_wp_php_incompatible', 'This plugin requires a newer PHP version.' ) );
		$this->upgrade = function ( string $kind, UpgraderSkin $skin, $file ): bool {
			$this->active[ $file ] = false;
			$this->write_plugin( 'hello-fixture', '1.1.0' );
			return true;
		};

		$e = $this->failure( fn() => Updates::plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->job_run() ) );

		$this->assertSame( 'fatal', $e->error_class() );
		$this->assertStringContainsString( 'could not be switched on again', $e->getMessage() );
		$this->assertSame( '1.1.0', $e->result()['new_version'] );
	}

	public function test_files_of_another_version_are_a_package_problem_and_stay_off(): void {
		$this->offer_plugin( '1.1.0' );
		$this->active['hello-fixture/hello-fixture.php'] = true;
		$this->upgrade = function ( string $kind, UpgraderSkin $skin, $file ): bool {
			$this->active[ $file ] = false;
			$this->write_plugin( 'hello-fixture', '0.9.0-broken' );
			return true;
		};

		$e = $this->failure( fn() => Updates::plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->job_run() ) );

		$this->assertSame( 'package', $e->error_class() );
		$this->assertSame( [], $this->activated, 'not switched on with files of an unknown version' );
	}

	public function test_a_theme_is_updated(): void {
		$this->transients['update_themes'] = (object) [
			'response' => [
				'fixture-theme' => [
					'new_version' => '2.1',
					'package'     => 'https://downloads.wordpress.org/theme/fixture-theme.2.1.zip',
				],
			],
		];
		$this->upgrade = function (): bool {
			$this->write_theme( 'fixture-theme', '2.1' );
			return true;
		};

		$result = Updates::theme(
			[
				'stylesheet'     => 'fixture-theme',
				'target_version' => '2.1',
			],
			static function (): void {},
			$this->job_run()
		);

		$this->assertSame( [ [ 'theme', 'fixture-theme' ] ], $this->upgrades->getArrayCopy() );
		$this->assertSame( '2.0', $result['previous_version'] );
		$this->assertSame( '2.1', $result['new_version'] );

		$e = $this->failure( fn() => Updates::theme( [ 'stylesheet' => 'missing-theme' ], static function (): void {}, $this->job_run() ) );
		$this->assertSame( 'not_available', $e->error_class() );
	}

	/**
	 * A core offer.
	 *
	 * @param string $version  The version.
	 * @param string $response The response type.
	 * @param string $locale   The language.
	 */
	private static function core_offer( string $version, string $response = 'upgrade', string $locale = 'en_US' ): object {
		return (object) [
			'response' => $response,
			'current'  => $version,
			'locale'   => $locale,
			'packages' => (object) [ 'full' => "https://downloads.wordpress.org/release/wordpress-$version.zip" ],
		];
	}

	public function test_the_core_offer_follows_the_target(): void {
		$offers = [
			self::core_offer( '7.2.1', 'upgrade' ),
			self::core_offer( '7.2.1', 'upgrade', 'nl_NL' ),
			self::core_offer( '7.1.4', 'autoupdate' ),
			self::core_offer( '7.3-beta1', 'development' ),
		];

		$latest = Updates::core_offer( $offers, 'latest', '7.1.3', 'nl_NL' );
		$this->assertSame( '7.2.1', $latest->current ?? '' );
		$this->assertSame( 'nl_NL', $latest->locale ?? '', 'the site\'s language first' );
		$this->assertSame( '7.1.4', Updates::core_offer( $offers, 'same_line', '7.1.3', 'nl_NL' )->current ?? '' );
		$this->assertSame( '7.1.4', Updates::core_offer( $offers, '7.1.4', '7.1.3', 'de_DE' )->current ?? '' );
		$this->assertSame( 'en_US', Updates::core_offer( $offers, '7.2.1', '7.1.3', 'de_DE' )->locale ?? '' );
		$this->assertNull( Updates::core_offer( $offers, '7.3-beta1', '7.1.3', 'nl_NL' ), 'never a development release' );
		$this->assertNull( Updates::core_offer( $offers, 'same_line', '7.0.5', 'nl_NL' ), 'nothing newer in 7.0' );
	}

	public function test_wordpress_is_updated_within_its_line(): void {
		$this->core_offers = [ self::core_offer( '7.1.4', 'autoupdate' ), self::core_offer( '7.2.0', 'upgrade' ) ];
		$run               = $this->job_run();
		$this->upgrade     = function ( string $kind, UpgraderSkin $skin, $offer ) use ( $run ): string {
			$skin->feedback( 'maintenance_start' );
			$this->write_core( (string) $offer->current );
			$run->tag_event( [ 'version' => $offer->current ], 'CoreUpdate' );
			$skin->feedback( 'maintenance_end' );
			return (string) $offer->current;
		};

		$result = Updates::core( [ 'target' => 'same_line' ], static function (): void {}, $run );

		$this->assertSame( '7.1.3', $result['previous_version'] );
		$this->assertSame( '7.1.4', $result['new_version'] );
		$this->assertSame( [ 'version' => '7.1.4' ], $result['inventory']['core'], 'the inventory reads the new version file' );
		$this->assertCount( 1, $result['activity_event_ids'] );
	}

	public function test_wordpress_with_no_newer_release_in_its_line_is_not_available(): void {
		$this->core_offers = [ self::core_offer( '7.2.0', 'upgrade' ) ];

		$e = $this->failure( fn() => Updates::core( [ 'target' => 'same_line' ], static function (): void {}, $this->job_run() ) );
		$this->assertSame( 'not_available', $e->error_class() );
		$this->assertStringContainsString( '7.1', $e->getMessage() );

		$this->core_offers = [ self::core_offer( '7.1.3', 'latest' ) ];
		$result            = Updates::core( [], static function (): void {}, $this->job_run() );
		$this->assertSame( '7.1.3', $result['new_version'], 'latest when WordPress is the latest: nothing to do' );
		$this->assertSame( [], $this->upgrades->getArrayCopy() );
	}

	public function test_a_core_update_another_process_holds_the_lock_for(): void {
		$this->core_offers = [ self::core_offer( '7.2.0', 'upgrade' ) ];
		$this->upgrade     = static fn() => new \WP_Error( 'locked', 'Another update is currently in progress.' );

		$e = $this->failure( fn() => Updates::core( [ 'target' => 'latest' ], static function (): void {}, $this->job_run() ) );

		$this->assertSame( 'locked', $e->error_class() );
		$this->assertSame( '7.1.3', $e->result()['new_version'] );
	}

	public function test_the_translations_are_updated_and_each_pack_is_listed(): void {
		$this->language_updates = [
			(object) [
				'type'     => 'core',
				'slug'     => 'default',
				'language' => 'nl_NL',
				'version'  => '7.1.3',
			],
			(object) [
				'type'     => 'plugin',
				'slug'     => 'hello-fixture',
				'language' => 'nl_NL',
				'version'  => '1.0.0',
			],
		];
		$this->upgrade          = static fn() => [ [ 'source' => 'x' ], [ 'source' => 'y' ] ];

		$result = Updates::translations( [], static function (): void {}, $this->job_run() );

		$this->assertSame( 'translations', $this->upgrades[0][0] );
		$this->assertSame(
			[
				'type'     => 'plugin',
				'slug'     => 'hello-fixture',
				'language' => 'nl_NL',
				'version'  => '1.0.0',
				'updated'  => true,
			],
			$result['translations'][1]
		);

		$this->upgrade = static fn() => [ true, new \WP_Error( 'download_failed', 'Download failed.', 'Not Found' ) ];
		$e             = $this->failure( fn() => Updates::translations( [], static function (): void {}, $this->job_run() ) );
		$this->assertSame( 'package', $e->error_class() );
		$this->assertStringStartsWith( '1 of 2 language packs did not update.', $e->getMessage() );
		$this->assertFalse( $e->result()['translations'][1]['updated'] );
	}

	public function test_no_translation_updates_is_nothing_to_do(): void {
		$result = Updates::translations( [], static function (): void {}, $this->job_run() );

		$this->assertSame( [], $result['translations'] );
		$this->assertSame( [], $this->upgrades->getArrayCopy() );
	}

	public function test_the_skin_beats_at_once_at_long_steps_and_keeps_the_messages(): void {
		$sent  = 0;
		$clock = 1000;
		$beat  = new \Scanfully\Manage\Heartbeat(
			function () use ( &$sent ): bool {
				++$sent;
				return true;
			},
			30,
			function () use ( &$clock ): int {
				return $clock;
			}
		);
		$run   = $this->job_run();
		$run->set_heartbeat( $beat );
		$beat->start();
		$skin = new UpgraderSkin( $run );

		$skin->feedback( 'downloading_package' );
		$skin->feedback( 'Some line' );
		$skin->feedback( 'unpack_package' );
		$clock += 31;
		$skin->feedback( 'Another line' );

		$this->assertSame( 4, $sent, 'the start, two long steps at once, one line when due' );
		$this->assertSame( [ 'downloading_package', 'Some line', 'unpack_package', 'Another line' ], $skin->messages(), 'without an upgrader, the keys as they are' );
	}
}

/**
 * Stands in for WordPress's upgraders: calls the test's callable with the
 * kind, the skin and the argument, and records the call.
 */
final class FakeUpgrader {

	/**
	 * The kind.
	 *
	 * @var string
	 */
	private string $kind;

	/**
	 * The skin.
	 *
	 * @var UpgraderSkin
	 */
	private UpgraderSkin $skin;

	/**
	 * What to do.
	 *
	 * @var callable
	 */
	private $upgrade;

	/**
	 * The calls.
	 *
	 * @var \ArrayObject<int, array{0: string, 1: mixed}>
	 */
	private \ArrayObject $calls;

	/**
	 * Constructor.
	 *
	 * @param string       $kind    The kind.
	 * @param UpgraderSkin $skin    The skin.
	 * @param callable     $upgrade What to do.
	 * @param \ArrayObject $calls   The calls.
	 *
	 * @phpstan-param \ArrayObject<int, array{0: string, 1: mixed}> $calls
	 */
	public function __construct( string $kind, UpgraderSkin $skin, callable $upgrade, \ArrayObject $calls ) {
		$this->kind    = $kind;
		$this->skin    = $skin;
		$this->upgrade = $upgrade;
		$this->calls   = $calls;
		// The upgrader's strings, as WordPress's skins read them.
		$upgrader          = new \WP_Upgrader();
		$upgrader->strings = [
			'downloading_package' => 'Downloading update from %s&#8230;',
			'unpack_package'      => 'Unpacking the update&#8230;',
			'process_success'     => 'Plugin updated successfully.',
		];
		$skin->upgrader    = $upgrader;
	}

	/**
	 * Upgrade one item.
	 *
	 * @param mixed $item The plugin file, theme stylesheet or core offer.
	 *
	 * @return mixed
	 */
	public function upgrade( $item ) {
		$this->calls[] = [ $this->kind, $item ];
		$result        = call_user_func( $this->upgrade, $this->kind, $this->skin, $item );
		if ( $result instanceof \WP_Error ) {
			$this->skin->set_result( $result );
		}
		return $result;
	}

	/**
	 * Upgrade the language packs.
	 *
	 * @param array $updates The packs.
	 *
	 * @return mixed
	 */
	public function bulk_upgrade( array $updates ) {
		$this->calls[] = [ $this->kind, $updates ];
		return call_user_func( $this->upgrade, $this->kind, $this->skin, $updates );
	}
}
