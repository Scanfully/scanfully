<?php
/**
 * Manage runner unit tests.
 *
 * @package Scanfully\Tests\Unit\Manage
 */

namespace Scanfully\Tests\Unit\Manage;

use Scanfully\Manage\Heartbeat;
use Scanfully\Manage\Inventory;
use Scanfully\Manage\JobFailed;
use Scanfully\Manage\JobRun;
use Scanfully\Manage\Runner;
use Brain\Monkey\Functions;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Manage\Runner
 * @covers \Scanfully\Manage\Heartbeat
 * @covers \Scanfully\Manage\JobFailed
 * @covers \Scanfully\Manage\JobRun
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

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		Inventory::$source = null;
		parent::tearDown();
	}

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
			function (): bool {
				++$this->beats;
				return true;
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
		$this->assertSame( 1, $this->beats, 'the first heartbeat, before the action runs' );
		$this->assertSame( '{"plugin_version":"0.0.0-test"}', json_encode( $result['result'] ) );
	}

	public function test_an_action_the_plugin_does_not_know_fails_as_not_supported(): void {
		$result = $this->run_job( [ 'action' => 'install_plugin' ] );

		$this->assertSame( 'failed', $result['state'] );
		$this->assertSame( 'not_supported', $result['error']['class'] );
		$this->assertStringContainsString( 'install_plugin', $result['error']['message'] );
		$this->assertSame( '{}', json_encode( $result['result'] ), 'the result is an object for the API' );
	}

	public function test_the_actions_are_listed_for_the_capabilities_report(): void {
		$this->assertSame( [ 'ping', 'update_plugin', 'update_theme', 'update_core', 'update_translations' ], Runner::actions( true ) );
		$this->assertSame( [ 'ping' ], Runner::actions( false ), 'where WordPress cannot change files, only what needs no files' );
		$this->assertTrue( Runner::knows( 'update_translations' ) );
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
			function (): bool {
				++$this->beats;
				return true;
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
			function (): bool {
				++$this->beats;
				return true;
			},
			30,
			fn(): int => $this->now
		);

		$beat->beat();
		$this->assertSame( 1, $this->beats );
	}

	public function test_nothing_runs_when_the_first_heartbeat_is_refused(): void {
		$ran      = false;
		$registry = [
			'ping' => [
				'run'  => static function () use ( &$ran ): array {
					$ran = true;
					return [];
				},
				'long' => false,
			],
		];

		$result = Runner::run( [ 'action' => 'ping' ], static fn(): bool => false, fn(): int => $this->now, $registry );

		$this->assertNull( $result );
		$this->assertFalse( $ran );

		// A sender that said nothing is no yes either.
		$this->assertNull( Runner::run( [ 'action' => 'ping' ], static function (): void {}, fn(): int => $this->now, $registry ) );
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
		$beat     = static fn(): bool => true;
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
			function (): bool {
				++$this->beats;
				return true;
			},
			fn(): int => $this->now,
			$registry
		);

		$this->assertSame( 'succeeded', $result['state'] );
		$this->assertSame( 2, $this->beats, 'one as it starts, one 31 seconds in' );
		$this->assertSame( '2026-10-28T10:00:31+00:00', $result['finished_at'] );
	}

	public function test_on_a_network_only_the_updates_and_ping_run(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		$ran      = false;
		$registry = [
			'delete_plugin' => [
				'run'  => function () use ( &$ran ): array {
					$ran = true;
					return [];
				},
				'long' => false,
			],
			'update_plugin' => [
				'run'     => static fn(): array => [ 'new_version' => '2.0' ],
				'long'    => true,
				'network' => true,
			],
		];
		$beat     = static fn(): bool => true;

		$refused = Runner::run( [ 'action' => 'delete_plugin' ], $beat, fn(): int => $this->now, $registry );
		$this->assertSame( 'multisite_refused', $refused['error']['class'] );
		$this->assertFalse( $ran );

		$this->assertSame( 'succeeded', Runner::run( [ 'action' => 'update_plugin' ], $beat, fn(): int => $this->now, $registry )['state'] );
		$this->assertSame( 'succeeded', $this->run_job( [ 'action' => 'ping' ] )['state'] );
	}

	public function test_a_maintenance_file_left_behind_fails_an_update_that_went_well(): void {
		$dir = sys_get_temp_dir() . '/scanfully-runner-' . uniqid( '', true );
		mkdir( $dir );
		Functions\when( 'wp_delete_file' )->justReturn( null );
		// Not there when the job starts.
		$run      = new JobRun( 'a1a1a1a1-1111-4111-8111-111111111111', null, $dir . '/.maintenance' );
		$registry = [
			'update_core' => [
				'run'     => static function () use ( $dir ): array {
					mkdir( $dir . '/.maintenance' ); // Cannot be removed as a file.
					return [ 'new_version' => '7.1.4' ];
				},
				'long'    => true,
				'network' => true,
			],
		];

		$result = Runner::run( [ 'action' => 'update_core' ], static fn(): bool => true, fn(): int => $this->now, $registry, $run );

		rmdir( $dir . '/.maintenance' );
		rmdir( $dir );
		$this->assertSame( 'failed', $result['state'] );
		$this->assertSame( 'maintenance', $result['error']['class'] );
		$this->assertSame( '7.1.4', $result['result']->new_version, 'what the update did is still reported' );
	}

	public function test_a_result_of_a_large_site_is_cut_to_the_size_the_api_takes(): void {
		$plugins = [];
		for ( $i = 0; $i < 300; $i++ ) {
			$plugins[] = [
				'slug'           => 'plugin-' . $i,
				'name'           => 'A plugin with a long name number ' . $i,
				'version'        => '1.2.' . $i,
				'latest_version' => '1.3.' . $i,
				'active'         => 0 === $i % 2,
				'url'            => 'https://scanfully.com/plugins/plugin-' . $i . '/',
				'author'         => 'An author with a long name',
				'author_uri'     => 'https://scanfully.com/authors/' . $i . '/',
				'description'    => str_repeat( 'Long text. ', 40 ),
			];
		}
		Functions\when( 'wp_clean_plugins_cache' )->justReturn( null );
		Functions\when( 'wp_clean_themes_cache' )->justReturn( null );
		Functions\when( 'get_site_transient' )->justReturn( (object) [ 'response' => [] ] );
		Inventory::$source = static fn(): array => [
			'plugins'      => $plugins,
			'themes'       => [],
			'core'         => [ 'installed' => '7.1.4' ],
			'translations' => [ 'available' => 0 ],
		];
		$registry          = [
			'update_plugin' => [
				'run'     => static fn(): array => [
					'previous_version'   => '1.2.0',
					'new_version'        => '1.3.0',
					'duration_ms'        => 1234,
					'inventory'          => Inventory::collect(),
					'activity_event_ids' => [ 'e1e1e1e1-1111-4111-8111-111111111111' ],
					'log'                => array_map( static fn( int $n ): string => 'Line ' . $n . ' ' . str_repeat( 'x', 250 ), range( 1, 60 ) ),
				],
				'long'    => true,
				'network' => true,
			],
		];
		$this->assertGreaterThan( Runner::MAX_RESULT_BYTES, strlen( (string) json_encode( call_user_func( $registry['update_plugin']['run'] ) ) ), 'too large as it is' );

		$result = Runner::run( [ 'action' => 'update_plugin' ], static fn(): bool => true, fn(): int => $this->now, $registry );

		$fields = (array) $result['result'];
		$this->assertLessThanOrEqual( Runner::MAX_RESULT_BYTES, strlen( (string) json_encode( $fields ) ) );
		$this->assertSame( 'succeeded', $result['state'] );
		$this->assertNull( $fields['inventory'] );
		$this->assertSame( 'size', $fields['inventory_dropped'] );
		$this->assertCount( 5, $fields['log'], 'the last lines of the log' );
		$this->assertStringStartsWith( 'Line 60', end( $fields['log'] ) );
		$this->assertSame( '1.2.0', $fields['previous_version'] );
		$this->assertSame( '1.3.0', $fields['new_version'] );
		$this->assertSame( [ 'e1e1e1e1-1111-4111-8111-111111111111' ], $fields['activity_event_ids'] );
	}

	public function test_a_result_that_fits_is_left_as_it_is(): void {
		$result = [
			'new_version' => '2.0',
			'log'         => [ 'one' ],
			'inventory'   => [ 'plugins' => [] ],
		];
		$this->assertSame( $result, Runner::fit( $result ) );
	}

	public function test_heartbeats_without_an_answer_go_quiet_for_an_interval(): void {
		$clock = 1000;
		$sent  = 0;
		$beat  = new Heartbeat(
			function () use ( &$sent ) {
				++$sent;
				return null; // The API cannot be reached; each try would wait its timeout.
			},
			30,
			function () use ( &$clock ): int {
				return $clock;
			}
		);
		$this->assertFalse( $beat->start() );
		for ( $i = 0; $i < 50; $i++ ) {
			++$clock;
			$beat->beat( true );
		}

		$this->assertLessThanOrEqual( 3, $sent, 'one at the start, then one per 30 quiet seconds' );
		$this->assertGreaterThanOrEqual( 2, $sent );
	}

	public function test_beats_at_long_steps_keep_a_floor_of_five_seconds(): void {
		$clock = 1000;
		$sent  = 0;
		$beat  = new Heartbeat(
			function () use ( &$sent ): bool {
				++$sent;
				return true;
			},
			30,
			function () use ( &$clock ): int {
				return $clock;
			}
		);
		$beat->start();
		for ( $i = 0; $i < 20; $i++ ) {
			++$clock;
			$beat->beat( true );
		}

		$this->assertSame( 5, $sent, 'the start and one per 5 seconds over 20 seconds' );
	}
}
