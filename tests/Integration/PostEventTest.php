<?php
/**
 * Post event integration tests.
 *
 * Runs against real WordPress and WooCommerce (wp-env) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Events\Controller as EventsController;

/**
 * Verifies which post saves queue a timeline event.
 */
final class PostEventTest extends TestCase {

	/**
	 * Posts created by a test.
	 *
	 * @var array<int, int>
	 */
	private array $posts = [];

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'wc_get_order_types' ) ) {
			$this->markTestSkipped( 'WordPress with WooCommerce not available. Run this suite under wp-env.' );
		}
		update_option( 'scanfully_connect_is_connected', 'yes' );
		as_unschedule_all_actions( '', [], 'scanfully' );
	}

	protected function tearDown(): void {
		if ( function_exists( 'wp_delete_post' ) ) {
			foreach ( $this->posts as $id ) {
				wp_delete_post( $id, true );
			}
			as_unschedule_all_actions( '', [], 'scanfully' );
			delete_option( 'scanfully_connect_is_connected' );
		}
		parent::tearDown();
	}

	/**
	 * Insert a post and return how many event jobs were queued.
	 *
	 * @param string $type   Post type.
	 * @param string $status Post status.
	 *
	 * @return int
	 */
	private function events_queued_for( string $type, string $status ): int {
		$this->posts[] = wp_insert_post(
			[
				'post_title'  => 'Post event test',
				'post_type'   => $type,
				'post_status' => $status,
			]
		);

		return count(
			as_get_scheduled_actions(
				[
					'hook'     => EventsController::ACTION_SEND_EVENT,
					'group'    => 'scanfully',
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				],
				'ids'
			)
		);
	}

	public function test_publishing_a_post_queues_an_event(): void {
		$this->assertSame( 1, $this->events_queued_for( 'post', 'publish' ) );
	}

	public function test_an_hpos_order_placeholder_queues_nothing(): void {
		$this->assertSame( 0, $this->events_queued_for( 'shop_order_placehold', 'draft' ) );
	}

	public function test_an_order_backup_post_queues_nothing(): void {
		$this->assertSame( 0, $this->events_queued_for( 'shop_order', 'draft' ) );
	}
}
