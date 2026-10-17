<?php
/**
 * Content report and overflow unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\Content
 */

namespace Scanfully\Tests\Unit\Events\Content;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Scanfully\Events\Content\Overflow;
use Scanfully\Events\Content\Report;
use Scanfully\Events\ContentEvent;
use Scanfully\Events\Controller;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\Content\Report
 * @covers \Scanfully\Events\Content\Overflow
 * @covers \Scanfully\Events\Content\MinuteStore
 * @covers \Scanfully\Events\ContentEvent
 */
final class ReportTest extends TestCase {

	/**
	 * In-memory transient store.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	/**
	 * Scheduled jobs: hook and arguments.
	 *
	 * @var array<int, array{hook: string, args: array<mixed>}>
	 */
	private array $scheduled = [];

	/**
	 * Fired actions: hook and arguments.
	 *
	 * @var array<int, array{hook: string, args: array<int, mixed>}>
	 */
	private array $fired = [];

	/**
	 * Whether the site is connected.
	 *
	 * @var string
	 */
	private string $connected = 'yes';

	/**
	 * Stash keys made so far.
	 *
	 * @var int
	 */
	private int $uuids = 0;

	/**
	 * The options table the minute store writes to.
	 *
	 * @var FakeOptionsTable
	 */
	private FakeOptionsTable $table;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->alias( fn() => $this->connected );
		$this->table = FakeOptionsTable::install();
		Functions\when( 'get_post_type_object' )->justReturn( (object) [ 'labels' => (object) [ 'name' => 'Posts' ] ] );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'wp_generate_uuid4' )->alias( fn() => sprintf( '%08x-0000-4000-8000-000000000000', ++$this->uuids ) );
		Functions\when( 'get_transient' )->alias( fn( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args ) {
				$this->scheduled[] = [
					'hook' => $hook,
					'args' => $args,
				];
				return 1;
			}
		);
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) [
				'ID'           => 3,
				'display_name' => 'Editor',
			]
		);

		foreach ( [ ContentEvent::hook( 'PostBulkAction' ), ContentEvent::hook( 'PostDeleted' ) ] as $hook ) {
			Actions\expectDone( $hook )->zeroOrMoreTimes()->whenHappen(
				function ( ...$args ) use ( $hook ) {
					$this->fired[] = [
						'hook' => $hook,
						'args' => $args,
					];
				}
			);
		}
	}

	/**
	 * Deleted post items, each with its own event.
	 *
	 * @param int $count How many.
	 * @param int $from  The first ID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function deletes( int $count, int $from = 1 ): array {
		$items = [];
		for ( $id = $from; $id < $from + $count; $id++ ) {
			$items[] = [
				'action' => 'deleted',
				'group'  => 'post',
				'id'     => $id,
				'title'  => 'Post ' . $id,
				'event'  => [
					'hook' => ContentEvent::hook( 'PostDeleted' ),
					'args' => [ [ 'id' => $id ] ],
				],
			];
		}

		return $items;
	}

	/**
	 * The hooks fired, in order.
	 *
	 * @return array<int, string>
	 */
	private function fired_hooks(): array {
		return array_column( $this->fired, 'hook' );
	}

	public function test_a_few_posts_get_rows_of_their_own(): void {
		Report::send( Report::KIND_POST, $this->deletes( 10 ) );

		$this->assertSame( array_fill( 0, 10, ContentEvent::hook( 'PostDeleted' ) ), $this->fired_hooks() );
	}

	public function test_more_than_the_threshold_become_one_row_with_every_post(): void {
		Report::send( Report::KIND_POST, $this->deletes( 11 ) );

		$this->assertSame( [ ContentEvent::hook( 'PostBulkAction' ) ], $this->fired_hooks() );
		$data = $this->fired[0]['args'][0];
		$this->assertSame( 11, $data['count'] );
		$this->assertSame( range( 1, 11 ), array_column( $data['posts'], 'id' ) );
	}

	public function test_the_threshold_is_filterable(): void {
		Filters\expectApplied( 'scanfully_activity_bulk_threshold' )->with( 10, 'post' )->andReturn( 2 );

		Report::send( Report::KIND_POST, $this->deletes( 3 ) );

		$this->assertSame( [ ContentEvent::hook( 'PostBulkAction' ) ], $this->fired_hooks() );
	}

	public function test_posts_only_counted_always_go_in_a_summary_row(): void {
		$items = $this->deletes( 2 );
		unset( $items[1]['event'] );

		Report::send( Report::KIND_POST, $items );

		$this->assertSame( [ ContentEvent::hook( 'PostBulkAction' ) ], $this->fired_hooks() );
	}

	public function test_nothing_is_reported_while_the_site_is_not_connected(): void {
		$this->connected = '';

		Report::send( Report::KIND_POST, $this->deletes( 3 ) );

		$this->assertSame( [], $this->fired );
		$this->assertSame( [], $this->transients );
	}

	public function test_events_over_the_cap_are_kept_for_the_minutes_job(): void {
		Filters\expectApplied( 'scanfully_post_saved_events_per_minute' )->andReturn( 2 );

		Report::send( Report::KIND_POST, $this->deletes( 3 ) );
		Report::send( Report::KIND_POST, $this->deletes( 2, 10 ) );

		$this->assertCount( 2, $this->fired );
		$jobs = array_values( array_filter( $this->scheduled, static fn( array $job ): bool => Controller::ACTION_SEND_OVERFLOW === $job['hook'] ) );
		$this->assertCount( 1, $jobs, 'The minute\'s job is scheduled once.' );

		[ $kind, $bucket ] = $jobs[0]['args'];
		$this->assertSame( Report::KIND_POST, $kind );
		$this->assertCount( 2, $this->table->names( 'scanfully_minute_overflow_post_' . $bucket . '_' ), 'Both requests kept what they could not send, each in an option of its own.' );

		Overflow::send( $kind, $bucket );

		$builds = array_values( array_filter( $this->scheduled, static fn( array $job ): bool => Controller::ACTION_BUILD_CONTENT_EVENT === $job['hook'] ) );
		$this->assertCount( 1, $builds );
		$this->assertSame( 'PostBulkAction', $builds[0]['args']['type'] );
		$this->assertSame(
			[
				'id'   => 3,
				'name' => 'Editor',
			],
			$builds[0]['args']['user']
		);
		$data = $builds[0]['args']['data'];
		$this->assertTrue( $data['overflow'] );
		$this->assertSame( [ 3, 10, 11 ], array_column( $data['posts'], 'id' ) );
		$this->assertSame( [], $this->table->rows );
		$this->assertArrayNotHasKey( 'scanfully_minute_overflow_post_' . $bucket . '_job', $this->transients );
	}

	public function test_a_summary_row_too_long_for_the_job_is_stored_for_it(): void {
		$items = $this->deletes( 300 );

		Report::send( Report::KIND_POST, $items );
		( new \Scanfully\Events\PostBulkAction() )->listener_callback( $this->fired[0]['args'][0] );

		$job = end( $this->scheduled );
		$this->assertSame( Controller::ACTION_BUILD_CONTENT_EVENT, $job['hook'] );
		$this->assertSame( [ 'stashed' => true ], $job['args']['data'] );
		$this->assertCount( 300, $this->transients[ 'scanfully_post_change_' . $job['args']['stash'] ]['data']['posts'] );
	}
}
