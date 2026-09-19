<?php
/**
 * Event sending unit tests.
 *
 * @package Scanfully\Tests\Unit\Events
 */

namespace Scanfully\Tests\Unit\Events;

use Brain\Monkey\Functions;
use RuntimeException;
use Scanfully\Events\Controller;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\Controller::send_event
 * @covers \Scanfully\API\Request::do_request
 */
final class SendEventTest extends TestCase {

	/**
	 * The fake API status, or null for a transport error.
	 *
	 * @var int|null
	 */
	private ?int $status = 202;

	/**
	 * Retries scheduled: their job arguments.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $retries = [];

	protected function setUp(): void {
		parent::setUp();
		$this->retries = [];
		Functions\when( 'get_option' )->justReturn( 'yes' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => null === $thing );
		Functions\when( 'wp_remote_post' )->alias( fn() => null === $this->status ? null : [ $this->status ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $response ) => $response[0] );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args ) {
				$this->retries[] = $args;
				return 1;
			}
		);
	}

	/**
	 * Send an event and return the exception message, or '' when accepted.
	 *
	 * @param int $attempt Attempt number.
	 *
	 * @return string
	 */
	private function send( int $attempt = 0 ): string {
		try {
			Controller::send_event( 'PostSaved', [ 'id' => 1 ], [ 'id' => 2 ], $attempt );
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
		return '';
	}

	public function test_an_accepted_event_is_done(): void {
		$this->assertSame( '', $this->send() );
		$this->assertSame( [], $this->retries );
	}

	/**
	 * @dataProvider provide_temporary_failures
	 *
	 * @param int|null $status API status.
	 */
	public function test_a_temporary_failure_is_retried_and_recorded( ?int $status ): void {
		$this->status = $status;

		$message = $this->send();

		$this->assertStringContainsString( 'did not accept the PostSaved event', $message );
		$this->assertSame( [ [ 'PostSaved', [ 'id' => 1 ], [ 'id' => 2 ], 1 ] ], $this->retries );
	}

	/**
	 * @return array<string, array{int|null}>
	 */
	public function provide_temporary_failures(): array {
		return [
			'no response'     => [ null ],
			'token expired'   => [ 401 ],
			'rate limited'    => [ 429 ],
			'server error'    => [ 500 ],
			'bad gateway'     => [ 502 ],
		];
	}

	public function test_a_rejected_event_is_recorded_but_not_retried(): void {
		$this->status = 400;

		$this->assertStringContainsString( 'HTTP 400', $this->send() );
		$this->assertSame( [], $this->retries );
	}

	public function test_retries_stop_after_three_attempts(): void {
		$this->status = 500;

		$this->assertStringContainsString( 'HTTP 500', $this->send( 3 ) );
		$this->assertSame( [], $this->retries );
	}
}
