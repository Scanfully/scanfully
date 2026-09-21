<?php
/**
 * "Run check now" integration test.
 *
 * Runs the manual email check through the real Action Scheduler (wp-env) and
 * skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Cron\Controller as CronController;

/**
 * Verifies a manual email check doesn't move the scheduled checks.
 */
final class EmailRunNowTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( \ActionScheduler::class ) || ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler not available. Run this suite under wp-env.' );
		}
		as_unschedule_all_actions( '', [], 'scanfully' );
	}

	protected function tearDown(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], 'scanfully' );
		}
		parent::tearDown();
	}

	public function test_a_manual_run_through_action_scheduler_does_not_reschedule_the_chain(): void {
		$hook      = CronController::ACTION_EMAIL_DELIVERABILITY_PING;
		$action_id = as_schedule_single_action( time(), $hook, [ 'source' => 'manual' ], 'scanfully' );

		\ActionScheduler::runner()->process_action( $action_id );

		$this->assertFalse(
			as_next_scheduled_action( $hook, [], 'scanfully' ),
			'A manual run must not schedule or move the regular check.'
		);
	}
}
