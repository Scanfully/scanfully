<?php
/**
 * Failed update event unit tests.
 *
 * @package Scanfully\Tests\Unit\Events
 */

namespace Scanfully\Tests\Unit\Events;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use ReflectionProperty;
use Scanfully\Events\Controller;
use Scanfully\Events\CoreUpdateFailed;
use Scanfully\Events\PluginUpdateFailed;
use Scanfully\Events\ThemeUpdateFailed;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\Controller::remember_update_item
 * @covers \Scanfully\Events\Controller::install_package_failed
 * @covers \Scanfully\Events\Controller::automatic_updates_failed
 * @covers \Scanfully\Events\UpdateFailedEvent
 * @covers \Scanfully\Events\PluginUpdateFailed
 * @covers \Scanfully\Events\ThemeUpdateFailed
 * @covers \Scanfully\Events\CoreUpdateFailed
 */
final class UpdateFailedTest extends TestCase {

	/**
	 * Payloads of the failure actions, by action.
	 *
	 * @var array<string, array<int, array<string, string>>>
	 */
	private array $reported = [];

	/**
	 * In-memory transient store.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	protected function setUp(): void {
		parent::setUp();
		$this->reported   = [];
		$this->transients = [];

		$reported = new ReflectionProperty( Controller::class, 'reported_failures' );
		$reported->setAccessible( true );
		$reported->setValue( null, [] );

		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		Functions\when( 'get_transient' )->alias( fn( string $key ) => array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);

		foreach ( [ 'plugin', 'theme', 'core' ] as $type ) {
			$action = 'scanfully_' . $type . '_update_failed';
			Actions\expectDone( $action )->zeroOrMoreTimes()->whenHappen(
				function ( $payload ) use ( $type ) {
					$this->reported[ $type ][] = $payload;
				}
			);
		}
	}

	/**
	 * A WP_Error double.
	 *
	 * @param string $message The error message.
	 * @param mixed  $data    The error data.
	 *
	 * @return \WP_Error
	 */
	private function error( string $message, $data = null ) {
		$error = Mockery::mock( 'WP_Error' );
		$error->shouldReceive( 'get_error_message' )->andReturn( $message );
		$error->shouldReceive( 'get_error_data' )->andReturn( $data );

		return $error;
	}

	public function test_a_plugin_update_that_fails_while_installing_is_reported(): void {
		$error = $this->error( 'The package could not be installed.' );

		$returned = Controller::install_package_failed(
			$error,
			[
				'plugin'                => 'akismet/akismet.php',
				'scanfully_old_name'    => 'Akismet',
				'scanfully_old_version' => '5.3',
				'scanfully_new_version' => '5.4',
			]
		);

		$this->assertSame( $error, $returned, 'The install result must be returned unchanged.' );
		$this->assertSame(
			[
				[
					'name'        => 'Akismet',
					'slug'        => 'akismet/akismet.php',
					'version'     => '5.3',
					'new_version' => '5.4',
					'error'       => 'The package could not be installed.',
				],
			],
			$this->reported['plugin']
		);
	}

	public function test_a_theme_update_failure_is_reported_as_a_theme(): void {
		Controller::install_package_failed( $this->error( 'Could not copy file.', '/wp-content/themes/twentytwentyfive/style.css' ), [ 'theme' => 'twentytwentyfive' ] );

		$this->assertSame( 'twentytwentyfive', $this->reported['theme'][0]['name'], 'Without a remembered name, the slug is used.' );
		$this->assertSame( 'Could not copy file. /wp-content/themes/twentytwentyfive/style.css', $this->reported['theme'][0]['error'] );
		$this->assertArrayNotHasKey( 'plugin', $this->reported );
	}

	public function test_scanfully_s_own_failed_update_is_reported(): void {
		Controller::install_package_failed( $this->error( 'Failed.' ), [ 'plugin' => 'scanfully/scanfully.php' ] );

		$this->assertCount( 1, $this->reported['plugin'] );
	}

