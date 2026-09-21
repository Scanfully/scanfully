<?php
/**
 * Recurring job scheduling unit tests.
 *
 * @package Scanfully\Tests\Unit\Cron
 */

namespace Scanfully\Tests\Unit\Cron;

use Brain\Monkey\Functions;
use Scanfully\Cron\Controller;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Cron\Controller::schedule_events
 */
final class ScheduleEventsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		// Legacy hook migration already done.
		Functions\when( 'get_option' )->justReturn( '1' );
	}

	public function test_front_end_requests_do_not_check_the_schedule(): void {
		Functions\expect( 'as_has_scheduled_action' )->never();
		Functions\expect( 'get_option' )->never();

		Controller::schedule_events();
	}

	/**
	 * @dataProvider provide_contexts
	 *
	 * @param string $context Request context that checks the schedule.
	 */
	public function test_missing_jobs_are_scheduled_as_unique( string $context ): void {
		Functions\when( $context )->justReturn( true );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );

		$unique_flags = [];
		Functions\when( 'as_schedule_recurring_action' )->alias(
			static function ( $timestamp, $interval, $hook, $args, $group, $unique = false ) use ( &$unique_flags ) {
				$unique_flags[ $hook ] = $unique;
				return 1;
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			static function ( $timestamp, $hook, $args, $group, $unique = false ) use ( &$unique_flags ) {
				$unique_flags[ $hook ] = $unique;
				return 1;
			}
		);

		Controller::schedule_events();

		$this->assertCount( 5, $unique_flags );
		$this->assertSame( [ true ], array_values( array_unique( $unique_flags ) ), 'Every job must be scheduled as unique.' );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function provide_contexts(): array {
		return [
			'wp-admin' => [ 'is_admin' ],
			'cron'     => [ 'wp_doing_cron' ],
		];
	}
}
