<?php
/**
 * Token refresh unit tests.
 *
 * @package Scanfully\Tests\Unit\Cron
 */

namespace Scanfully\Tests\Unit\Cron;

use Brain\Monkey\Functions;
use ReflectionMethod;
use Scanfully\Cron\Controller;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Cron\Controller
 * @covers \Scanfully\Connect\Controller::refresh_access_token
 * @covers \Scanfully\Connect\Scopes
 * @covers \Scanfully\Options\Controller::get_fresh_options
 */
final class TokenRefreshTest extends TestCase {

	private const LOCK = 'scanfully_refresh_lock';

	/**
	 * Stored options, keyed by full option name.
	 *
	 * @var array<string, string>
	 */
	private array $options = [];

	/**
	 * In-memory transient store.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	/**
	 * Number of token requests sent to the API.
	 *
	 * @var int
	 */
	private int $token_requests = 0;

	/**
	 * The next API response: status and body.
	 *
	 * @var array{int, string}
	 */
	private array $api_response = [ 200, '' ];

	/**
	 * The addresses of the token requests.
	 *
	 * @var string[]
	 */
	private array $urls = [];

	/**
	 * Runs inside the fake API call, to simulate another process.
	 *
	 * @var callable|null
	 */
	private $during_request = null;

	/**
	 * The fake database.
	 *
	 * @var FakeWpdb
	 */
	private FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->options = [
			'scanfully_connect_is_connected'   => 'yes',
			'scanfully_connect_site_id'        => 'site-1',
			'scanfully_connect_access_token'   => 'old-access',
			'scanfully_connect_refresh_token'  => 'old-refresh',
			'scanfully_connect_expires'        => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			'scanfully_connect_last_used'      => '',
			'scanfully_connect_date_connected' => '',
			// a connection on the OAuth server; the move from a legacy one has tests of its own.
			'scanfully_connect_flow'           => 'oauth',
		];
		$this->api_response   = [ 200, $this->token_body( 'new-access', 'new-refresh' ) ];
		$this->token_requests = 0;
		$this->during_request = null;

		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ $name ] = (string) $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
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
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_post' )->alias(
			function ( string $url ) {
				++$this->token_requests;
				$this->urls[] = $url;
				if ( null !== $this->during_request ) {
					( $this->during_request )();
				}
				return $this->api_response;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $response ) => $response[0] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $response ) => $response[1] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * A token response body.
	 *
	 * @param string $access  Access token.
	 * @param string $refresh Refresh token.
	 *
	 * @return string
	 */
	private function token_body( string $access, string $refresh ): string {
		return (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
			[
				'access_token'  => $access,
				'refresh_token' => $refresh,
				// the OAuth server's answer, and the legacy route's expiry.
				'expires_in'    => 28 * DAY_IN_SECONDS,
				'scope'         => 'wordpress:read',
				'expires'       => gmdate( 'Y-m-d\TH:i:s\Z', time() + 28 * DAY_IN_SECONDS ),
				'site_id'       => 'site-1',
			]
		);
	}

	/**
	 * Run the private refresh routine.
	 */
	private function refresh(): void {
		$method = new ReflectionMethod( Controller::class, 'refresh_access_token_if_needed' );
		$method->setAccessible( true );
		$method->invoke( null );
	}

	public function test_a_refresh_learns_a_change_made_in_the_dashboard_with_the_same_checked_list(): void {
		Functions\when( 'delete_option' )->alias(
			function ( string $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		$this->options['scanfully_connect_scopes']   = 'wordpress:read';
		$this->options['scanfully_manage_paused']    = 'wordpress:read';
		$this->api_response                          = [ 200, str_replace( '"wordpress:read"', '"wordpress:read wordpress:manage wordpress:later"', $this->token_body( 'new-access', 'new-refresh' ) ) ];

		$this->refresh();

		// The scope this version does not know is left out, as the hourly read leaves it out.
		$this->assertSame( 'wordpress:read wordpress:manage', $this->options['scanfully_connect_scopes'] );
		$this->assertArrayHasKey( 'scanfully_connect_scopes_changed', $this->options, 'noted as changed in the dashboard' );
		$this->assertArrayNotHasKey( 'scanfully_manage_paused', $this->options, 'the pause ends' );

		// The next refresh with the same answer is no change.
		unset( $this->options['scanfully_connect_scopes_changed'] );
		$this->options['scanfully_connect_expires'] = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$this->refresh();
		$this->assertArrayNotHasKey( 'scanfully_connect_scopes_changed', $this->options );
	}

	public function test_a_token_far_from_expiry_is_not_refreshed(): void {
		$this->options['scanfully_connect_expires'] = gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS );

		$this->refresh();

		$this->assertSame( 0, $this->token_requests );
	}

	public function test_an_expiring_token_is_refreshed_and_the_lock_released(): void {
		$this->refresh();

		$this->assertSame( 1, $this->token_requests );
		$this->assertSame( 'new-access', $this->options['scanfully_connect_access_token'] );
		$this->assertSame( 'new-refresh', $this->options['scanfully_connect_refresh_token'] );
		$this->assertArrayNotHasKey( self::LOCK, $this->wpdb->rows );
	}

	public function test_a_refresh_keeps_the_connection_dates(): void {
		$this->options['scanfully_connect_last_used']      = '2026-09-18 08:00:00';
		$this->options['scanfully_connect_date_connected'] = '2026-01-02 03:04:05';

		$this->refresh();

		$this->assertSame( 'new-access', $this->options['scanfully_connect_access_token'] );
		$this->assertSame( '2026-09-18 08:00:00', $this->options['scanfully_connect_last_used'] );
		$this->assertSame( '2026-01-02 03:04:05', $this->options['scanfully_connect_date_connected'] );
	}

	public function test_no_refresh_while_another_process_holds_the_lock(): void {
		$this->wpdb->rows[ self::LOCK ] = (string) time();

		$this->refresh();

		$this->assertSame( 0, $this->token_requests );
		$this->assertSame( 'old-refresh', $this->options['scanfully_connect_refresh_token'] );
		$this->assertArrayHasKey( self::LOCK, $this->wpdb->rows, 'Another process\'s lock must not be released.' );
	}

	public function test_an_abandoned_lock_is_taken_over(): void {
		$this->wpdb->rows[ self::LOCK ] = (string) ( time() - 10 * MINUTE_IN_SECONDS );

		$this->refresh();

		$this->assertSame( 1, $this->token_requests );
		$this->assertArrayNotHasKey( self::LOCK, $this->wpdb->rows );
	}

	public function test_no_second_refresh_when_another_process_already_refreshed(): void {
		// The first process refreshed while this one was starting: the
		// database now holds a token that is far from expiry.
		$this->wpdb->before_insert = function () {
			$this->options['scanfully_connect_refresh_token'] = 'refreshed-elsewhere';
			$this->options['scanfully_connect_expires']       = gmdate( 'Y-m-d H:i:s', time() + 28 * DAY_IN_SECONDS );
		};

		$this->refresh();

		$this->assertSame( 0, $this->token_requests );
		$this->assertSame( 'refreshed-elsewhere', $this->options['scanfully_connect_refresh_token'] );
	}

	public function test_a_parallel_refresh_during_the_request_cannot_take_the_lock(): void {
		$this->during_request = function () {
			$this->assertFalse( $this->wpdb->try_insert_lock(), 'A second process must not get the lock mid-refresh.' );
		};

		$this->refresh();

		$this->assertSame( 1, $this->token_requests );
	}

	/**
	 * @dataProvider provide_failed_responses
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body.
	 */
	public function test_a_failed_response_is_recorded_without_touching_the_tokens( int $status, string $body ): void {
		$this->api_response = [ $status, $body ];

		$this->refresh();

		$this->assertSame( 'old-access', $this->options['scanfully_connect_access_token'] );
		$this->assertSame( 'old-refresh', $this->options['scanfully_connect_refresh_token'] );
		$this->assertSame( 1, $this->transients['scanfully_refresh_failures'] ?? 0 );
		$this->assertArrayNotHasKey( self::LOCK, $this->wpdb->rows );
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public function provide_failed_responses(): array {
		return [
			'api rejects the refresh token' => [ 400, '' ],
			'proxy error page as json'      => [ 502, '{"message":"Bad gateway"}' ],
			'200 with an error body'        => [ 200, '{"message":"maintenance"}' ],
			'200 with missing fields'       => [ 200, '{"access_token":"a","refresh_token":"","expires":"x"}' ],
			'200 with a non-object body'    => [ 200, '"ok"' ],
		];
	}

	public function test_the_site_id_falls_back_to_the_stored_one(): void {
		$this->api_response = [ 200, '{"access_token":"a","refresh_token":"r","expires_in":2419200,"expires":"2030-01-01T00:00:00Z"}' ];

		$this->refresh();

		$this->assertSame( 'site-1', $this->options['scanfully_connect_site_id'] );
		$this->assertSame( 'a', $this->options['scanfully_connect_access_token'] );
	}

	public function test_an_unparsable_stored_expiry_triggers_a_refresh(): void {
		$this->options['scanfully_connect_expires'] = 'not a date';

		$this->refresh();

		$this->assertSame( 1, $this->token_requests );
		$this->assertSame( 'new-access', $this->options['scanfully_connect_access_token'] );
	}

	public function test_a_legacy_connection_moves_to_the_oauth_server_once(): void {
		unset( $this->options['scanfully_connect_flow'] );
		$this->options['scanfully_connect_expires'] = gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS );

		$this->refresh();

		$this->assertSame( 1, $this->token_requests );
		$this->assertStringEndsWith( '/oauth/token', $this->urls[0] );
		$this->assertSame( 'new-access', $this->options['scanfully_connect_access_token'] );
		$this->assertSame( 'oauth', $this->options['scanfully_connect_flow'] );
		$this->assertSame( 'wordpress:read', $this->options['scanfully_connect_scopes'] );

		$this->refresh();
		$this->assertSame( 1, $this->token_requests, 'moved: no refresh until the token expires' );
	}

	public function test_a_failed_move_keeps_the_tokens_and_is_no_broken_connection(): void {
		unset( $this->options['scanfully_connect_flow'] );
		$this->options['scanfully_connect_expires'] = gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS );
		$this->api_response                         = [ 502, '' ];

		$this->refresh();

		$this->assertSame( 'old-access', $this->options['scanfully_connect_access_token'] );
		$this->assertArrayNotHasKey( 'scanfully_refresh_failures', $this->transients );
		$this->assertArrayHasKey( 'scanfully_oauth_unavailable', $this->transients );

		$this->refresh();
		$this->assertSame( 1, $this->token_requests, 'the move waits a day' );
	}

	public function test_a_legacy_connection_the_oauth_server_refuses_refreshes_on_the_legacy_route_when_expiring(): void {
		unset( $this->options['scanfully_connect_flow'] );
		$replies              = [ [ 400, '{"error":"invalid_grant"}' ], [ 200, $this->token_body( 'legacy-access', 'legacy-refresh' ) ] ];
		$this->during_request = function () use ( &$replies ) {
			$this->api_response = array_shift( $replies );
		};

		$this->refresh();

		$this->assertSame( 2, $this->token_requests );
		$this->assertSame( 'legacy-access', $this->options['scanfully_connect_access_token'] );
	}

	public function test_a_moved_connection_the_oauth_server_refuses_is_a_failure(): void {
		$this->api_response = [ 400, '{"error":"invalid_grant"}' ];

		$this->refresh();

		$this->assertSame( 1, $this->token_requests );
		$this->assertSame( 1, $this->transients['scanfully_refresh_failures'] ?? 0 );
	}

	public function test_an_api_without_the_plugin_client_refreshes_an_expiring_token_on_the_legacy_route(): void {
		unset( $this->options['scanfully_connect_flow'] );
		$replies = [ [ 400, '{"error":"invalid_client"}' ], [ 200, $this->token_body( 'legacy-access', 'legacy-refresh' ) ] ];
		$this->during_request = function () use ( &$replies ) {
			$this->api_response = array_shift( $replies );
		};

		$this->refresh();

		$this->assertSame( 2, $this->token_requests );
		$this->assertStringEndsWith( '/oauth/token', $this->urls[0] );
		$this->assertStringEndsWith( '/connect/token', $this->urls[1] );
		$this->assertSame( 'legacy-access', $this->options['scanfully_connect_access_token'] );
		$this->assertArrayNotHasKey( 'scanfully_connect_flow', $this->options );
	}
}

