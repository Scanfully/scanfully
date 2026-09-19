<?php
/**
 * Admin panel state cache unit tests.
 *
 * @package Scanfully\Tests\Unit\EmailHealth
 */

namespace Scanfully\Tests\Unit\EmailHealth;

use Brain\Monkey\Functions;
use ReflectionMethod;
use Scanfully\EmailHealth\Controller;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\EmailHealth\Controller
 * @covers \Scanfully\API\EmailDeliverabilityStateRequest::fetch
 */
final class StateCacheTest extends TestCase {

	/**
	 * In-memory transients: [value, expiration].
	 *
	 * @var array<string, array{mixed, int}>
	 */
	private array $transients = [];

	/**
	 * Timeouts of the state requests made.
	 *
	 * @var array<int, int>
	 */
	private array $requests = [];

	/**
	 * Options saved during the test.
	 *
	 * @var array<string, string>
	 */
	private array $saved = [];

	/**
	 * The fake API response: [status, body], or null for a transport error.
	 *
	 * @var array{int, string}|null
	 */
	private $response = [ 200, '{"state":"healthy"}' ];

	protected function setUp(): void {
		parent::setUp();
		$this->transients = [];
		$this->requests   = [];

		Functions\when( 'get_option' )->justReturn( 'site-1' );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ) {
				$this->saved[ $name ] = (string) $value;
				return true;
			}
		);
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url );
		Functions\when( 'get_transient' )->alias(
			fn( string $key ) => array_key_exists( $key, $this->transients ) ? $this->transients[ $key ][0] : false
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $expiration ) {
				$this->transients[ $key ] = [ $value, $expiration ];
				return true;
			}
		);
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => null === $thing );
		Functions\when( 'wp_remote_get' )->alias(
			function ( string $url, array $args ) {
				$this->requests[] = $args['timeout'];
				return $this->response;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $response ) => $response[0] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $response ) => $response[1] );
	}

	/**
	 * Fetch the state the way the admin panel does.
	 *
	 * @return array|null
	 */
	private function fetch_state(): ?array {
		$method = new ReflectionMethod( Controller::class, 'fetch_state' );
		$method->setAccessible( true );
		return $method->invoke( null );
	}

	public function test_the_state_is_fetched_with_a_short_timeout_and_cached(): void {
		$first  = $this->fetch_state();
		$second = $this->fetch_state();

		$this->assertSame( [ 'state' => 'healthy' ], $first );
		$this->assertSame( $first, $second );
		$this->assertSame( [ 5 ], $this->requests, 'One request with a 5 second timeout; the second load uses the cache.' );
		$this->assertSame( 5 * MINUTE_IN_SECONDS, $this->transients['scanfully_email_deliverability_state'][1] );
	}

	public function test_an_unavailable_api_is_remembered_briefly(): void {
		$this->response = null;

		$this->assertNull( $this->fetch_state() );
		$this->assertNull( $this->fetch_state() );

		$this->assertCount( 1, $this->requests, 'A failed fetch must not be retried on every page load.' );
		$this->assertSame( MINUTE_IN_SECONDS, $this->transients['scanfully_email_deliverability_state'][1] );
	}

	/**
	 * @dataProvider provide_intervals
	 *
	 * @param int    $from_api Interval in the state response.
	 * @param string $stored   Interval stored.
	 */
	public function test_the_configured_interval_is_taken_from_the_state( int $from_api, string $stored ): void {
		$this->response = [ 200, '{"state":"healthy","interval_seconds":' . $from_api . '}' ];

		$this->fetch_state();

		$this->assertSame( $stored, $this->saved['scanfully_connect_email_deliverability_interval_seconds'] );
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public function provide_intervals(): array {
		return [
			'two hours'          => [ 7200, '7200' ],
			'too short, clamped' => [ 1, '900' ],
		];
	}
}
