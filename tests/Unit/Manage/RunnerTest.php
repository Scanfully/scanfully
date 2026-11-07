<?php
/**
 * Manage runner unit tests.
 *
 * @package Scanfully\Tests\Unit\Manage
 */

namespace Scanfully\Tests\Unit\Manage;

use Scanfully\Manage\Heartbeat;
use Scanfully\Manage\JobFailed;
use Scanfully\Manage\Runner;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Manage\Runner
 * @covers \Scanfully\Manage\Heartbeat
 * @covers \Scanfully\Manage\JobFailed
 */
final class RunnerTest extends TestCase {

	/**
	 * A clock that moves only when told.
	 *
	 * @var int
	 */
	private int $now = 1793181600;

	/**
	 * Heartbeats sent.
	 *
	 * @var int
	 */
	private int $beats = 0;

	/**
	 * Run a job with the test clock and heartbeat counter.
	 *
	 * @param array<string, mixed> $job The job.
	 *
	 * @return array<string, mixed>
	 */
	private function run_job( array $job ): array {
		return Runner::run(
			$job,
			function (): void {
				++$this->beats;
			},
			fn(): int => $this->now
		);
	}

	public function test_ping_answers_at_once_with_the_plugin_version(): void {
		$result = $this->run_job(
			[
				'id'     => 'a1a1a1a1-1111-4111-8111-111111111111',
				'action' => 'ping',
				'params' => [],
			]
		);

		$this->assertSame( 'succeeded', $result['state'] );
		$this->assertSame( '2026-10-28T10:00:00+00:00', $result['started_at'] );
		$this->assertSame( '2026-10-28T10:00:00+00:00', $result['finished_at'] );
		$this->assertEquals( (object) [ 'plugin_version' => SCANFULLY_VERSION ], $result['result'] );
		$this->assertSame(
			[
				'class'   => '',
				'message' => '',
			],
			$result['error']
		);
		$this->assertSame( 0, $this->beats, 'ping is not long: no heartbeat' );
		$this->assertSame( '{"plugin_version":"0.0.0-test"}', json_encode( $result['result'] ) );
	}

	public function test_an_action_the_plugin_does_not_know_fails_as_not_supported(): void {
		$result = $this->run_job( [ 'action' => 'update_core' ] );

		$this->assertSame( 'failed', $result['state'] );
		$this->assertSame( 'not_supported', $result['error']['class'] );
		$this->assertStringContainsString( 'update_core', $result['error']['message'] );
		$this->assertSame( '{}', json_encode( $result['result'] ), 'the result is an object for the API' );
	}

	public function test_the_actions_are_listed_for_the_capabilities_report(): void {
		$this->assertSame( [ 'ping' ], Runner::actions() );
		$this->assertTrue( Runner::knows( 'ping' ) );
		$this->assertFalse( Runner::knows( 'reboot' ) );
	}

	public function test_a_failure_keeps_its_class_and_a_long_message_is_cut(): void {
		$failed = Runner::failed( $this->now - 5, $this->now, 'filesystem', str_repeat( 'x', 3000 ) . '  ' );

		$this->assertSame( 'failed', $failed['state'] );
		$this->assertSame( 'filesystem', $failed['error']['class'] );
		$this->assertSame( 1000, strlen( $failed['error']['message'] ) );
		$this->assertSame( '2026-10-28T09:59:55+00:00', $failed['started_at'] );
	}

	public function test_heartbeats_go_at_most_once_per_interval(): void {
		$beat = new Heartbeat(
			function (): void {
				++$this->beats;
			},
			Runner::HEARTBEAT_EVERY,
			fn(): int => $this->now
		);

		$beat->start();
		$beat->beat();
		$this->now += 29;
		$beat->beat();
		$this->assertSame( 1, $this->beats );

		$this->now += 1;
		$beat->beat();
		$beat->beat();
		$this->assertSame( 2, $this->beats );
	}

	public function test_a_beat_before_start_sends_one(): void {
		$beat = new Heartbeat(
			function (): void {
				++$this->beats;
			},
			30,
			fn(): int => $this->now
		);

		$beat->beat();
		$this->assertSame( 1, $this->beats );
	}

	public function test_an_action_that_throws_fails_with_its_class_or_as_fatal(): void {
		$registry = [
			'named'   => [
				'run'  => static function (): array {
					throw new JobFailed( 'package', 'The package could not be downloaded.' );
				},
				'long' => false,
			],
			'crashes' => [
				'run'  => static function (): array {
					throw new \TypeError( 'Argument 1 must be of type array' );
				},
				'long' => false,
			],
		];
		$beat     = static function (): void {};
		$clock    = fn(): int => $this->now;

		$named = Runner::run( [ 'action' => 'named' ], $beat, $clock, $registry );
		$this->assertSame( 'failed', $named['state'] );
		$this->assertSame( 'package', $named['error']['class'] );
		$this->assertSame( 'The package could not be downloaded.', $named['error']['message'] );

		$crashed = Runner::run( [ 'action' => 'crashes' ], $beat, $clock, $registry );
		$this->assertSame( 'fatal', $crashed['error']['class'] );
	}

	public function test_a_long_action_beats_as_it_starts_and_while_it_runs(): void {
		$registry = [
			'slow' => [
				'run'  => function ( array $params, callable $beat ): array {
					foreach ( [ 10, 25, 31 ] as $at ) {
						$this->now += $at - ( $this->now - 1793181600 );
						$beat();
					}
					return [ 'steps' => 3 ];
				},
				'long' => true,
			],
		];

		$result = Runner::run(
			[ 'action' => 'slow' ],
			function (): void {
				++$this->beats;
			},
			fn(): int => $this->now,
			$registry
		);

		$this->assertSame( 'succeeded', $result['state'] );
		$this->assertSame( 2, $this->beats, 'one as it starts, one 31 seconds in' );
		$this->assertSame( '2026-10-28T10:00:31+00:00', $result['finished_at'] );
	}
}
