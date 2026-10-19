<?php
/**
 * Failed login reporter and store unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\FailedLogins
 */

namespace Scanfully\Tests\Unit\Events\FailedLogins;

use Brain\Monkey\Functions;
use Mockery;
use Scanfully\Events\FailedLogins\Reporter;
use Scanfully\Events\FailedLogins\Store;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\FailedLogins\Reporter
 * @covers \Scanfully\Events\FailedLogins\Store
 * @covers \Scanfully\API\FailedLoginsRequest
 */
final class ReporterTest extends TestCase {

	/**
	 * The counters.
	 *
	 * @var FakeCounterTable
	 */
	private FakeCounterTable $table;

	/**
	 * Bodies sent to the API.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * The fake API status, or null for no answer.
	 *
	 * @var int|null
	 */
	private ?int $status = 204;

	protected function setUp(): void {
		parent::setUp();
		$this->table = FakeCounterTable::install();

		Functions\when( 'get_option' )->justReturn( 'yes' );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => null === $thing );
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->sent[] = json_decode( $args['body'], true );
				return null === $this->status ? null : [ $this->status ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $response ) => $response[0] );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) {
				if ( 7 !== $id && 9 !== $id ) {
					return false;
				}
				$user               = Mockery::mock( 'WP_User' );
				$user->ID           = $id;
				$user->display_name = 7 === $id ? 'Bob' : 'bob@scanfully.com';
				$user->first_name   = '';
				$user->last_name    = 7 === $id ? '' : 'Smith';
				$user->roles        = [ 'administrator' ];
				return $user;
			}
		);
		Functions\when( 'is_email' )->alias( static fn( $text ) => false !== filter_var( $text, FILTER_VALIDATE_EMAIL ) );
	}

	/**
	 * Put counters in an hour.
	 *
	 * @param int                $hour     The hour.
	 * @param array<string, int> $counters The counters.
	 *
	 * @return void
	 */
	private function counters( int $hour, array $counters ): void {
		foreach ( $counters as $counter => $count ) {
			$this->table->rows[ 'scanfully_lf_' . $hour . '_' . $counter ] = $count;
		}
	}

	public function test_the_hours_that_are_over_are_sent_in_one_request_and_removed(): void {
		$now = Store::hour();
		$this->counters(
			$now - 2,
			[
				'total'            => 12,
				'r_wrong_password' => 10,
				'r_unknown_account' => 2,
				'c_login_page'     => 12,
				'u_7'              => 4,
				'u_9'              => 6,
				'u_3'              => 1,
			]
		);
		$this->counters(
			$now - 1,
			[
				'total'     => 1,
				'r_blocked' => 1,
				'c_xmlrpc'  => 1,
			]
		);
		$this->counters( $now, [ 'total' => 5 ] );

		Reporter::send();

		$this->assertCount( 1, $this->sent );
		$hours = $this->sent[0]['hours'];
		$this->assertSame( gmdate( 'Y-m-d\TH:00:00\Z', ( $now - 2 ) * 3600 ), $hours[0]['hour'] );
		$this->assertSame( 12, $hours[0]['total'] );
		$this->assertSame(
			[
				'wrong_password'  => 10,
				'unknown_account' => 2,
				'blocked'         => 0,
			],
			$hours[0]['reasons']
		);
		$this->assertSame(
			[
				'login_page'   => 12,
				'xmlrpc'       => 0,
				'app_password' => 0,
				'other'        => 0,
			],
			$hours[0]['channels']
		);
		$this->assertSame(
			[
				[
					'id'    => 9,
					'name'  => 'Smith',
					'roles' => [ 'administrator' ],
					'count' => 6,
				],
				[
					'id'    => 7,
					'name'  => 'Bob',
					'roles' => [ 'administrator' ],
					'count' => 4,
				],
			],
			$hours[0]['accounts'],
			'The most targeted first, never an email address, accounts that no longer exist left out.'
		);
		$this->assertSame( 1, $hours[1]['total'] );
		$this->assertCount( 2, $hours, 'The current hour is not over.' );

		$this->assertSame( [ 'scanfully_lf_' . $now . '_total' ], array_keys( $this->table->rows ) );
	}

	/**
	 * @dataProvider provide_retried_answers
	 *
	 * @param int|null $status The API's answer.
	 */
	public function test_counts_the_api_did_not_take_are_kept_for_the_next_run( ?int $status ): void {
		$this->status = $status;
		$this->counters( Store::hour() - 1, [ 'total' => 3 ] );

		Reporter::send();

		$this->assertCount( 1, $this->table->rows );
	}

	/**
	 * @return array<string, array{int|null}>
	 */
	public function provide_retried_answers(): array {
		return [
			'no answer'               => [ null ],
			'token being refreshed'   => [ 401 ],
			'an API without the endpoint' => [ 404 ],
			'too many requests'       => [ 429 ],
			'server error'            => [ 503 ],
		];
	}

	public function test_counts_the_api_refuses_as_bad_data_are_dropped(): void {
		$this->status = 400;
		$this->counters( Store::hour() - 1, [ 'total' => 3 ] );

		Reporter::send();

		$this->assertSame( [], $this->table->rows );
	}

	public function test_hours_older_than_a_week_are_dropped_unsent(): void {
		$this->counters( Store::hour() - 7 * 24 - 1, [ 'total' => 3 ] );

		Reporter::send();

		$this->assertSame( [], $this->sent );
		$this->assertSame( [], $this->table->rows );
	}

	public function test_nothing_is_sent_while_the_site_is_not_connected(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		$this->counters( Store::hour() - 1, [ 'total' => 3 ] );

		Reporter::send();

		$this->assertSame( [], $this->sent );
	}

	public function test_deleting_an_hour_leaves_hours_that_start_with_the_same_digits(): void {
		$this->counters( 493000, [ 'total' => 1 ] );
		$this->counters( 4930001, [ 'total' => 1 ] );

		Store::delete_hour( 493000 );

		$this->assertSame( [ 'scanfully_lf_4930001_total' ], array_keys( $this->table->rows ) );
	}
}
