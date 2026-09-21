<?php
/**
 * Plugin boot integration tests.
 *
 * Runs under a real WordPress runtime (wp-env). When the runtime is absent
 * (the default for the fast unit run), every test marks itself skipped so the
 * suite stays green locally.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Main;

/**
 * Verifies the plugin boots inside WordPress.
 */
final class PluginBootTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'did_action' ) || ! function_exists( 'wp_get_theme' ) ) {
			$this->markTestSkipped(
				'WordPress runtime not available. Run this suite under wp-env: '
				. '`npm run env:start` then `npm run test:integration`.'
			);
		}
	}

	public function test_plugin_constants_are_defined(): void {
		$this->assertTrue( defined( 'SCANFULLY_VERSION' ), 'SCANFULLY_VERSION was not defined.' );
		$this->assertTrue( defined( 'SCANFULLY_PLUGIN_FILE' ), 'SCANFULLY_PLUGIN_FILE was not defined.' );
	}

	public function test_scanfully_helper_returns_the_main_instance(): void {
		$this->assertTrue( function_exists( 'Scanfully' ), 'The Scanfully() helper is not defined.' );
		$this->assertSame( Main::get(), \Scanfully() );
	}

	public function test_action_scheduler_is_loaded(): void {
		$this->assertTrue( function_exists( 'as_schedule_single_action' ), 'Action Scheduler is not loaded.' );
	}
}
