<?php
/**
 * Event send failure integration tests.
 *
 * Runs a queued event through the real Action Scheduler (wp-env) with the API
 * call intercepted, and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Events\Controller as EventsController;

/**
 * Verifies a failed event is recorded as failed and retried, not lost.
 */
final class EventSendFailureTest extends TestCase {

	/**
	 * The HTTP interceptor.
	 *
	 * @var callable
	 */
	private $interceptor;

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( \ActionScheduler::class ) ) {
			$this->markTestSkipped( 'Action Scheduler not available. Run this suite under wp-env.' );
		}
		update_option( 'scanfully_connect_is_connected', 'yes' );
		$this->interceptor = static function () {
			return [
				'headers'  => [],
				'body'     => 'internal server error',
				'response' => [
					'code'    => 500,
					'message' => 'Internal Server Error',
				],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $this->interceptor );
		as_unschedule_all_actions( '', [], 'scanfully' );
	}

	protected function tearDown(): void {
		if ( function_exists( 'remove_filter' ) ) {
			remove_filter( 'pre_http_request', $this->interceptor );
			as_unschedule_all_actions( '', [], 'scanfully' );
			delete_option( 'scanfully_connect_is_connected' );
		}
		parent::tearDown();
	}

	public function test_a_server_error_marks_the_job_failed_and_queues_a_retry(): void {
		$action_id = as_schedule_single_action(
			time(),
			EventsController::ACTION_SEND_EVENT,
			[
				'type' => 'PostSaved',
				'user' => [ 'id' => 1 ],
				'data' => [ 'id' => 2 ],
			],
			'scanfully'
		);

		\ActionScheduler::runner()->process_action( $action_id );

		$this->assertSame( \ActionScheduler_Store::STATUS_FAILED, \ActionScheduler::store()->get_status( $action_id ) );
		$retries = as_get_scheduled_actions(
			[
				'hook'     => EventsController::ACTION_SEND_EVENT,
				'args'     => [ 'PostSaved', [ 'id' => 1 ], [ 'id' => 2 ], 1 ],
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			],
			'ids'
		);
		$this->assertCount( 1, $retries );
	}
}
