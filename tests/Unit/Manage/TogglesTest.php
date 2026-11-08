<?php
/**
 * Manage activate, deactivate and theme switch unit tests, with WordPress's
 * functions faked.
 *
 * @package Scanfully\Tests\Unit\Manage
 */

namespace Scanfully\Tests\Unit\Manage;

use Brain\Monkey\Functions;
use Scanfully\Manage\Runner;
use Scanfully\Manage\Toggles;

/**
 * @covers \Scanfully\Manage\Toggles
 * @covers \Scanfully\Manage\Action
 */
final class TogglesTest extends SiteTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->write_plugin( 'hello-fixture', '1.2.0' );
		$this->write_plugin( 'scanfully', '2.0.0' );
		$this->active['scanfully/scanfully.php'] = true;
		$this->write_theme( 'blocksy', '2.1' );
	}

	public function test_a_plugin_is_switched_on(): void {
		$result = Toggles::activate_plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->run );

		$this->assertTrue( $this->active['hello-fixture/hello-fixture.php'] );
		$this->assertTrue( $result['active'] );
		$this->assertSame( 'hello-fixture', $result['slug'] );
		$this->assertSame( '1.2.0', $result['previous_version'] );
		$this->assertSame( '1.2.0', $result['new_version'] );
		$this->assertCount( 1, $result['activity_event_ids'], 'the activation event carries the job id' );
	}

	public function test_a_plugin_that_is_on_already_is_nothing_to_do(): void {
		$this->active['hello-fixture/hello-fixture.php'] = true;
		$this->activate = function (): void {
			$this->fail( 'activate_plugin() ran.' );
		};

		$result = Toggles::activate_plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->run );

		$this->assertTrue( $result['active'] );
		$this->assertSame( [ 'The plugin is switched on already: nothing to do.' ], $result['log'] );
	}

	public function test_a_plugin_that_fatals_on_activation_fails_as_fatal_and_stays_off(): void {
		$this->activate = static function (): void {
			// activate_plugin() opens a buffer and includes the plugin.
			ob_start();
			echo 'half a page';
			throw new \Error( 'Call to undefined function hello_missing() in ' . WP_PLUGIN_DIR . '/hello-fixture/hello-fixture.php:12' );
		};
		$level = ob_get_level();

		$e = $this->failure( fn() => Toggles::activate_plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->run ) );

		$this->assertSame( $level, ob_get_level(), 'the plugin\'s output buffer is closed' );
		$this->assertSame( 'fatal', $e->error_class() );
		$this->assertStringContainsString( 'Call to undefined function hello_missing()', $e->getMessage() );
		$this->assertStringContainsString( 'The plugin stays switched off.', $e->getMessage() );
		$this->assertFalse( $e->result()['active'] );
		$this->assertArrayNotHasKey( 'hello-fixture/hello-fixture.php', $this->active );
	}

	public function test_a_sandboxed_error_on_activation_fails_as_fatal_and_switches_the_plugin_off_again(): void {
		$this->activate = function ( string $file ) {
			// WordPress keeps a plugin that printed output switched on.
			$this->active[ $file ] = true;
			return new \WP_Error( 'unexpected_output', 'The plugin generated unexpected output.', '<b>Warning</b>: something in ' . WP_PLUGIN_DIR . '/hello-fixture/x.php' );
		};

		$e = $this->failure( fn() => Toggles::activate_plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->run ) );

		$this->assertSame( 'fatal', $e->error_class() );
		$this->assertStringContainsString( 'The plugin generated unexpected output. Warning: something in ./wp-content/plugins/hello-fixture/x.php', $e->getMessage(), 'sanitized: no tags, no server paths' );
		$this->assertStringContainsString( 'Scanfully switched it off again.', $e->getMessage() );
		$this->assertArrayNotHasKey( 'hello-fixture/hello-fixture.php', $this->active );
		$this->assertFalse( $e->result()['active'] );
		$this->assertSame( [ false ], $this->silent, 'the deactivation is logged, not silent' );
		$this->assertCount( 1, $e->result()['activity_event_ids'], 'and tagged with the job' );
	}

	public function test_the_runner_reports_an_activation_fatal_cleaned(): void {
		$this->activate = static function (): void {
			ob_start();
			throw new \Error( '<b>Call to undefined function nope()</b> in ' . WP_PLUGIN_DIR . '/hello-fixture/hello-fixture.php:7 https://x.test/a/b?key=SECRET' );
		};

		$result = Runner::run(
			[
				'id'     => self::JOB,
				'action' => 'activate_plugin',
				'params' => [ 'slug' => 'hello-fixture' ],
			],
			static fn(): bool => true,
			null,
			null,
			$this->run
		);

		$this->assertSame( 'failed', $result['state'] );
		$this->assertSame( 'fatal', $result['error']['class'] );
		$this->assertSame( 'The plugin stopped with a PHP error while WordPress switched it on: Call to undefined function nope() in ./wp-content/plugins/hello-fixture/hello-fixture.php:7 https://x.test/…/b The plugin stays switched off.', $result['error']['message'] );
		$this->assertFalse( $result['result']->active );
	}

	/**
	 * @dataProvider provide_own_plugin_folders
	 *
	 * @param string $own   The Scanfully plugin's file, as plugin_basename() names it.
	 * @param string $other The folder of another plugin the job names.
	 */
	public function test_the_scanfully_plugin_is_known_by_its_file_not_its_folder_name( string $own, string $other ): void {
		Functions\when( 'plugin_basename' )->justReturn( $own );
		$this->write_plugin( 'scanfully-plugin', '2.0.0' );
		$this->active['scanfully-plugin/scanfully-plugin.php'] = true;

		$this->assertSame( 'not_supported', $this->failure( fn() => Toggles::deactivate_plugin( [ 'slug' => dirname( $own ) ], static function (): void {}, $this->run ) )->error_class() );
		$this->assertFalse( Toggles::deactivate_plugin( [ 'slug' => $other ], static function (): void {}, $this->run )['active'], 'another plugin of a similar name is switched off' );
	}

	/**
	 * The Scanfully plugin's file and another plugin's folder.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provide_own_plugin_folders(): array {
		return [
			'Scanfully in scanfully-plugin/' => [ 'scanfully-plugin/scanfully-plugin.php', 'scanfully' ],
			'Scanfully in scanfully/'        => [ 'scanfully/scanfully.php', 'scanfully-plugin' ],
		];
	}

	public function test_a_plugin_whose_requirements_the_site_does_not_meet_is_not_supported(): void {
		$this->activate = static fn() => new \WP_Error( 'plugin_php_incompatible', 'Current PHP version does not meet minimum requirements for Hello Fixture.' );

		$this->assertSame( 'not_supported', $this->failure( fn() => Toggles::activate_plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->run ) )->error_class() );
	}

	public function test_a_plugin_that_is_not_installed_fails_as_not_installed(): void {
		$this->assertSame( 'not_installed', $this->failure( fn() => Toggles::activate_plugin( [ 'slug' => 'nope' ], static function (): void {}, $this->run ) )->error_class() );
		$this->assertSame( 'not_installed', $this->failure( fn() => Toggles::deactivate_plugin( [ 'slug' => 'nope' ], static function (): void {}, $this->run ) )->error_class() );
	}

	public function test_a_plugin_is_switched_off(): void {
		$this->active['hello-fixture/hello-fixture.php'] = true;

		$result = Toggles::deactivate_plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->run );

		$this->assertFalse( $result['active'] );
		$this->assertArrayNotHasKey( 'hello-fixture/hello-fixture.php', $this->active );
		$this->assertCount( 1, $result['activity_event_ids'] );
	}

	public function test_a_plugin_that_is_off_already_is_nothing_to_do(): void {
		$result = Toggles::deactivate_plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->run );

		$this->assertFalse( $result['active'] );
		$this->assertSame( [], $result['activity_event_ids'] );
	}

	public function test_the_scanfully_plugin_is_never_switched_off(): void {
		$e = $this->failure( fn() => Toggles::deactivate_plugin( [ 'slug' => 'scanfully' ], static function (): void {}, $this->run ) );

		$this->assertSame( 'not_supported', $e->error_class() );
		$this->assertTrue( $this->active['scanfully/scanfully.php'] );
	}

	public function test_a_deactivation_hook_that_throws_fails_as_fatal(): void {
		$this->active['hello-fixture/hello-fixture.php'] = true;
		Functions\when( 'deactivate_plugins' )->alias(
			static function (): void {
				throw new \RuntimeException( 'Uninstall table missing.' );
			}
		);

		$e = $this->failure( fn() => Toggles::deactivate_plugin( [ 'slug' => 'hello-fixture' ], static function (): void {}, $this->run ) );

		$this->assertSame( 'fatal', $e->error_class() );
		$this->assertStringContainsString( 'It is still switched on.', $e->getMessage() );
		$this->assertTrue( $e->result()['active'] );
	}

	public function test_the_site_is_switched_to_another_theme(): void {
		$result = Toggles::switch_theme( [ 'stylesheet' => 'blocksy' ], static function (): void {}, $this->run );

		$this->assertSame( 'blocksy', $this->options['stylesheet'] );
		$this->assertSame( 'blocksy', $result['stylesheet'] );
		$this->assertSame( 'twentyfive', $result['previous_theme'] );
		$this->assertSame( '2.1', $result['new_version'] );
		$this->assertCount( 1, $result['activity_event_ids'], 'the switch event carries the job id' );
	}

	public function test_switching_to_the_active_theme_is_nothing_to_do(): void {
		$result = Toggles::switch_theme( [ 'stylesheet' => 'twentyfive' ], static function (): void {}, $this->run );

		$this->assertSame( [], $result['activity_event_ids'] );
	}

	public function test_a_theme_that_is_not_installed_or_broken_is_not_switched_to(): void {
		$this->assertSame( 'not_installed', $this->failure( fn() => Toggles::switch_theme( [ 'stylesheet' => 'nope' ], static function (): void {}, $this->run ) )->error_class() );

		$this->theme_errors['blocksy'] = new \WP_Error( 'theme_no_parent', 'The parent theme is missing. Please install the "blocksy-base" parent theme.' );
		$e                             = $this->failure( fn() => Toggles::switch_theme( [ 'stylesheet' => 'blocksy' ], static function (): void {}, $this->run ) );
		$this->assertSame( 'not_installed', $e->error_class(), 'a child theme without its parent' );
		$this->assertSame( 'twentyfive', $this->options['stylesheet'] );
	}

	public function test_a_theme_whose_requirements_the_site_does_not_meet_is_not_supported(): void {
		Functions\when( 'validate_theme_requirements' )->justReturn( new \WP_Error( 'theme_php_incompatible', 'Requires PHP 8.2.' ) );

		$this->assertSame( 'not_supported', $this->failure( fn() => Toggles::switch_theme( [ 'stylesheet' => 'blocksy' ], static function (): void {}, $this->run ) )->error_class() );
		$this->assertSame( 'twentyfive', $this->options['stylesheet'] );
	}

	public function test_on_a_network_every_m2_action_is_refused(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		foreach ( [ 'install_plugin', 'install_theme', 'install_plugin_upload', 'install_theme_upload', 'activate_plugin', 'deactivate_plugin', 'switch_theme', 'delete_plugin', 'delete_theme' ] as $action ) {
			$result = Runner::run(
				[
					'id'     => self::JOB,
					'action' => $action,
					'params' => [ 'slug' => 'hello-fixture' ],
				],
				static fn(): bool => true,
				null,
				null,
				$this->run
			);
			$this->assertSame( 'multisite_refused', $result['error']['class'], $action );
		}
		$this->assertArrayNotHasKey( 'hello-fixture/hello-fixture.php', $this->active );
	}

	public function test_the_runner_switches_a_plugin_on_without_file_changes_allowed(): void {
		$this->file_mods = false;

		$result = Runner::run(
			[
				'id'     => self::JOB,
				'action' => 'activate_plugin',
				'params' => [ 'slug' => 'hello-fixture' ],
			],
			static fn(): bool => true,
			null,
			null,
			$this->run
		);

		$this->assertSame( 'succeeded', $result['state'] );
		$this->assertTrue( $result['result']->active );
	}
}
