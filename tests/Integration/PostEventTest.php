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
use Scanfully\Events\PostChanges\Collector;

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
		Collector::reset();
	}

	protected function tearDown(): void {
		if ( function_exists( 'wp_delete_post' ) ) {
			foreach ( $this->posts as $id ) {
				wp_delete_post( $id, true );
			}
			as_unschedule_all_actions( '', [], 'scanfully' );
			delete_option( 'scanfully_connect_is_connected' );
			remove_filter( 'scanfully_post_saved_events_per_minute', '__return_zero' );
			unregister_post_type( 'seopress_404' );
			unregister_post_type( 'scanfully_test_type' );
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
		// The post is reported at the end of the request.
		Collector::flush();

		return count(
			as_get_scheduled_actions(
				[
					'hook'     => EventsController::ACTION_BUILD_POST_EVENT,
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

	public function test_a_seopress_404_record_queues_nothing_from_creation_to_deletion(): void {
		// SEOPress registers the type with an admin screen and saves a
		// published record for every request to a missing URL.
		register_post_type( 'seopress_404', [ 'show_ui' => true ] );

		$this->assertSame(
			[
				'created'        => 0,
				'updated'        => 0,
				'status changed' => 0,
				'trashed'        => 0,
				'deleted'        => 0,
			],
			$this->jobs_queued_per_step( 'seopress_404' )
		);
	}

	public function test_a_custom_post_type_with_an_admin_screen_is_still_reported(): void {
		register_post_type( 'scanfully_test_type', [ 'show_ui' => true ] );

		$queued = $this->jobs_queued_per_step( 'scanfully_test_type' );

		$this->assertSame( 1, $queued['created'] );
		$this->assertSame( 1, $queued['updated'] );
		$this->assertSame( 1, $queued['status changed'] );
		$this->assertSame( 1, $queued['trashed'] );
	}

	/**
	 * Create, update, unpublish, trash and delete a post, each as its own
	 * request, and return how many Scanfully jobs each step queued.
	 *
	 * @param string $type Post type.
	 *
	 * @return array<string, int>
	 */
	private function jobs_queued_per_step( string $type ): array {
		// Other tests' saves this minute must not cap these.
		add_filter( 'scanfully_post_saved_events_per_minute', '__return_zero' );

		$id    = 0;
		$steps = [
			'created'        => function () use ( $type, &$id ): void {
				$id            = (int) wp_insert_post(
					[
						'post_title'  => '"/.env" not found',
						'post_type'   => $type,
						'post_status' => 'publish',
					]
				);
				$this->posts[] = $id;
			},
			'updated'        => static function () use ( &$id ): void {
				wp_update_post(
					[
						'ID'         => $id,
						'post_title' => '"/.git/config" not found',
					]
				);
			},
			'status changed' => static function () use ( &$id ): void {
				wp_update_post(
					[
						'ID'          => $id,
						'post_status' => 'draft',
					]
				);
			},
			'trashed'        => static function () use ( &$id ): void {
				wp_trash_post( $id );
			},
			'deleted'        => static function () use ( &$id ): void {
				wp_delete_post( $id, true );
			},
		];

		$queued = [];
		foreach ( $steps as $step => $run ) {
			as_unschedule_all_actions( '', [], 'scanfully' );
			Collector::reset();
			$run();
			// The post is reported at the end of the request.
			Collector::flush();

			$queued[ $step ] = count(
				as_get_scheduled_actions(
					[
						'group'    => 'scanfully',
						'status'   => \ActionScheduler_Store::STATUS_PENDING,
						'per_page' => -1,
					],
					'ids'
				)
			);
		}

		return $queued;
	}
}