	/**
	 * @dataProvider provide_ignored_install_results
	 *
	 * @param mixed $hook_extra Upgrader hook extras.
	 */
	public function test_successful_updates_and_installs_are_not_failures( $hook_extra ): void {
		Controller::install_package_failed( [ 'destination' => WP_PLUGIN_DIR ], [ 'plugin' => 'akismet/akismet.php' ] );
		Controller::install_package_failed( $this->error( 'Failed.' ), $hook_extra );

		$this->assertSame( [], $this->reported );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public function provide_ignored_install_results(): array {
		return [
			'a failed new plugin install' => [
				[
					'type'   => 'plugin',
					'action' => 'install',
				],
			],
			'no hook extras'              => [ null ],
		];
	}

	public function test_the_name_and_version_on_offer_are_remembered_before_the_update(): void {
		Functions\when( 'get_file_data' )->justReturn( [ 'Name' => 'Akismet' ] );
		Functions\when( 'get_site_transient' )->justReturn(
			(object) [
				'response' => [ 'akismet/akismet.php' => (object) [ 'new_version' => '5.4' ] ],
			]
		);

		$options = Controller::remember_update_item( [ 'hook_extra' => [ 'plugin' => 'akismet/akismet.php' ] ] );

		$this->assertSame( 'Akismet', $options['hook_extra']['scanfully_old_name'] );
		$this->assertSame( '5.4', $options['hook_extra']['scanfully_new_version'] );
	}

	/**
	 * An automatic update result as WordPress records it.
	 *
	 * @param object $item     The update offer.
	 * @param mixed  $result   The update result.
	 * @param string $name     The item name.
	 * @param array  $messages The upgrader messages.
	 *
	 * @return object
	 */
	private function auto_update( object $item, $result, string $name = '', array $messages = [] ): object {
		return (object) [
			'item'     => $item,
			'result'   => $result,
			'name'     => $name,
			'messages' => $messages,
		];
	}

	public function test_automatic_update_failures_before_the_install_are_reported_with_the_last_message(): void {
		Controller::automatic_updates_failed(
			[
				'plugin' => [
					$this->auto_update(
						(object) [
							'plugin'          => 'premium/premium.php',
							'current_version' => '1.0',
							'new_version'     => '2.0',
						],
						null,
						'Premium',
						[ 'Updating plugin: Premium', 'Downloading update from <span class="code">https://scanfully.com/premium.zip</span>&#8230;', 'Download failed. Unauthorized', '' ]
					),
					$this->auto_update( (object) [ 'plugin' => 'akismet/akismet.php' ], true, 'Akismet' ),
				],
			]
		);

		$this->assertSame(
			[
				[
					'name'        => 'Premium',
					'slug'        => 'premium/premium.php',
					'version'     => '1.0',
					'new_version' => '2.0',
					'error'       => 'Download failed. Unauthorized',
				],
			],
			$this->reported['plugin']
		);
	}

	public function test_an_automatic_update_failure_already_reported_while_installing_is_not_reported_twice(): void {
		$error = $this->error( 'The package could not be installed.' );
		Controller::install_package_failed( $error, [ 'theme' => 'twentytwentyfive' ] );

		Controller::automatic_updates_failed(
			[
				'theme' => [ $this->auto_update( (object) [ 'theme' => 'twentytwentyfive' ], $error, 'Twenty Twenty-Five' ) ],
			]
		);

		$this->assertCount( 1, $this->reported['theme'] );
	}

	public function test_a_failed_automatic_core_update_is_reported(): void {
		Controller::automatic_updates_failed(
			[
				'core'        => [
					$this->auto_update(
						(object) [
							'current' => '6.8.2',
							'version' => '6.8.2',
						],
						$this->error( 'The update cannot be installed because some files could not be copied.' ),
						'WordPress 6.8.2'
					),
				],
				'translation' => [ $this->auto_update( (object) [], null ) ],
			]
		);

		$this->assertSame(
			[
				[
					'version'     => '6.8.2',
					'old_version' => '6.8.1',
					'error'       => 'The update cannot be installed because some files could not be copied.',
				],
			],
			$this->reported['core']
		);
		$this->assertArrayNotHasKey( 'plugin', $this->reported );
	}

	public function test_a_successful_automatic_core_update_is_not_a_failure(): void {
		Controller::automatic_updates_failed(
			[
				'core' => [ $this->auto_update( (object) [ 'current' => '6.8.2' ], '6.8.2', 'WordPress 6.8.2' ) ],
			]
		);

		$this->assertSame( [], $this->reported );
	}

	/**
	 * @dataProvider provide_failure_events
	 *
	 * @param string $event_class Event class.
	 */
	public function test_the_same_failure_is_reported_once_a_day( string $event_class ): void {
		$event   = new $event_class();
		$payload = [
			'slug'        => 'akismet/akismet.php',
			'version'     => '5.3',
			'new_version' => '5.4',
			'error'       => 'Failed.',
		];

		$this->assertTrue( $event->should_fire( [ $payload ] ) );
		$this->assertFalse( $event->should_fire( [ $payload ] ), 'Again within a day.' );
		$this->assertTrue( $event->should_fire( [ array_merge( $payload, [ 'new_version' => '5.5' ] ) ] ), 'Another version on offer.' );

		$this->transients = [];
		$this->assertTrue( $event->should_fire( [ $payload ] ), 'After a day.' );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function provide_failure_events(): array {
		return [
			'plugin' => [ PluginUpdateFailed::class ],
			'theme'  => [ ThemeUpdateFailed::class ],
			'core'   => [ CoreUpdateFailed::class ],
		];
	}

	/**
	 * @dataProvider provide_failure_events
	 *
	 * @param string $event_class Event class.
	 */
	public function test_a_non_array_payload_is_ignored( string $event_class ): void {
		$event = new $event_class();

		$this->assertFalse( $event->should_fire( [ 'x' ] ) );
		$this->assertSame( [], $event->get_post_body( [ 'x' ] ) );
	}
}
