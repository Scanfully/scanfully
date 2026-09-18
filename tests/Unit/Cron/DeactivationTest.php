<?php
/**
 * Deactivation cleanup unit tests.
 *
 * @package Scanfully\Tests\Unit\Cron
 */

namespace Scanfully\Tests\Unit\Cron;

use Brain\Monkey\Functions;
use Scanfully\Cron\Controller;
use Scanfully\Events\DeactivatedPlugin;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Cron\Controller::clear_scheduled_events
 * @covers \Scanfully\Cron\Controller::schedule_health_sync
 * @covers \Scanfully\Events\DeactivatedPlugin::should_fire
 * @covers \Scanfully\Main::is_own_plugin
 */
final class DeactivationTest extends TestCase {

	private const OWN = 'scanfully/scanfully.php';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'plugin_basename' )->justReturn( self::OWN );
	}

	public function test_deactivation_cancels_every_job_in_the_scanfully_group(): void {
		Functions\expect( 'as_unschedule_all_actions' )->once()->with( '', [], 'scanfully' );

		Controller::clear_scheduled_events();
	}

	public function test_no_health_sync_is_scheduled_for_scanfully_itself(): void {
		Functions\expect( 'as_unschedule_all_actions' )->never();
		Functions\expect( 'as_schedule_single_action' )->never();

		Controller::schedule_health_sync( self::OWN );
	}

	public function test_a_health_sync_is_scheduled_for_other_plugins(): void {
		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
		Functions\expect( 'as_schedule_single_action' )->once();

		Controller::schedule_health_sync( 'akismet/akismet.php' );
	}

	public function test_a_health_sync_is_scheduled_without_a_plugin_argument(): void {
		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
		Functions\expect( 'as_schedule_single_action' )->once();

		Controller::schedule_health_sync();
	}

	public function test_scanfully_s_own_deactivation_is_not_reported(): void {
		$event = new DeactivatedPlugin();

		$this->assertFalse( $event->should_fire( [ self::OWN ] ) );
		$this->assertTrue( $event->should_fire( [ 'akismet/akismet.php' ] ) );
	}
}
