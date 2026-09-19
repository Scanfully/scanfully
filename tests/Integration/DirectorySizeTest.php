<?php
/**
 * Directory size sync integration tests.
 *
 * Runs against real WordPress (wp-env) and skips otherwise. The API call is
 * intercepted, so nothing leaves the test environment.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Cron\Controller as CronController;
use Scanfully\Health\Controller as HealthController;

/**
 * Verifies directory sizes are fresh, and never sent as 0 when unmeasured.
 */
final class DirectorySizeTest extends TestCase {

	/**
	 * Bodies of intercepted directory requests.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * The HTTP interceptor.
	 *
	 * @var callable
	 */
	private $interceptor;

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'recurse_dirsize' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'WordPress runtime not available. Run this suite under wp-env.' );
		}

		$this->sent        = [];
		$this->interceptor = function ( $pre, $args, $url ) {
			if ( false !== strpos( $url, '/directories' ) ) {
				$this->sent[] = json_decode( $args['body'], true );
			}
			return [
				'headers'  => [],
				'body'     => '',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $this->interceptor, 10, 3 );
		as_unschedule_all_actions( '', [], 'scanfully' );
	}

	protected function tearDown(): void {
		if ( function_exists( 'remove_filter' ) ) {
			remove_filter( 'pre_http_request', $this->interceptor, 10 );
			remove_all_filters( 'pre_recurse_dirsize' );
			as_unschedule_all_actions( '', [], 'scanfully' );
		}
		parent::tearDown();
	}

	public function test_sizes_are_measured_fresh_instead_of_read_from_a_stale_cache(): void {
		// A plugins size cached long ago, as WordPress never clears it for
		// plugin updates.
		set_transient( 'dirsize_cache', [ WP_PLUGIN_DIR => 1 ], 10 * YEAR_IN_SECONDS );

		HealthController::send_directories_data();

		$this->assertCount( 1, $this->sent );
		$this->assertNotSame( 0.0, $this->sent[0]['data']['plugins_size'] );
		$this->assertGreaterThan( 0, $this->sent[0]['data']['plugins_size'], 'The stale cached size (1 byte) must not be reported.' );
	}

	public function test_an_unmeasured_directory_sends_nothing_and_queues_one_retry(): void {
		// Simulate the time limit running out for the plugins directory.
		add_filter(
			'pre_recurse_dirsize',
			static function ( $size, $directory ) {
				return WP_PLUGIN_DIR === $directory ? null : $size;
			},
			10,
			2
		);

		HealthController::send_directories_data();
		HealthController::send_directories_data();

		$this->assertSame( [], $this->sent, 'A size of 0 must never be reported for an unmeasured directory.' );
		$retries = as_get_scheduled_actions(
			[
				'hook'     => CronController::ACTION_SYNC_DIRECTORIES,
				'args'     => [ 'retry' => 1 ],
				'group'    => 'scanfully',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			],
			'ids'
		);
		$this->assertCount( 1, $retries, 'Exactly one retry should be queued.' );
	}
}
