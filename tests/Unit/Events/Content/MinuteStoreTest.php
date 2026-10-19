<?php
/**
 * Minute store unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\Content
 */

namespace Scanfully\Tests\Unit\Events\Content;

use Brain\Monkey\Functions;
use Scanfully\Events\Content\MinuteStore;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\Content\MinuteStore
 */
final class MinuteStoreTest extends TestCase {

	/**
	 * The options table.
	 *
	 * @var FakeOptionsTable
	 */
	private FakeOptionsTable $table;

	/**
	 * In-memory transient store.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	/**
	 * Scheduled jobs: time, hook and arguments.
	 *
	 * @var array<int, array{time: int, hook: string, args: array<mixed>}>
	 */
	private array $scheduled = [];

	/**
	 * Keys made so far.
	 *
	 * @var int
	 */
	private int $uuids = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->table = FakeOptionsTable::install();
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
					'time' => $timestamp,
					'hook' => $hook,
					'args' => $args,
				];
				return 1;
			}
		);
	}

	public function test_each_request_keeps_its_entry_and_the_minute_gets_one_job(): void {
		$this->assertTrue( MinuteStore::add( 'media', [ 'n' => 1 ], 'scanfully_job' ) );
		$this->assertTrue( MinuteStore::add( 'media', [ 'n' => 2 ], 'scanfully_job' ) );

		$bucket = MinuteStore::bucket();
		$this->assertCount( 2, $this->table->names( 'scanfully_minute_media_' . $bucket . '_' ) );
		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( 'scanfully_job', $this->scheduled[0]['hook'] );
		$this->assertSame( [ $bucket ], $this->scheduled[0]['args'] );
		$this->assertSame( ( $bucket + 1 ) * 60 + MinuteStore::DELAY, $this->scheduled[0]['time'] );
	}

	public function test_the_jobs_arguments_come_before_the_minute(): void {
		MinuteStore::add( 'overflow_term', [ 'n' => 1 ], 'scanfully_job', [ 'term' ] );

		$this->assertSame( [ 'term', MinuteStore::bucket() ], $this->scheduled[0]['args'] );
	}

	public function test_take_returns_the_entries_oldest_first_and_removes_them(): void {
		MinuteStore::add( 'media', [ 'n' => 1 ], 'scanfully_job' );
		MinuteStore::add( 'media', [ 'n' => 2 ], 'scanfully_job' );
		$bucket = MinuteStore::bucket();

		$this->assertSame( [ [ 'n' => 1 ], [ 'n' => 2 ] ], MinuteStore::take( 'media', $bucket ) );
		$this->assertSame( [], $this->table->rows );
		$this->assertSame( [], $this->transients, 'The minute\'s job marker is gone too.' );
		$this->assertSame( [], MinuteStore::take( 'media', $bucket ), 'A second run of the job finds nothing.' );
	}

	public function test_take_leaves_later_minutes_and_other_kinds_and_sweeps_earlier_ones(): void {
		$bucket = MinuteStore::bucket();
		$key    = str_repeat( 'a', 32 );

		$this->table->rows[ 'scanfully_minute_media_' . ( $bucket - 5 ) . '_' . $key ] = [ 'n' => 'missed' ];
		$this->table->rows[ 'scanfully_minute_media_' . $bucket . '_' . $key ]         = [ 'n' => 'now' ];
		$this->table->rows[ 'scanfully_minute_media_' . ( $bucket + 1 ) . '_' . $key ] = [ 'n' => 'next' ];
		$this->table->rows[ 'scanfully_minute_mediax_' . $bucket . '_' . $key ]        = [ 'n' => 'other kind' ];
		$this->table->rows[ 'scanfully_minute_overflow_post_' . $bucket . '_' . $key ] = [ 'n' => 'overflow' ];

		$this->assertSame( [ [ 'n' => 'missed' ], [ 'n' => 'now' ] ], MinuteStore::take( 'media', $bucket ) );
		$this->assertCount( 3, $this->table->rows );
	}

	public function test_an_entry_another_job_took_first_is_skipped(): void {
		MinuteStore::add( 'media', [ 'n' => 1 ], 'scanfully_job' );
		$bucket = MinuteStore::bucket();

		// Another run deletes it between the read and the claim.
		Functions\when( 'delete_option' )->justReturn( false );

		$this->assertSame( [], MinuteStore::take( 'media', $bucket ) );
	}
}
