<?php
/**
 * WooCheckout config report queue integration tests.
 *
 * Runs against real WooCommerce (wp-env) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Cron\Controller as CronController;

/**
 * Verifies WooCommerce saves queue the config sync instead of calling the API.
 */
final class WooCheckoutReportQueueTest extends TestCase {

	/**
	 * Number of outgoing HTTP requests.
	 *
	 * @var int
	 */
	private int $http_requests = 0;

	/**
	 * The HTTP interceptor.
	 *
	 * @var callable
	 */
	private $interceptor;

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'WooCommerce not available. Run this suite under wp-env.' );
		}

		$this->http_requests = 0;
		$this->interceptor   = function () {
			++$this->http_requests;
			return new \WP_Error( 'blocked', 'No HTTP in this test.' );
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

	/**
	 * Pending config sync jobs.
	 *
	 * @return int
	 */
	private function queued_syncs(): int {
		return count(
			as_get_scheduled_actions(
				[
					'hook'     => CronController::ACTION_SYNC_WOOCHECKOUT_CONFIG,
					'group'    => 'scanfully',
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				],
				'ids'
			)
		);
	}

	/**
	 * Change the store country, which triggers a config resync.
	 */
	private function change_store_country(): void {
		$current = (string) get_option( 'woocommerce_default_country', 'US' );
		update_option( 'woocommerce_default_country', 'NL' === $current ? 'BE' : 'NL' );
		update_option( 'woocommerce_default_country', 'DE' );
	}

	public function test_a_settings_change_queues_one_sync_without_calling_the_api(): void {
		update_option( 'scanfully_connect_is_connected', 'yes' );

		$this->change_store_country();

		$this->assertSame( 1, $this->queued_syncs(), 'Several changes in one save queue one sync.' );
		$this->assertSame( 0, $this->http_requests, 'Saving settings must not wait on the Scanfully API.' );
	}

	public function test_nothing_is_queued_while_the_site_is_not_connected(): void {
		delete_option( 'scanfully_connect_is_connected' );

		$this->change_store_country();

		$this->assertSame( 0, $this->queued_syncs() );
		$this->assertSame( 0, $this->http_requests );
	}
}
