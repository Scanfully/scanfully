<?php
/**
 * Email check run source unit tests.
 *
 * @package Scanfully\Tests\Unit\EmailHealth
 */

namespace Scanfully\Tests\Unit\EmailHealth;

use Brain\Monkey\Functions;
use Scanfully\EmailHealth\Controller;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\EmailHealth\Controller::run_ping
 */
final class RunPingSourceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Not connected: the cycle itself returns early, so only the
		// rescheduling decision is exercised.
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'update_option' )->justReturn( true );
	}

	/**
	 * @dataProvider provide_manual_args
	 *
	 * @param mixed $args Arguments as the hook receives them.
	 */
	public function test_a_manual_run_leaves_the_scheduled_chain_alone( $args ): void {
		Functions\expect( 'as_unschedule_all_actions' )->never();
		Functions\expect( 'as_schedule_single_action' )->never();

		Controller::run_ping( $args );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public function provide_manual_args(): array {
		return [
			'string, as Action Scheduler passes it' => [ 'manual' ],
			'array with a source key'               => [ [ 'source' => 'manual' ] ],
		];
	}

	/**
	 * @dataProvider provide_scheduled_args
	 *
	 * @param mixed $args Arguments as the hook receives them.
	 */
	public function test_a_scheduled_run_reschedules_the_next_one( $args ): void {
		Functions\expect( 'as_unschedule_all_actions' )->once();
		Functions\expect( 'as_schedule_single_action' )->once();

		Controller::run_ping( $args );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public function provide_scheduled_args(): array {
		return [
			'no args'        => [ [] ],
			'scheduled'      => [ 'scheduled' ],
			'unknown source' => [ 'something-else' ],
		];
	}
}
