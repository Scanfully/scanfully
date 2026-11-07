<?php
/**
 * PHP error monitor unit tests.
 *
 * @package Scanfully\Tests\Unit\Errors
 */

namespace Scanfully\Tests\Unit\Errors;

use Brain\Monkey\Functions;
use Scanfully\Errors\Attribution;
use Scanfully\Errors\Monitor;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Errors\Monitor
 */
final class MonitorTest extends TestCase {

	/**
	 * Options by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monitor::reset();
		$this->options = [
			'scanfully_connect_is_connected' => 'yes',
			'active_plugins'                 => [ 'scanfully/scanfully.php' ],
		];
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'wp_load_alloptions' )->alias( fn() => $this->options );
		Functions\when( 'is_multisite' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monitor::reset();
		parent::tearDown();
	}

	public function test_it_runs_only_when_connected_and_switched_on(): void {
		$this->assertTrue( Monitor::should_run() );

		$this->options['scanfully_connect_php_errors_enabled'] = 'no';
		$this->assertFalse( Monitor::should_run() );

		$this->options['scanfully_connect_php_errors_enabled'] = 'yes';
		$this->options['scanfully_connect_is_connected']       = 'no';
		$this->assertFalse( Monitor::should_run() );
	}

	public function test_the_filter_turns_it_off(): void {
		\Brain\Monkey\Filters\expectApplied( 'scanfully_php_errors_enabled' )->andReturn( false );

		$this->assertFalse( Monitor::should_run() );
	}

	public function test_the_must_use_plugin_runs_only_while_the_plugin_is_active(): void {
		$this->assertTrue( Monitor::should_run( 'scanfully/scanfully.php' ) );
		$this->assertFalse( Monitor::should_run( 'scanfully-dev/scanfully.php' ) );
	}

	public function test_a_warning_is_kept_and_passed_on_to_the_handler_that_was_there_before(): void {
		Monitor::boot( 'plugin' );
		$this->assertSame( 'plugin', Monitor::origin() );
		// PHPUnit's own handler was there first.
		$this->assertTrue( Monitor::takes_all_types() );

		$passed_on = false;
		try {
			trigger_error( 'Something is off', E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- The warning under test.
		} catch ( \Throwable $e ) {
			// PHPUnit's handler turns the warning into an exception.
			$passed_on = false !== strpos( $e->getMessage(), 'Something is off' );
		}

		$this->assertTrue( $passed_on, 'The warning reached the previous handler.' );
		$recorded = array_values( Monitor::recorded() );
		$this->assertCount( 1, $recorded );
		$this->assertSame( E_USER_WARNING, $recorded[0]['type'] );
		$this->assertSame( 'Something is off', $recorded[0]['message'] );
		$this->assertSame( __FILE__, $recorded[0]['file'] );
		$this->assertSame( __FUNCTION__, $recorded[0]['trace'][0]['function'] );
	}

	public function test_it_boots_once(): void {
		Monitor::boot( 'mu', 'scanfully/scanfully.php' );
		Monitor::boot( 'plugin' );

		$this->assertSame( 'mu', Monitor::origin() );
	}

	public function test_it_does_not_boot_when_switched_off(): void {
		$this->options['scanfully_connect_php_errors_enabled'] = 'no';
		Monitor::boot( 'plugin' );

		$this->assertSame( '', Monitor::origin() );
	}

	public function test_without_a_previous_handler_it_returns_false_so_php_handles_the_error(): void {
		$this->assertFalse( Monitor::handle( E_WARNING, 'Undefined variable $x', '/srv/site/a.php', 3 ) );
		$this->assertCount( 1, Monitor::recorded() );
	}

	public function test_silenced_errors_notices_and_deprecations_are_skipped(): void {
		$reporting = error_reporting( E_ALL & ~E_WARNING );
		Monitor::handle( E_WARNING, 'Silenced', '/srv/site/a.php', 1 );
		error_reporting( $reporting );

		Monitor::handle( E_NOTICE, 'A notice', '/srv/site/a.php', 2 );
		Monitor::handle( E_DEPRECATED, 'Deprecated', '/srv/site/a.php', 3 );
		Monitor::handle( E_USER_DEPRECATED, 'Deprecated', '/srv/site/a.php', 4 );

		$this->assertSame( [], Monitor::recorded() );
	}

	public function test_the_same_warning_is_counted_and_distinct_ones_are_capped(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			Monitor::handle( E_WARNING, 'Again ' . $i, '/srv/site/a.php', 1 );
		}
		for ( $line = 100; $line < 100 + Monitor::MAX_PER_REQUEST + 5; $line++ ) {
			Monitor::handle( E_WARNING, 'Other', '/srv/site/a.php', $line );
		}

		$recorded = Monitor::recorded();
		$this->assertCount( Monitor::MAX_PER_REQUEST, $recorded );
		$this->assertSame( 5, $recorded[ E_WARNING . ':/srv/site/a.php:1' ]['count'] );
		$this->assertSame( 'Again 0', $recorded[ E_WARNING . ':/srv/site/a.php:1' ]['message'] );
	}

	public function test_the_fatal_error_is_kept_past_the_cap(): void {
		for ( $line = 1; $line <= Monitor::MAX_PER_REQUEST; $line++ ) {
			Monitor::handle( E_WARNING, 'Noise', '/srv/site/a.php', $line );
		}
		Monitor::record( E_ERROR, 'Allowed memory size exhausted', '/srv/site/b.php', 9 );

		$this->assertArrayHasKey( E_ERROR . ':/srv/site/b.php:9', Monitor::recorded() );
	}

	public function test_errors_on_two_lines_with_the_same_fingerprint_are_added_up(): void {
		Monitor::handle( E_WARNING, 'Undefined array key "a"', '/srv/site/wp-content/plugins/shop/a.php', 10 );
		Monitor::handle( E_WARNING, 'Undefined array key "b"', '/srv/site/wp-content/plugins/shop/a.php', 12 );
		Monitor::handle( E_WARNING, 'Undefined array key "b"', '/srv/site/wp-content/plugins/shop/a.php', 12 );

		$built = Monitor::build(
			new Attribution(
				[
					'plugins' => '/srv/site/wp-content/plugins',
					'abspath' => '/srv/site',
				]
			),
			[
				'request' => 'front',
				'path' => '/',
				'php' => '8.2',
				'wp' => '6.8',
				'maintenance' => false,
			]
		);

		$this->assertCount( 1, $built );
		$error = array_values( $built )[0];
		$this->assertSame( 3, $error['count'] );
		$this->assertSame( 'plugin', $error['source']['kind'] );
		$this->assertSame( 'Undefined array key "a"', $error['message'] );
	}

	public function test_a_long_message_is_cut_in_memory(): void {
		Monitor::handle( E_WARNING, str_repeat( 'x', 10000 ), '/srv/site/a.php', 1 );

		$this->assertSame( 4000, strlen( array_values( Monitor::recorded() )[0]['message'] ) );
	}
}
