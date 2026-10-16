<?php
/**
 * Plugin and theme install, delete and switch event unit tests.
 *
 * @package Scanfully\Tests\Unit\Events
 */

namespace Scanfully\Tests\Unit\Events;

use Brain\Monkey\Functions;
use Mockery;
use ReflectionProperty;
use Scanfully\Events\PluginDeleted;
use Scanfully\Events\PluginInstalled;
use Scanfully\Events\ThemeDeleted;
use Scanfully\Events\ThemeInstalled;
use Scanfully\Events\ThemeSwitched;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\PluginInstalled
 * @covers \Scanfully\Events\ThemeInstalled
 * @covers \Scanfully\Events\PluginDeleted
 * @covers \Scanfully\Events\ThemeDeleted
 * @covers \Scanfully\Events\ThemeSwitched
 */
final class PluginThemeChangeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		foreach ( [ PluginDeleted::class, ThemeDeleted::class ] as $class ) {
			$pending = new ReflectionProperty( $class, 'pending' );
			$pending->setAccessible( true );
			$pending->setValue( null, [] );
		}
	}

	/**
	 * A WP_Theme double.
	 *
	 * @param string $stylesheet The theme directory.
	 * @param string $name       The theme name.
	 * @param string $version    The theme version.
	 * @param bool   $exists     Whether the theme exists.
	 *
	 * @return \WP_Theme
	 */
	private function theme( string $stylesheet, string $name, string $version = '1.0', bool $exists = true ) {
		$theme = Mockery::mock( 'WP_Theme' );
		$theme->shouldReceive( 'get_stylesheet' )->andReturn( $stylesheet );
		$theme->shouldReceive( 'exists' )->andReturn( $exists );
		$theme->shouldReceive( 'get' )->andReturnUsing(
			static function ( string $header ) use ( $name, $version ) {
				return [
					'Name'    => $name,
					'Version' => $version,
					'Author'  => 'the WordPress team',
				][ $header ] ?? false;
			}
		);

		return $theme;
	}

	/**
	 * An upgrader double after an install.
	 *
	 * @param mixed $result  The install result.
	 * @param mixed $info    What plugin_info() or theme_info() returns.
	 *
	 * @return object
	 */
	private function upgrader( $result, $info ): object {
		return new class( $result, $info ) {
			/**
			 * The install result.
			 *
			 * @var mixed
			 */
			public $result;

			/**
			 * The installed item.
			 *
			 * @var mixed
			 */
			private $info;

			/**
			 * Constructor.
			 *
			 * @param mixed $result The install result.
			 * @param mixed $info   The installed item.
			 */
			public function __construct( $result, $info ) {
				$this->result = $result;
				$this->info   = $info;
			}

			/**
			 * The installed plugin.
			 *
			 * @return mixed
			 */
			public function plugin_info() {
				return $this->info;
			}

			/**
			 * The installed theme.
			 *
			 * @return mixed
			 */
			public function theme_info() {
				return $this->info;
			}
		};
	}

	public function test_an_installed_plugin_is_reported_with_its_details(): void {
		Functions\when( 'get_file_data' )->justReturn(
			[
				'Name'    => 'Akismet Anti-spam',
				'Version' => '5.4',
				'Author'  => 'Automattic',
			]
		);
		$data = [
			$this->upgrader( [ 'destination_name' => 'akismet' ], 'akismet/akismet.php' ),
			[
				'type'   => 'plugin',
				'action' => 'install',
			],
		];

		$event = new PluginInstalled();

		$this->assertTrue( $event->should_fire( $data ) );
		$this->assertSame(
			[
				'name'    => 'Akismet Anti-spam',
				'slug'    => 'akismet/akismet.php',
				'version' => '5.4',
				'author'  => 'Automattic',
			],
			$event->get_post_body( $data )
		);
	}

	/**
	 * @dataProvider provide_no_plugin_install
	 *
	 * @param mixed $result     The upgrader result.
	 * @param mixed $hook_extra The hook extras.
	 */
	public function test_other_upgrader_runs_are_not_plugin_installs( $result, $hook_extra ): void {
		$this->assertFalse( ( new PluginInstalled() )->should_fire( [ $this->upgrader( $result, 'akismet/akismet.php' ), $hook_extra ] ) );
	}

	/**
	 * @return array<string, array{mixed, mixed}>
	 */
	public function provide_no_plugin_install(): array {
		$install = [
			'type'   => 'plugin',
			'action' => 'install',
		];

		return [
			'failed install'     => [ null, $install ],
			'update'             => [
				[ 'destination_name' => 'akismet' ],
				[
					'type'    => 'plugin',
					'action'  => 'update',
					'plugins' => [ 'akismet/akismet.php' ],
				],
			],
			'theme install'      => [
				[ 'destination_name' => 'akismet' ],
				[
					'type'   => 'theme',
					'action' => 'install',
				],
			],
			'translation update' => [
				[ 'destination_name' => 'nl_NL' ],
				[
					'type'   => 'translation',
					'action' => 'update',
				],
			],
		];
	}

	public function test_an_installed_theme_is_reported_with_its_details(): void {
		$data  = [
			$this->upgrader( [ 'destination_name' => 'twentytwentyfive' ], $this->theme( 'twentytwentyfive', 'Twenty Twenty-Five', '1.2' ) ),
			[
				'type'   => 'theme',
				'action' => 'install',
			],
		];
		$event = new ThemeInstalled();

		$this->assertTrue( $event->should_fire( $data ) );
		$this->assertSame(
			[
				'name'    => 'Twenty Twenty-Five',
				'slug'    => 'twentytwentyfive',
				'version' => '1.2',
				'author'  => 'the WordPress team',
			],
			$event->get_post_body( $data )
		);
	}

	public function test_a_failed_theme_install_is_not_reported(): void {
		$data = [
			$this->upgrader( new \stdClass(), $this->theme( 'broken', 'Broken' ) ),
			[
				'type'   => 'theme',
				'action' => 'install',
			],
		];

		$this->assertFalse( ( new ThemeInstalled() )->should_fire( $data ) );
	}

	public function test_a_deleted_plugin_is_reported_with_the_details_read_before_deletion(): void {
		Functions\when( 'get_file_data' )->justReturn(
			[
				'Name'    => 'Hello Dolly',
				'Version' => '1.7.2',
			]
		);
		$event = new PluginDeleted();

		PluginDeleted::remember( 'hello.php' );

		$this->assertTrue( $event->should_fire( [ 'hello.php', true ] ) );
		$this->assertSame(
			[
				'name'    => 'Hello Dolly',
				'slug'    => 'hello.php',
				'version' => '1.7.2',
			],
			$event->get_post_body( [ 'hello.php', true ] )
		);
	}

	public function test_a_plugin_whose_files_could_not_be_deleted_is_not_reported(): void {
		$this->assertFalse( ( new PluginDeleted() )->should_fire( [ 'hello.php', false ] ) );
	}

	public function test_a_deleted_theme_is_reported_with_the_details_read_before_deletion(): void {
		Functions\when( 'wp_get_theme' )->justReturn( $this->theme( 'twentytwentythree', 'Twenty Twenty-Three', '1.6' ) );
		$event = new ThemeDeleted();

		ThemeDeleted::remember( 'twentytwentythree' );

		$this->assertTrue( $event->should_fire( [ 'twentytwentythree', true ] ) );
		$this->assertFalse( $event->should_fire( [ 'twentytwentythree', false ] ) );
		$this->assertSame(
			[
				'name'    => 'Twenty Twenty-Three',
				'slug'    => 'twentytwentythree',
				'version' => '1.6',
			],
			$event->get_post_body( [ 'twentytwentythree', true ] )
		);
	}

	public function test_a_theme_switch_has_the_new_and_old_theme(): void {
		$data  = [
			'Twenty Twenty-Five',
			$this->theme( 'twentytwentyfive', 'Twenty Twenty-Five' ),
			$this->theme( 'twentytwentyfour', 'Twenty Twenty-Four' ),
		];
		$event = new ThemeSwitched();

		$this->assertTrue( $event->should_fire( $data ) );
		$this->assertSame(
			[
				'name'     => 'Twenty Twenty-Five',
				'slug'     => 'twentytwentyfive',
				'old_name' => 'Twenty Twenty-Four',
				'old_slug' => 'twentytwentyfour',
			],
			$event->get_post_body( $data )
		);
	}

	public function test_switching_to_the_same_theme_is_not_reported(): void {
		$theme = $this->theme( 'twentytwentyfive', 'Twenty Twenty-Five' );

		$this->assertFalse( ( new ThemeSwitched() )->should_fire( [ 'Twenty Twenty-Five', $theme, $theme ] ) );
		$this->assertFalse( ( new ThemeSwitched() )->should_fire( [ 'Twenty Twenty-Five', null, $theme ] ) );
	}
}
