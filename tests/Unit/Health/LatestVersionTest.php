<?php
/**
 * Plugin list unit tests: the latest version from WordPress's update check.
 *
 * @package Scanfully\Tests\Unit\Health
 */

namespace Scanfully\Tests\Unit\Health;

use Brain\Monkey\Functions;
use ReflectionMethod;
use Scanfully\Health\Controller;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Health\Controller
 */
final class LatestVersionTest extends TestCase {

	/**
	 * The plugins as reported, by slug.
	 *
	 * @param mixed $updates The update_plugins site transient.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function plugins( $updates ): array {
		Functions\when( 'get_plugins' )->justReturn(
			[
				'akismet/akismet.php'      => $this->header( 'Akismet', '5.3' ),
				'premium/premium.php'      => $this->header( 'Premium', '2.0' ),
				'hello.php'                => $this->header( 'Hello Dolly', '1.7.2' ),
				'custom-plugin/custom.php' => $this->header( 'Custom', '1.0' ),
			]
		);
		Functions\when( 'get_site_transient' )->justReturn( $updates );
		Functions\when( 'plugin_basename' )->returnArg();
		Functions\when( 'is_plugin_active' )->justReturn( true );

		$method = new ReflectionMethod( Controller::class, 'get_plugins' );
		$method->setAccessible( true );

		return array_column( $method->invoke( null ), null, 'slug' );
	}

	/**
	 * A plugin header as get_plugins() returns it.
	 *
	 * @param string $name    Name.
	 * @param string $version Version.
	 *
	 * @return array<string, string>
	 */
	private function header( string $name, string $version ): array {
		return [
			'Name'        => $name,
			'PluginURI'   => '',
			'Version'     => $version,
			'Description' => '',
			'Author'      => '',
			'AuthorURI'   => '',
		];
	}

	public function test_the_latest_version_comes_from_the_update_check(): void {
		$updates = (object) [
			'response'  => [
				'akismet/akismet.php' => (object) [ 'new_version' => '5.7.2' ],
				// A third-party updater that adds an array.
				'premium/premium.php' => [ 'new_version' => '2.1' ],
			],
			'no_update' => [
				'hello.php' => (object) [ 'new_version' => '1.7.2' ],
			],
		];

		$plugins = $this->plugins( $updates );

		$this->assertSame( '5.7.2', $plugins['akismet']['latest_version'] );
		$this->assertSame( '2.1', $plugins['premium']['latest_version'] );
		$this->assertSame( '1.7.2', $plugins['hello']['latest_version'], 'An up-to-date plugin reports its own version as the latest.' );
		$this->assertNull( $plugins['custom-plugin']['latest_version'], 'A plugin the update check does not know has no latest version.' );
	}

	public function test_without_an_update_check_no_latest_version_is_reported(): void {
		foreach ( $this->plugins( false ) as $plugin ) {
			$this->assertNull( $plugin['latest_version'] );
		}
	}

	public function test_an_empty_or_odd_new_version_is_ignored(): void {
		$updates = (object) [
			'response' => [
				'akismet/akismet.php' => (object) [ 'new_version' => '' ],
				'premium/premium.php' => (object) [ 'new_version' => [ '2.1' ] ],
				'hello.php'           => 'not an object',
			],
		];

		foreach ( $this->plugins( $updates ) as $plugin ) {
			$this->assertNull( $plugin['latest_version'] );
		}
	}
}
