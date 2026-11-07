<?php
/**
 * Software inventory unit tests: plugins, themes, core and translations as
 * WordPress's update transients describe them.
 *
 * @package Scanfully\Tests\Unit\Health
 */

namespace Scanfully\Tests\Unit\Health;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use ReflectionMethod;
use Scanfully\Health\Controller;
use Scanfully\Health\Inventory;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Health\Inventory
 * @covers \Scanfully\Health\Controller
 */
final class InventoryTest extends TestCase {

	/**
	 * The site transients, by name.
	 *
	 * @var array<string, mixed>
	 */
	private $transients = [];

	/**
	 * The site options, by name.
	 *
	 * @var array<string, mixed>
	 */
	private $options = [];

	protected function set_up(): void {
		parent::set_up();

		$this->transients = [
			// One plugin with an update and a package, one whose premium
			// updater announces an update without a package (it needs a
			// license), one up to date.
			'update_plugins' => (object) [
				'response'  => [
					'akismet/akismet.php' => (object) [
						'slug'         => 'akismet',
						'new_version'  => '5.3.1',
						'package'      => 'https://downloads.wordpress.org/plugin/akismet.5.3.1.zip',
						'requires'     => '5.8',
						'requires_php' => '7.4',
					],
					'premium/premium.php' => [
						'new_version' => '2.1',
						'package'     => '',
						'url'         => 'https://premium.scanfully.com/',
					],
				],
				'no_update' => [
					'hello.php' => (object) [
						'new_version' => '1.7.2',
						'package'     => 'https://downloads.wordpress.org/plugin/hello-dolly.1.7.2.zip',
					],
				],
			],
			// A parent theme with an update whose package URL carries a
			// license key, and its child in use.
			'update_themes'  => (object) [
				'response'  => [
					'parent-theme' => [
						'theme'        => 'parent-theme',
						'new_version'  => '1.2',
						'package'      => 'https://user:secret@Updates.Scanfully.com:8443/download.zip?license=abc123',
						'requires'     => '6.0',
						'requires_php' => '8.0',
					],
				],
				'no_update' => [
					'child-theme' => [ 'new_version' => '1.0' ],
				],
			],
			// Core 6.4.2: 6.4.5 in its own line, 6.7.1 the latest, offered
			// once per locale.
			'update_core'    => (object) [
				'updates' => [
					(object) [
						'response' => 'upgrade',
						'current'  => '6.7.1',
						'locale'   => 'nl_NL',
						'package'  => '',
						'packages' => (object) [ 'full' => '' ],
					],
					(object) [
						'response' => 'upgrade',
						'current'  => '6.7.1',
						'locale'   => 'en_US',
						'packages' => (object) [ 'full' => 'https://downloads.wordpress.org/release/wordpress-6.7.1.zip' ],
					],
					(object) [
						'response' => 'autoupdate',
						'current'  => '6.4.5',
						'locale'   => 'en_US',
						'packages' => (object) [ 'full' => 'https://downloads.wordpress.org/release/wordpress-6.4.5.zip' ],
					],
					(object) [
						'response' => 'development',
						'current'  => '6.8-beta1',
						'packages' => (object) [ 'full' => 'https://wordpress.org/nightly.zip' ],
					],
				],
			],
		];
		$this->options    = [
			'auto_update_plugins' => [ 'akismet/akismet.php' ],
			'auto_update_themes'  => [],
		];

		Functions\when( 'get_site_transient' )->alias(
			function ( $name ) {
				return $this->transients[ $name ] ?? false;
			}
		);
		Functions\when( 'get_site_option' )->alias(
			function ( $name, $fallback = false ) {
				return $this->options[ $name ] ?? $fallback;
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_is_auto_update_enabled_for_type' )->justReturn( true );
		Functions\when( 'wp_is_auto_update_forced_for_item' )->justReturn( null );
		Functions\when( 'wp_is_file_mod_allowed' )->justReturn( true );
		Functions\when( 'get_bloginfo' )->justReturn( '6.4.2' );
		Functions\when( 'plugin_basename' )->returnArg();
		Functions\when( 'is_plugin_active' )->justReturn( true );
		Functions\when( 'get_stylesheet' )->justReturn( 'child-theme' );
		Functions\when( 'wp_get_themes' )->justReturn(
			[
				'child-theme'  => $this->theme( 'Child', '1.0', 'parent-theme', [] ),
				'parent-theme' => $this->theme(
					'Parent',
					'1.1',
					'parent-theme',
					[
						'RequiresPHP' => '7.4',
						'RequiresWP' => '5.9',
					]
				),
			]
		);
		Functions\when( 'wp_get_translation_updates' )->justReturn( [ (object) [ 'type' => 'core' ], (object) [ 'type' => 'plugin' ] ] );
	}

	/**
	 * A WP_Theme as the collector reads it.
	 *
	 * @param string                $name     Name.
	 * @param string                $version  Version.
	 * @param string                $template The parent's stylesheet, or its own.
	 * @param array<string, string> $headers  Other headers.
	 *
	 * @return object
	 */
	private function theme( string $name, string $version, string $template, array $headers ) {
		$headers = array_merge(
			[
				'Name'    => $name,
				'Version' => $version,
			],
			$headers
		);

		return new class( $headers, $template ) {
			/**
			 * Headers.
			 *
			 * @var array<string, string>
			 */
			private $headers;

			/**
			 * Template.
			 *
			 * @var string
			 */
			private $template;

			/**
			 * Constructor.
			 *
			 * @param array<string, string> $headers  Headers.
			 * @param string                $template Template.
			 */
			public function __construct( array $headers, string $template ) {
				$this->headers  = $headers;
				$this->template = $template;
			}

			/**
			 * A header.
			 *
			 * @param string $header The header.
			 *
			 * @return string|false
			 */
			public function get( string $header ) {
				return $this->headers[ $header ] ?? false;
			}

			/**
			 * The template.
			 *
			 * @return string
			 */
			public function get_template(): string {
				return $this->template;
			}
		};
	}

	/**
	 * A plugin header as get_plugins() returns it.
	 *
	 * @param string                $name    Name.
	 * @param string                $version Version.
	 * @param array<string, string> $extra   Other headers.
	 *
	 * @return array<string, string>
	 */
	private function header( string $name, string $version, array $extra = [] ): array {
		return array_merge(
			[
				'Name'        => $name,
				'PluginURI'   => '',
				'Version'     => $version,
				'Description' => '',
				'Author'      => '',
				'AuthorURI'   => '',
				'RequiresWP'  => '',
				'RequiresPHP' => '',
			],
			$extra
		);
	}

	/**
	 * The plugins as reported, by slug.
	 *
	 * @param array<string, array<string, string>> $plugins The plugins, by file.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function plugins( array $plugins ): array {
		Functions\when( 'get_plugins' )->justReturn( $plugins );

		$method = new ReflectionMethod( Controller::class, 'get_plugins' );
		$method->setAccessible( true );

		return array_column( $method->invoke( null ), null, 'slug' );
	}

	public function test_a_plugin_update_with_and_without_a_package(): void {
		$plugins = $this->plugins(
			[
				'akismet/akismet.php' => $this->header(
					'Akismet',
					'5.3',
					[
						'RequiresPHP' => '7.2',
						'RequiresWP' => '5.0',
					]
				),
				'premium/premium.php' => $this->header( 'Premium', '2.0' ),
				'hello.php'           => $this->header( 'Hello Dolly', '1.7.2' ),
			]
		);

		$akismet = $plugins['akismet'];
		$this->assertSame( '5.3.1', $akismet['latest_version'] );
		$this->assertTrue( $akismet['package'] );
		$this->assertSame( 'downloads.wordpress.org', $akismet['source'] );
		$this->assertSame( '7.2', $akismet['requires_php'] );
		$this->assertSame( '5.0', $akismet['requires_wp'] );
		$this->assertSame( '7.4', $akismet['update_requires_php'] );
		$this->assertSame( '5.8', $akismet['update_requires_wp'] );
		$this->assertTrue( $akismet['auto_update'] );

		// api#261: WordPress announces the update but cannot download it.
		$premium = $plugins['premium'];
		$this->assertSame( '2.1', $premium['latest_version'] );
		$this->assertFalse( $premium['package'], 'An update without a package is reported as package false.' );
		$this->assertNull( $premium['source'] );
		$this->assertNull( $premium['requires_php'], 'An empty header is not reported.' );
		$this->assertFalse( $premium['auto_update'] );

		$hello = $plugins['hello'];
		$this->assertSame( '1.7.2', $hello['latest_version'] );
		$this->assertNull( $hello['package'], 'An up-to-date plugin has no update package.' );
		$this->assertNull( $hello['source'] );
		$this->assertNull( $hello['update_requires_php'] );
	}

	public function test_a_package_url_is_reported_as_its_host_only(): void {
		$themes = array_column( Inventory::themes(), null, 'stylesheet' );

		$this->assertSame( 'updates.scanfully.com', $themes['parent-theme']['source'] );
		$this->assertStringNotContainsString( 'abc123', (string) self::json( $themes ) );
		$this->assertStringNotContainsString( 'secret', (string) self::json( $themes ) );
	}

	public function test_themes(): void {
		$themes = array_column( Inventory::themes(), null, 'stylesheet' );

		$child = $themes['child-theme'];
		$this->assertTrue( $child['active'] );
		$this->assertSame( 'parent-theme', $child['parent'] );
		$this->assertSame( '1.0', $child['latest_version'] );
		$this->assertNull( $child['package'] );
		$this->assertNull( $child['requires_php'] );

		$parent = $themes['parent-theme'];
		$this->assertFalse( $parent['active'] );
		$this->assertNull( $parent['parent'] );
		$this->assertSame( 'Parent', $parent['name'] );
		$this->assertSame( '1.1', $parent['version'] );
		$this->assertSame( '1.2', $parent['latest_version'] );
		$this->assertTrue( $parent['package'] );
		$this->assertSame( '7.4', $parent['requires_php'] );
		$this->assertSame( '5.9', $parent['requires_wp'] );
		$this->assertSame( '8.0', $parent['update_requires_php'] );
		$this->assertSame( '6.0', $parent['update_requires_wp'] );
		$this->assertFalse( $parent['auto_update'] );
	}

	public function test_core_with_a_same_line_release(): void {
		$core = Inventory::core();

		$this->assertSame( '6.4.2', $core['installed'] );
		$this->assertSame( '6.7.1', $core['latest'], 'A development offer is not a release.' );
		$this->assertSame( '6.4.5', $core['same_line_latest'] );
		$this->assertTrue( $core['package'], 'One locale with a package is enough.' );
		$this->assertTrue( $core['same_line_package'] );
		$this->assertSame( 'minor', $core['auto_update'] );
	}

	public function test_core_without_an_update_check(): void {
		$this->transients = [];

		$core = Inventory::core( '6.7.1' );

		$this->assertSame( '6.7.1', $core['installed'] );
		$this->assertNull( $core['latest'] );
		$this->assertNull( $core['same_line_latest'] );
		$this->assertNull( $core['package'] );
		$this->assertNull( $core['same_line_package'] );
	}

	public function test_core_auto_updates(): void {
		$this->options['auto_update_core_major'] = 'enabled';
		$this->assertSame( 'major', Inventory::core()['auto_update'] );

		Filters\expectApplied( 'allow_major_auto_core_updates' )->andReturn( false );
		Filters\expectApplied( 'allow_minor_auto_core_updates' )->andReturn( false );
		$this->assertSame( 'none', Inventory::core()['auto_update'] );
	}

	public function test_core_auto_updates_off_without_file_changes(): void {
		Functions\when( 'wp_is_file_mod_allowed' )->justReturn( false );

		$this->assertSame( 'none', Inventory::core()['auto_update'] );
	}

	public function test_a_forced_auto_update_wins(): void {
		Functions\when( 'wp_is_auto_update_forced_for_item' )->justReturn( true );
		$plugins = $this->plugins( [ 'premium/premium.php' => $this->header( 'Premium', '2.0' ) ] );
		$this->assertTrue( $plugins['premium']['auto_update'] );

		Functions\when( 'wp_is_auto_update_enabled_for_type' )->justReturn( false );
		$plugins = $this->plugins( [ 'akismet/akismet.php' => $this->header( 'Akismet', '5.3' ) ] );
		$this->assertFalse( $plugins['akismet']['auto_update'], 'Auto-updates of the type are off.' );
	}

	public function test_translations(): void {
		$this->assertSame( [ 'available' => 2 ], Inventory::translations() );
	}

	public function test_the_site_data_carries_the_inventory(): void {
		$data = $this->site_data_inventory( 3, 1 );

		$this->assertArrayHasKey( 'themes', $data );
		$this->assertArrayHasKey( 'core', $data );
		$this->assertArrayHasKey( 'translations', $data );
		$this->assertCount( 3, $data['plugins'] );
	}

	/**
	 * The report of a site with 300 plugins and 50 themes, with long names
	 * and descriptions, stays far under the API's 8 MiB body limit for
	 * plugin requests (measured at 534,620 bytes, about 0.5 MiB).
	 */
	public function test_the_report_of_a_large_site_stays_under_the_body_limit(): void {
		$data  = $this->site_data_inventory( 300, 50 );
		$bytes = strlen( (string) self::json( $data ) );

		$this->assertLessThan( 1024 * 1024, $bytes, 'The inventory stays under 1 MiB.' );
		$this->assertLessThan( 8 * 1024 * 1024, $bytes );
	}

	/**
	 * The inventory part of the site data for a site with many plugins and
	 * themes, each with long texts and an update.
	 *
	 * @param int $plugin_count How many plugins.
	 * @param int $theme_count  How many themes.
	 *
	 * @return array<string, mixed>
	 */
	private function site_data_inventory( int $plugin_count, int $theme_count ): array {
		$plugins   = [];
		$responses = [];
		for ( $i = 0; $i < $plugin_count; $i++ ) {
			$file             = sprintf( 'plugin-with-a-long-folder-name-%03d/plugin-with-a-long-folder-name-%03d.php', $i, $i );
			$plugins[ $file ] = $this->header(
				str_repeat( 'Plugin name ', 8 ) . $i,
				'10.20.30',
				[
					'Description' => str_repeat( 'A long plugin description with <strong>markup</strong>. ', 20 ),
					'PluginURI'   => 'https://scanfully.com/plugins/' . str_repeat( 'x', 80 ),
					'Author'      => 'Scanfully',
					'AuthorURI'   => 'https://scanfully.com/',
					'RequiresPHP' => '7.4',
					'RequiresWP'  => '6.0',
				]
			);
			$responses[ $file ] = (object) [
				'new_version'  => '10.20.31',
				'package'      => 'https://downloads.wordpress.org/plugin/' . $i . '.zip',
				'requires'     => '6.2',
				'requires_php' => '8.0',
			];
		}
		$themes = [];
		for ( $i = 0; $i < $theme_count; $i++ ) {
			$themes[ 'theme-' . $i ] = $this->theme( str_repeat( 'Theme name ', 8 ), '3.2.1', 'theme-' . $i, [ 'RequiresPHP' => '7.4' ] );
		}
		$this->transients['update_plugins'] = (object) [ 'response' => $responses ];
		Functions\when( 'wp_get_themes' )->justReturn( $themes );

		$method = new ReflectionMethod( Controller::class, 'get_plugins' );
		$method->setAccessible( true );
		Functions\when( 'get_plugins' )->justReturn( $plugins );

		return [
			'plugins' => $method->invoke( null ),
			'themes' => Inventory::themes(),
			'core' => Inventory::core(),
			'translations' => Inventory::translations(),
		];
	}

	/**
	 * JSON as the request sends it (wp_json_encode without WordPress).
	 *
	 * @param mixed $data The data.
	 *
	 * @return string|false
	 */
	private static function json( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}
