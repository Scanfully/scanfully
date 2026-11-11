<?php
/**
 * Manage delete action unit tests, with WordPress's functions faked.
 *
 * @package Scanfully\Tests\Unit\Manage
 */

namespace Scanfully\Tests\Unit\Manage;

use Brain\Monkey\Functions;
use Scanfully\Manage\Deletes;

/**
 * @covers \Scanfully\Manage\Deletes
 * @covers \Scanfully\Manage\Action
 */
final class DeletesTest extends SiteTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->write_plugin( 'hello-fixture', '1.2.0' );
		$this->write_plugin( 'scanfully', '2.0.0' );
		$this->write_theme( 'blocksy', '2.1' );
		$this->write_theme( 'blocksy-child', '1.0' );
	}

	public function test_an_inactive_plugin_is_deleted(): void {
		$result = Deletes::plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->run );

		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/hello-fixture' );
		$this->assertSame( '1.2.0', $result['previous_version'] );
		$this->assertSame( '', $result['new_version'] );
		$this->assertSame( 'hello-fixture', $result['slug'] );
		$this->assertCount( 1, $result['activity_event_ids'], 'the delete event carries the job id' );
	}

	public function test_an_active_plugin_is_refused_as_plugin_active(): void {
		$this->active['hello-fixture/hello-fixture.php'] = true;

		$e = $this->failure( fn() => Deletes::plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->run ) );

		$this->assertSame( 'plugin_active', $e->error_class() );
		$this->assertDirectoryExists( WP_PLUGIN_DIR . '/hello-fixture' );
	}

	public function test_on_a_network_a_plugin_or_theme_another_site_uses_is_not_deleted(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_plugin_active_for_network' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_sites' )->justReturn( [ 1, 2, 3 ] );
		Functions\when( 'get_home_url' )->alias( static fn( int $site ): string => 'https://scanfully.com/site-' . $site );
		Functions\when( 'maybe_unserialize' )->alias( static fn( $value ) => is_string( $value ) && 0 === strpos( $value, 'a:' ) ? unserialize( $value ) : $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Test double of maybe_unserialize().
		$blog_options = [
			2 => [
			'active_plugins' => serialize( [] ),
			'stylesheet' => 'twentytwentyfour',
			'template' => 'twentytwentyfour',
				], // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- As WordPress stores it.
			3 => [
			'active_plugins' => serialize( [ 'hello-fixture/hello-fixture.php' ] ),
			'stylesheet' => 'blocksy-child',
			'template' => 'blocksy',
			], // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- As WordPress stores it.
		];
		$beats = 0;
		$before = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new class( $blog_options ) {
			/**
			 * The options of each site, by id.
			 *
			 * @var array<int, array<string, string>>
			 */
			public array $sites;

			/**
			 * The site the last prefix was for.
			 *
			 * @var int
			 */
			private int $site = 0;

			public function __construct( array &$sites ) {
				$this->sites = &$sites;
			}

			public function get_blog_prefix( int $site ): string {
				$this->site = $site;
				return 'wp_' . $site . '_';
			}

			public function get_results( string $query ): array {
				$rows = [];
				foreach ( $this->sites[ $this->site ] ?? [] as $name => $value ) {
					$rows[] = [
						'option_name' => $name,
						'option_value' => $value,
					];
				}
				return $rows;
			}
		};
		$beat = static function () use ( &$beats ): void {
			++$beats;
		};

		try {
			$e = $this->failure( fn() => Deletes::plugin( [ 'slug' => 'hello-fixture' ], $beat, $this->run ) );
			$this->assertSame( 'plugin_active', $e->error_class() );
			$this->assertStringContainsString( 'https://scanfully.com/site-3', $e->getMessage() );
			$this->assertDirectoryExists( WP_PLUGIN_DIR . '/hello-fixture' );

			foreach ( [ 'blocksy', 'blocksy-child' ] as $theme ) {
				$e = $this->failure( fn() => Deletes::theme( [ 'stylesheet' => $theme ], $beat, $this->run ) );
				$this->assertSame( 'theme_in_use', $e->error_class(), $theme . ' is the theme or parent of site 3' );
				$this->assertDirectoryExists( get_theme_root( $theme ) . '/' . $theme );
			}

			// No other site uses it: deleted, with a heartbeat on the way.
			$blog_options[3]['active_plugins'] = serialize( [] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- As WordPress stores it.
			Deletes::plugin( [ 'slug' => 'hello-fixture' ], $beat, $this->run );
			$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/hello-fixture' );
			$this->assertGreaterThan( 0, $beats );
		} finally {
			$GLOBALS['wpdb'] = $before;
		}
	}

	public function test_the_scanfully_plugin_is_never_deleted(): void {
		$e = $this->failure( fn() => Deletes::plugin( [ 'slug' => 'scanfully' ], static function (): void {}, $this->run ) );

		$this->assertSame( 'not_supported', $e->error_class() );
		$this->assertDirectoryExists( WP_PLUGIN_DIR . '/scanfully' );
	}

	public function test_another_plugin_named_like_scanfully_may_be_deleted(): void {
		Functions\when( 'plugin_basename' )->justReturn( 'scanfully-plugin/scanfully-plugin.php' );
		$this->write_plugin( 'scanfully-plugin', '2.0.0' );

		$this->assertSame( 'not_supported', $this->failure( fn() => Deletes::plugin( [ 'slug' => 'scanfully-plugin' ], static function (): void {}, $this->run ) )->error_class() );
		Deletes::plugin( [ 'slug' => 'scanfully' ], static function (): void {}, $this->run );
		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/scanfully' );
		$this->assertDirectoryExists( WP_PLUGIN_DIR . '/scanfully-plugin' );
	}

	public function test_a_plugin_that_is_gone_fails_as_not_installed(): void {
		$e = $this->failure( fn() => Deletes::plugin( [ 'slug' => 'nope' ], static function (): void {}, $this->run ) );

		$this->assertSame( 'not_installed', $e->error_class() );
		$this->assertSame( '', $e->result()['previous_version'] );
	}

	public function test_a_filesystem_wordpress_cannot_reach_deletes_nothing(): void {
		$this->filesystem = false;
		Functions\when( 'get_filesystem_method' )->justReturn( 'ftpext' );

		$e = $this->failure( fn() => Deletes::plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->run ) );

		$this->assertSame( 'filesystem', $e->error_class() );
		$this->assertStringContainsString( 'FTP credentials', $e->getMessage() );
		$this->assertDirectoryExists( WP_PLUGIN_DIR . '/hello-fixture' );
	}

	public function test_no_file_changes_allowed_deletes_nothing(): void {
		$this->file_mods = false;

		$this->assertSame( 'not_supported', $this->failure( fn() => Deletes::plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->run ) )->error_class() );
	}

	public function test_a_delete_that_leaves_the_files_fails_as_filesystem(): void {
		Functions\when( 'delete_plugins' )->justReturn( true );

		$e = $this->failure( fn() => Deletes::plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->run ) );

		$this->assertSame( 'filesystem', $e->error_class() );
		$this->assertSame( '1.2.0', $e->result()['new_version'] );
	}

	public function test_an_error_wordpress_reports_is_classed(): void {
		Functions\when( 'delete_plugins' )->justReturn( new \WP_Error( 'could_not_remove_plugin', 'Could not fully remove the plugin hello-fixture/hello-fixture.php.' ) );

		$this->assertSame( 'filesystem', $this->failure( fn() => Deletes::plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->run ) )->error_class() );
	}

	public function test_uninstall_code_that_throws_fails_as_fatal(): void {
		Functions\when( 'delete_plugins' )->alias(
			static function (): void {
				throw new \Error( 'Class "Hello_Uninstaller" not found' );
			}
		);

		$e = $this->failure( fn() => Deletes::plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->run ) );

		$this->assertSame( 'fatal', $e->error_class() );
		$this->assertStringContainsString( 'still there', $e->getMessage() );
	}

	public function test_an_unused_theme_is_deleted(): void {
		$result = Deletes::theme( [ 'stylesheet' => 'blocksy-child' ], static function (): void {}, $this->run );

		$this->assertDirectoryDoesNotExist( $this->tmp . '/themes/blocksy-child' );
		$this->assertSame( '1.0', $result['previous_version'] );
		$this->assertSame( 'blocksy-child', $result['stylesheet'] );
		$this->assertCount( 1, $result['activity_event_ids'] );
	}

	public function test_the_active_theme_and_its_parent_are_refused_as_theme_in_use(): void {
		$this->options = [
			'stylesheet' => 'blocksy-child',
			'template'   => 'blocksy',
		];

		$active = $this->failure( fn() => Deletes::theme( [ 'stylesheet' => 'blocksy-child' ], static function (): void {}, $this->run ) );
		$parent = $this->failure( fn() => Deletes::theme( [ 'stylesheet' => 'blocksy' ], static function (): void {}, $this->run ) );

		$this->assertSame( 'theme_in_use', $active->error_class() );
		$this->assertSame( 'theme_in_use', $parent->error_class() );
		$this->assertStringContainsString( 'parent', $parent->getMessage() );
		$this->assertDirectoryExists( $this->tmp . '/themes/blocksy' );
		$this->assertDirectoryExists( $this->tmp . '/themes/blocksy-child' );
	}

	public function test_a_theme_that_is_gone_fails_as_not_installed(): void {
		$this->assertSame( 'not_installed', $this->failure( fn() => Deletes::theme( [ 'stylesheet' => 'nope' ], static function (): void {}, $this->run ) )->error_class() );
	}

	public function test_a_stylesheet_that_climbs_out_of_the_themes_folder_is_refused(): void {
		$this->assertSame( 'other', $this->failure( fn() => Deletes::theme( [ 'stylesheet' => '..' ], static function (): void {}, $this->run ) )->error_class() );
		$this->assertSame( 'other', $this->failure( fn() => Deletes::theme( [ 'stylesheet' => 'a/../../b' ], static function (): void {}, $this->run ) )->error_class() );
	}
}
