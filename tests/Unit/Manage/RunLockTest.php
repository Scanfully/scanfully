<?php
/**
 * Manage run lock unit tests: one job at a time on the site.
 *
 * @package Scanfully\Tests\Unit\Manage
 */

namespace Scanfully\Tests\Unit\Manage;

use Brain\Monkey\Functions;
use Scanfully\Manage\RunLock;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Manage\RunLock
 */
final class RunLockTest extends TestCase {

	private const JOB   = 'a1a1a1a1-1111-4111-8111-111111111111';
	private const OTHER = 'b2b2b2b2-2222-4222-8222-222222222222';

	/**
	 * The options table.
	 *
	 * @var FakeWpdb
	 */
	private FakeWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db        = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->db;
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_a_second_job_does_not_get_the_lock_while_the_first_holds_it(): void {
		$now = 1000;
		$this->assertTrue( RunLock::take( self::JOB, $now + 600, $now ) );
		$this->assertFalse( RunLock::take( self::OTHER, $now + 600, $now + 10 ) );
		$this->assertSame( self::JOB, RunLock::holder( $now + 10 ) );
		$this->assertTrue( RunLock::take( self::JOB, $now + 700, $now + 10 ), 'the holder takes it again' );
	}

	public function test_an_expired_lock_is_taken_over(): void {
		$this->assertTrue( RunLock::take( self::JOB, 1600, 1000 ) );
		$this->assertNull( RunLock::holder( 1601 ) );
		$this->assertTrue( RunLock::take( self::OTHER, 2200, 1601 ) );
		$this->assertSame( self::OTHER, RunLock::holder( 1601 ) );
		$this->assertFalse( RunLock::extend( self::JOB, 3000 ), 'the old holder lost it' );
	}

	public function test_a_heartbeat_extends_the_lock_and_only_the_holder_releases_it(): void {
		$this->assertTrue( RunLock::take( self::JOB, 1600, 1000 ) );
		$this->assertTrue( RunLock::extend( self::JOB, 2800 ) );
		$this->assertSame( self::JOB, RunLock::holder( 2000 ) );

		RunLock::release( self::OTHER );
		$this->assertSame( self::JOB, RunLock::holder( 2000 ), 'another job cannot release it' );

		RunLock::release( self::JOB );
		$this->assertNull( RunLock::holder( 2000 ) );
		$this->assertArrayNotHasKey( RunLock::OPTION, $this->db->rows );
		$this->assertTrue( RunLock::take( self::OTHER, 2600, 2000 ) );
	}
}
