<?php
/**
 * Plugin and theme update event integration tests.
 *
 * Runs the real upgrader filters in WordPress (wp-env) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Verifies only successful updates are reported.
 */
final class UpdateEventTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'apply_filters' ) || ! class_exists( \WP_Error::class ) ) {
			$this->markTestSkipped( 'WordPress runtime not available. Run this suite under wp-env.' );
		}
	}

	/**
	 * Run the install-result filter and return how often the update action fired.
	 *
	 * @param mixed                $result     The install result.
	 * @param array<string, mixed> $hook_extra Upgrader hook extras.
	 * @param string               $action     The Scanfully update action.
	 *
	 * @return int
	 */
	private function updates_reported( $result, array $hook_extra, string $action ): int {
		$before   = did_action( $action );
		$returned = apply_filters( 'upgrader_install_package_result', $result, $hook_extra );

		$this->assertSame( $result, $returned, 'The filter must return the install result unchanged.' );

		return did_action( $action ) - $before;
	}

	/**
	 * @return array<string, array{array<string, string>, string}>
	 */
	public function provide_update_kinds(): array {
		return [
			'plugin' => [ [ 'plugin' => 'hello.php' ], 'scanfully_plugin_updated' ],
			'theme'  => [ [ 'theme' => 'twentytwentyfive' ], 'scanfully_theme_updated' ],
		];
	}

	/**
	 * @dataProvider provide_update_kinds
	 *
	 * @param array<string, string> $hook_extra Upgrader hook extras.
	 * @param string                $action     The Scanfully update action.
	 */
	public function test_a_failed_update_is_not_reported( array $hook_extra, string $action ): void {
		$failed = new \WP_Error( 'incompatible_php_required_version', 'The package requires a newer PHP version.' );

		$this->assertSame( 0, $this->updates_reported( $failed, $hook_extra, $action ) );
	}

	/**
	 * @dataProvider provide_update_kinds
	 *
	 * @param array<string, string> $hook_extra Upgrader hook extras.
	 * @param string                $action     The Scanfully update action.
	 */
	public function test_a_successful_update_is_reported( array $hook_extra, string $action ): void {
		$success = [ 'destination' => WP_PLUGIN_DIR ];

		$this->assertSame( 1, $this->updates_reported( $success, $hook_extra, $action ) );
	}

	/**
	 * Capture the payload of the next update action.
	 *
	 * @param string $action The Scanfully update action.
	 *
	 * @return \ArrayObject<string, mixed> Filled when the action fires.
	 */
	private function capture( string $action ): \ArrayObject {
		$payload = new \ArrayObject();
		add_action(
			$action,
			static function ( $data ) use ( $payload ) {
				$payload->exchangeArray( (array) $data );
			}
		);
		return $payload;
	}

	public function test_scanfully_s_own_update_is_not_reported_whatever_its_folder(): void {
		$own = [ 'plugin' => plugin_basename( SCANFULLY_PLUGIN_FILE ) ];

		$this->assertSame( 0, $this->updates_reported( [], $own, 'scanfully_plugin_updated' ) );
	}

	public function test_the_old_version_travels_under_a_prefixed_key(): void {
		$options = apply_filters(
			'upgrader_package_options',
			[ 'hook_extra' => [ 'plugin' => 'hello.php' ] ]
		);

		$this->assertArrayHasKey( 'scanfully_old_version', $options['hook_extra'] );
		$this->assertArrayNotHasKey( 'old_version', $options['hook_extra'], 'The generic key could collide with other plugins.' );

		$payload = $this->capture( 'scanfully_plugin_updated' );
		apply_filters( 'upgrader_install_package_result', [], $options['hook_extra'] );

		$this->assertSame( $options['hook_extra']['scanfully_old_version'], $payload['old_version'] );
	}

	public function test_a_theme_in_another_theme_directory_reports_its_version(): void {
		$root  = sys_get_temp_dir() . '/scanfully-theme-root-' . wp_generate_password( 6, false );
		$theme = 'scanfully-test-theme';
		wp_mkdir_p( $root . '/' . $theme );
		file_put_contents( $root . '/' . $theme . '/style.css', "/*\nTheme Name: Scanfully Test Theme\nVersion: 9.9.9\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $root . '/' . $theme . '/index.php', '<?php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		register_theme_directory( $root );
		search_theme_directories( true );

		$payload = $this->capture( 'scanfully_theme_updated' );
		apply_filters( 'upgrader_install_package_result', [], [ 'theme' => $theme ] );

		$this->assertSame( '9.9.9', $payload['version'] );

		unset( $GLOBALS['wp_theme_directories'][ array_search( $root, $GLOBALS['wp_theme_directories'], true ) ] );
		search_theme_directories( true );
	}
}