/**
 * In-memory stand-in for $wpdb that understands the refresh-lock queries
 * the way MySQL does: INSERT IGNORE only inserts a missing row, and the
 * conditional UPDATE only changes a row whose value still matches.
 */
final class FakeWpdb {

	/**
	 * Options table name.
	 *
	 * @var string
	 */
	public string $options = 'wp_options';

	/**
	 * Option rows written through this fake, keyed by option name.
	 *
	 * @var array<string, string>
	 */
	public array $rows = [];

	/**
	 * Runs before each INSERT, to simulate another process.
	 *
	 * @var callable|null
	 */
	public $before_insert = null;

	/**
	 * Prepare a query. Returns the query and its arguments for query().
	 *
	 * @param string $query   Query with placeholders.
	 * @param mixed  ...$args Arguments.
	 *
	 * @return array{string, array<int, mixed>}
	 */
	public function prepare( string $query, ...$args ): array {
		return [ $query, $args ];
	}

	/**
	 * Run a prepared query.
	 *
	 * @param array{string, array<int, mixed>} $prepared Prepared query.
	 *
	 * @return int Rows affected.
	 */
	public function query( array $prepared ): int {
		[ $sql, $args ] = $prepared;
		if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
			if ( null !== $this->before_insert ) {
				( $this->before_insert )();
			}
			return $this->try_insert_lock( (string) $args[1] ) ? 1 : 0;
		}
		if ( 0 === strpos( $sql, 'UPDATE' ) ) {
			[ $value, $name, $expected ] = $args;
			if ( isset( $this->rows[ $name ] ) && $this->rows[ $name ] === $expected ) {
				$this->rows[ $name ] = (string) $value;
				return 1;
			}
			return 0;
		}
		return 0;
	}

	/**
	 * Read a single value.
	 *
	 * @param array{string, array<int, mixed>} $prepared Prepared query.
	 *
	 * @return string|null
	 */
	public function get_var( array $prepared ): ?string {
		return $this->rows[ $prepared[1][0] ] ?? null;
	}

	/**
	 * Delete rows.
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $where Conditions.
	 *
	 * @return int Rows deleted.
	 */
	public function delete( string $table, array $where ): int {
		$name = $where['option_name'];
		if ( ! isset( $this->rows[ $name ] ) ) {
			return 0;
		}
		unset( $this->rows[ $name ] );
		return 1;
	}

	/**
	 * Insert the lock row if it does not exist yet, like INSERT IGNORE.
	 *
	 * @param string|null $value Lock value.
	 *
	 * @return bool Whether the row was inserted.
	 */
	public function try_insert_lock( ?string $value = null ): bool {
		if ( isset( $this->rows['scanfully_refresh_lock'] ) ) {
			return false;
		}
		$this->rows['scanfully_refresh_lock'] = $value ?? (string) time();
		return true;
	}
}
