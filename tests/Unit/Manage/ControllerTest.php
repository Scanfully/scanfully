<?php
/**
 * Manage controller unit tests: the poke, the pull and the runs.
 *
 * @package Scanfully\Tests\Unit\Manage
 */

namespace Scanfully\Tests\Unit\Manage;

use Brain\Monkey\Functions;
use Scanfully\Connect\Address;
use Scanfully\Connect\Scopes;
use Scanfully\Manage\Controller;
use Scanfully\Manage\Ledger;
use WP_REST_Request;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Manage\Controller
 * @covers \Scanfully\Manage\Ledger
 * @covers \Scanfully\API\ManageJobsRequest
 * @covers \Scanfully\API\PluginConnectionRequest
 * @covers \Scanfully\Connect\Scopes
 * @covers \Scanfully\Connect\Address
 */
final class ControllerTest extends TestCase {

	private const API    = 'https://api.scanfully.com/v1';
	private const ISSUER = 'https://api.scanfully.com';
	private const SITE   = 'c0ffee00-0000-4000-8000-000000000001';
	private const JOB    = 'a1a1a1a1-1111-4111-8111-111111111111';

	/**
	 * The signing key.
	 *
	 * @var mixed
	 */
	private static $key;

	/**
	 * The published key set.
	 *
	 * @var string
	 */
	private static string $jwks;

	/**
	 * Stored options, by name without the prefix.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * In-memory transients.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	/**
	 * Pending Action Scheduler actions: hook, args, group.
	 *
	 * @var array<int, array{hook: string, args: array<int, mixed>, recurring: bool}>
	 */
	private array $actions = [];

	/**
	 * Requests to the API: method, URL and decoded body.
	 *
	 * @var array<int, array{method: string, url: string, body: mixed}>
	 */
	private array $requests = [];

	/**
	 * The answers of the API, by method and path after the site.
	 *
	 * @var array<string, array{code: int, body: string}>
	 */
	private array $answers = [];

	/**
	 * The options table.
	 *
	 * @var FakeWpdb
	 */
	private FakeWpdb $db;

	/**
	 * The raw home option (get_option( 'home' )): the site's address.
	 *
	 * @var string
	 */
	private string $home = 'https://scanfully.com';

	/**
	 * What home_url() answers, call after call: a multilingual plugin's
	 * filter, which the address check must not follow.
	 *
	 * @var string[]
	 */
	private array $filtered_home = [ 'https://scanfully.com/fr', 'https://scanfully.com/de' ];

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$key  = openssl_pkey_new(
			[
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);
		$details    = openssl_pkey_get_details( self::$key );
		self::$jwks = (string) json_encode(
			[
				'keys' => [
					[
						'kty' => 'RSA',
						'kid' => 'key-1',
						'n'   => self::b64url( $details['rsa']['n'] ),
						'e'   => self::b64url( $details['rsa']['e'] ),
					],
				],
			]
		);
	}

	protected function setUp(): void {
		parent::setUp();
		$this->db         = new FakeWpdb();
		$GLOBALS['wpdb']  = $this->db;
		$this->options    = [
			'is_connected' => 'yes',
			'site_id'      => self::SITE,
			'access_token' => 'sfy_sat_token',
			'expires'      => '2099-01-01 00:00:00',
			'flow'         => 'oauth',
			'scopes'       => 'wordpress:read wordpress:manage',
		];
		$GLOBALS['scanfully_test_action_scheduler'] = [
			'locks'      => [],
			'dispatched' => [],
		];

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default = '' ) => 'home' === $name ? $this->home : ( $this->options[ str_replace( 'scanfully_connect_', '', $name ) ] ?? ( false === $default ? false : '' ) )
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ str_replace( 'scanfully_connect_', '', $name ) ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ) {
				unset( $this->options[ str_replace( 'scanfully_connect_', '', $name ) ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->alias(
			function () {
				$next = (string) array_shift( $this->filtered_home );
				$this->filtered_home[] = $next;
				return $next;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// The API takes the first heartbeat of the job unless a test says otherwise.
		$this->answers[ 'POST /manage/jobs/' . self::JOB . '/heartbeat' ] = [
			'code' => 200,
			'body' => '{"id":"' . self::JOB . '","state":"running"}',
		];
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'add_query_arg' )->alias(
			function ( ...$args ) {
				// ( array $query, string $url ) or ( string $key, string $value, string $url )
				$query = is_array( $args[0] ) ? $args[0] : [ $args[0] => $args[1] ];
				$url   = (string) ( is_array( $args[0] ) ? $args[1] : $args[2] );
				return $url . '?' . http_build_query( $query );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( $resp ) => $resp['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $resp ) => $resp['body'] );
		Functions\when( 'wp_remote_get' )->alias(
			function ( string $url ) {
				if ( self::ISSUER . '/.well-known/scanfully-plugin-keys.json' === $url ) {
					return [
						'code' => 200,
						'body' => self::$jwks,
					];
				}
				return $this->answer( 'GET', $url, null );
			}
		);
		Functions\when( 'wp_remote_post' )->alias(
			fn( string $url, array $args ) => $this->answer( 'POST', $url, isset( $args['body'] ) ? ( is_string( $args['body'] ) ? json_decode( $args['body'], true ) : $args['body'] ) : null )
		);

		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $when, string $hook, array $args = [], string $group = '', bool $unique = false ) {
				if ( $unique && $this->pending( $hook ) ) {
					return 0;
				}
				$this->actions[] = [
					'hook'      => $hook,
					'args'      => $args,
					'recurring' => false,
				];
				return count( $this->actions );
			}
		);
		Functions\when( 'as_schedule_recurring_action' )->alias(
			function ( $when, int $interval, string $hook ) {
				$this->actions[] = [
					'hook'      => $hook,
					'args'      => [],
					'recurring' => true,
				];
				return count( $this->actions );
			}
		);
		Functions\when( 'as_has_scheduled_action' )->alias(
			fn( string $hook, $args = null ) => $this->pending( $hook, $args )
		);
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function ( string $hook ) {
				$this->actions = array_values( array_filter( $this->actions, fn( array $a ) => $a['hook'] !== $hook ) );
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private static function b64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Whether an action of the hook (and args, when given) is pending.
	 *
	 * @param string     $hook The hook.
	 * @param mixed|null $args The args.
	 */
	private function pending( string $hook, $args = null ): bool {
		foreach ( $this->actions as $action ) {
			if ( $action['hook'] === $hook && ( null === $args || $action['args'] === $args ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The API's answer to a request, recorded.
	 *
	 * @param string $method The method.
	 * @param string $url    The URL.
	 * @param mixed  $body   The decoded body.
	 *
	 * @return array{code: int, body: string}|\WP_Error
	 */
	private function answer( string $method, string $url, $body ) {
		$this->requests[] = [
			'method' => $method,
			'url'    => $url,
			'body'   => $body,
		];
		$path   = substr( $url, strlen( self::API . '/sites/' . self::SITE ) );
		$answer = $this->answers[ $method . ' ' . $path ] ?? [
			'code' => 500,
			'body' => '',
		];
		if ( isset( $answer['none'] ) ) {
			return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}
		return $answer;
	}

	/**
	 * A poke as the API signs it, with claims changed.
	 *
	 * @param array<string, mixed> $claims Claims to change.
	 */
	private function signed( array $claims = [] ): string {
		$now     = time();
		$header  = self::b64url(
			(string) json_encode(
				[
					'alg' => 'RS256',
					'kid' => 'key-1',
				]
			)
		);
		$payload = self::b64url(
			(string) json_encode(
				array_merge(
					[
						'iss'   => self::ISSUER,
						'sub'   => 'manage',
						'aud'   => [ self::SITE ],
						'nbf'   => $now - 60,
						'exp'   => $now + 300,
						'jti'   => bin2hex( random_bytes( 8 ) ),
						'scope' => 'wordpress:read wordpress:manage',
					],
					$claims
				)
			)
		);
		openssl_sign( $header . '.' . $payload, $signature, self::$key, OPENSSL_ALGO_SHA256 );

		return $header . '.' . $payload . '.' . self::b64url( $signature );
	}

	private function poke( string $token ): bool {
		$request = new WP_REST_Request();
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		return Controller::check_permission( $request );
	}

	/**
	 * The jobs answer of the pull.
	 *
	 * @param array<int, array<string, mixed>> $jobs The jobs.
	 */
	private function jobs( array $jobs ): void {
		$this->answers['GET /manage/jobs'] = [
			'code' => 200,
			'body' => (string) json_encode( [ 'jobs' => $jobs ] ),
		];
	}

	/**
	 * A ping job as the API sends it.
	 *
	 * @return array<string, mixed>
	 */
	private static function ping(): array {
		return [
			'id'          => self::JOB,
			'action'      => 'ping',
			'params'      => new \stdClass(),
			'lease_until' => '2026-11-07T12:02:00Z',
			'created_at'  => '2026-11-07T12:00:00Z',
		];
	}

	/**
	 * The run actions pending, by job id.
	 *
	 * @return array<int, string>
	 */
	private function runs(): array {
		$out = [];
		foreach ( $this->actions as $action ) {
			if ( Controller::ACTION_RUN_JOB === $action['hook'] ) {
				$out[] = $action['args'][0];
			}
		}
		return $out;
	}

	/**
	 * Run the pending run actions, as Action Scheduler would, and take them
	 * off the queue.
	 */
	private function run_pending(): void {
		foreach ( $this->runs() as $id ) {
			$this->actions = array_values( array_filter( $this->actions, fn( array $a ) => Controller::ACTION_RUN_JOB !== $a['hook'] ) );
			Controller::run_job( $id );
		}
	}

	/**
	 * The requests sent to a path after the site.
	 *
	 * @param string $method The method.
	 * @param string $path   The path.
	 *
	 * @return array<int, mixed> The bodies.
	 */
	private function sent( string $method, string $path ): array {
		$out = [];
		foreach ( $this->requests as $request ) {
			if ( $request['method'] === $method && self::API . '/sites/' . self::SITE . $path === $request['url'] ) {
				$out[] = $request['body'];
			}
		}
		return $out;
	}

	public function test_a_poke_scanfully_signed_for_manage_passes_once(): void {
		$token = $this->signed();
		$this->assertTrue( $this->poke( $token ) );
		$this->assertFalse( $this->poke( $token ), 'a replayed poke' );
	}

	public function test_a_poke_needs_the_action_the_scope_and_a_grant_that_allows_it(): void {
		$this->assertFalse( $this->poke( $this->signed( [ 'sub' => 'sync' ] ) ), 'a signed sync ask' );
		$this->assertFalse( $this->poke( $this->signed( [ 'scope' => 'wordpress:read' ] ) ), 'a token without the scope' );
		$this->assertFalse( $this->poke( $this->signed( [ 'aud' => [ 'another-site' ] ] ) ), 'another site' );
		$this->assertFalse( $this->poke( 'sfy_sat_token' ), 'the site token itself' );
		$this->assertFalse( $this->poke( '' ) );

		$this->options['scopes'] = 'wordpress:read';
		$this->assertFalse( $this->poke( $this->signed() ), 'the plugin\'s own grant does not allow it' );

		$this->options['scopes']       = 'wordpress:read wordpress:manage';
		$this->options['is_connected'] = 'no';
		$this->assertFalse( $this->poke( $this->signed() ), 'a disconnected site' );
	}

	public function test_a_poke_schedules_a_pull_starts_the_runner_and_is_rate_limited(): void {
		$response = Controller::handle_poke();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'status' => 'scheduled' ], $response->get_data() );
		$this->assertTrue( $this->pending( Controller::ACTION_PULL_NOW ) );
		$this->assertCount( 1, $GLOBALS['scanfully_test_action_scheduler']['dispatched'] );
		$this->assertSame( [], $this->requests, 'the poke itself fetches nothing' );

		$again = Controller::handle_poke();
		$this->assertSame( 429, $again->get_status() );
		$this->assertSame( [ 'status' => 'rate_limited' ], $again->get_data() );
		$this->assertCount( 1, $this->actions );
	}

	public function test_a_pull_schedules_each_new_job_in_an_action_of_its_own(): void {
		$this->jobs(
			[
				self::ping(),
				[
					'id' => 'not-an-id',
					'action' => 'ping',
				],
				[ 'id' => 'b2b2b2b2-2222-4222-8222-222222222222' ],
			]
		);

		Controller::pull();

		$this->assertSame( [ self::JOB ], $this->runs(), 'the job, by its id only, and none of the malformed ones' );
		$this->assertSame(
			[
				'id'     => self::JOB,
				'action' => 'ping',
				'params' => [],
			],
			Ledger::get( self::JOB )['job'] ?? null,
			'the job as pulled, without its lease, is kept in the ledger'
		);
		$this->assertSame( [], $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' ), 'nothing runs in the pull' );
		$this->assertCount( 1, $GLOBALS['scanfully_test_action_scheduler']['dispatched'] );
	}

	public function test_a_job_pulled_again_is_not_started_twice(): void {
		$this->jobs( [ self::ping() ] );
		Controller::pull();
		Controller::pull();
		$this->assertCount( 1, $this->runs(), 'a second pull while the job is scheduled' );

		$this->answers[ 'POST /manage/jobs/' . self::JOB . '/result' ] = [
			'code' => 200,
			'body' => '{"id":"' . self::JOB . '","state":"succeeded"}',
		];
		$this->run_pending();
		$this->assertCount( 1, $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' ) );

		// The API hands it out once more, as during the lease: nothing runs.
		Controller::pull();
		$this->assertSame( [], $this->runs() );

		// Two actions of one job: the second one does nothing.
		Controller::run_job( self::JOB );
		$this->assertCount( 1, $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' ) );
	}

	public function test_a_ping_reports_its_result_with_the_times(): void {
		$this->jobs( [ self::ping() ] );
		$this->answers[ 'POST /manage/jobs/' . self::JOB . '/result' ] = [
			'code' => 200,
			'body' => '{"id":"' . self::JOB . '","state":"succeeded"}',
		];
		Controller::pull();
		$this->run_pending();

		$sent = $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' );
		$this->assertCount( 1, $sent );
		$this->assertSame( 'succeeded', $sent[0]['state'] );
		$this->assertSame( [ 'plugin_version' => SCANFULLY_VERSION ], $sent[0]['result'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $sent[0]['started_at'] );
		$this->assertArrayHasKey( 'finished_at', $sent[0] );
		$this->assertSame( Ledger::REPORTED, Ledger::get( self::JOB )['state'] ?? '' );
		$this->assertSame( [ null ], $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/heartbeat' ), 'one heartbeat, with no body, before ping runs' );
	}

	public function test_a_result_the_api_could_not_take_is_sent_again_on_the_next_pull(): void {
		$this->jobs( [ self::ping() ] );
		Controller::pull();
		$this->run_pending(); // The API answers 500.
		$this->assertSame( Ledger::DONE, Ledger::get( self::JOB )['state'] ?? '' );

		// The lease passed and the job went back to the queue: 409, queued.
		$this->answers[ 'POST /manage/jobs/' . self::JOB . '/result' ] = [
			'code' => 409,
			'body' => '{"error":"job_conflict","state":"queued"}',
		];
		Controller::pull();
		$this->run_pending();
		$this->assertSame( Ledger::DONE, Ledger::get( self::JOB )['state'] ?? '', 'kept for when the job comes back' );

		$this->answers[ 'POST /manage/jobs/' . self::JOB . '/result' ] = [
			'code' => 200,
			'body' => '{"state":"succeeded"}',
		];
		Controller::pull();
		$this->run_pending();
		$sent = $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' );
		$this->assertCount( 3, $sent );
		$this->assertSame( $sent[0], $sent[2], 'the same result, never a second run' );
		$this->assertSame( Ledger::REPORTED, Ledger::get( self::JOB )['state'] ?? '' );
	}

	public function test_a_conflict_about_a_finished_job_counts_as_taken(): void {
		$this->jobs( [ self::ping() ] );
		$this->answers[ 'POST /manage/jobs/' . self::JOB . '/result' ] = [
			'code' => 409,
			'body' => '{"error":"job_conflict","state":"succeeded"}',
		];
		Controller::pull();
		$this->run_pending();
		$this->assertSame( Ledger::REPORTED, Ledger::get( self::JOB )['state'] ?? '' );
	}

	public function test_a_run_that_never_ended_reports_that_it_stopped(): void {
		$started = time() - 20 * MINUTE_IN_SECONDS;
		$this->db->add(
			Ledger::PREFIX . self::JOB,
			(string) json_encode(
				[
					'state'      => Ledger::RUNNING,
					'at'         => $started,
					'started_at' => $started,
				]
			)
		);
		$this->jobs( [ self::ping() ] );
		$this->answers[ 'POST /manage/jobs/' . self::JOB . '/result' ] = [
			'code' => 200,
			'body' => '{"state":"failed"}',
		];

		Controller::pull();
		$this->run_pending();

		$sent = $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' );
		$this->assertCount( 1, $sent );
		$this->assertSame( 'failed', $sent[0]['state'] );
		$this->assertSame( 'fatal', $sent[0]['error']['class'] );
		$this->assertSame( gmdate( 'c', $started ), $sent[0]['started_at'] );
	}

	public function test_a_run_that_just_started_is_left_alone(): void {
		$this->db->add(
			Ledger::PREFIX . self::JOB,
			(string) json_encode(
				[
					'state'      => Ledger::RUNNING,
					'at'         => time(),
					'started_at' => time(),
				]
			)
		);
		$this->jobs( [ self::ping() ] );

		Controller::pull();

		$this->assertSame( [], $this->runs() );
	}

	public function test_nothing_is_pulled_or_run_without_the_scope(): void {
		$this->options['scopes'] = 'wordpress:read';
		$this->jobs( [ self::ping() ] );
		Controller::pull();
		$this->assertSame( [], $this->requests );

		$this->options['scopes'] = 'wordpress:read wordpress:manage';
		Controller::pull();
		$this->options['scopes'] = 'wordpress:read';
		$this->run_pending();
		$this->assertSame( Ledger::SCHEDULED, Ledger::get( self::JOB )['state'] ?? '', 'the grant went before the job ran' );
		$this->assertSame( [], $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' ) );
	}

	public function test_the_clock_follows_the_grant(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		Controller::maybe_sync_clock();
		$this->assertTrue( $this->pending( Controller::ACTION_PULL ) );
		Controller::sync_clock();
		$this->assertCount( 1, $this->actions, 'scheduled once' );

		$this->options['scopes'] = 'wordpress:read';
		Controller::sync_clock();
		$this->assertFalse( $this->pending( Controller::ACTION_PULL ) );
	}

	public function test_a_disconnect_stops_the_clock_and_keeps_the_ledger(): void {
		$this->jobs( [ self::ping() ] );
		$this->answers[ 'POST /manage/jobs/' . self::JOB . '/result' ] = [
			'code' => 200,
			'body' => '{"state":"succeeded"}',
		];
		Controller::sync_clock();
		Controller::pull();
		$this->run_pending();

		Controller::clear();
		$this->assertSame( [], $this->actions );

		// A reconnect within the lease: the API hands the job out again.
		Controller::sync_clock();
		Controller::pull();
		$this->run_pending();
		$this->assertCount( 1, $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' ), 'the job never runs twice' );
	}

	public function test_a_result_is_sent_again_on_the_clock_when_the_job_is_not_handed_out(): void {
		$this->jobs( [ self::ping() ] );
		Controller::pull();
		$this->run_pending(); // The API answers 500.
		$this->assertSame( Ledger::DONE, Ledger::get( self::JOB )['state'] ?? '' );

		// The job got stuck at the API, which hands out no stuck job but takes its late result.
		$this->jobs( [] );
		$this->answers[ 'POST /manage/jobs/' . self::JOB . '/result' ] = [
			'code' => 200,
			'body' => '{"state":"succeeded"}',
		];
		Controller::pull();
		$this->run_pending();

		$this->assertCount( 2, $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' ) );
		$this->assertSame( Ledger::REPORTED, Ledger::get( self::JOB )['state'] ?? '' );
	}

	public function test_a_heartbeat_keeps_a_long_run_from_counting_as_stopped(): void {
		$started = time() - 20 * MINUTE_IN_SECONDS;
		$this->db->add(
			Ledger::PREFIX . self::JOB,
			(string) json_encode(
				[
					'state'      => Ledger::RUNNING,
					'at'         => $started,
					'started_at' => $started,
				]
			)
		);
		Ledger::touch( self::JOB, time() );
		$this->jobs( [] );

		Controller::pull();

		$this->assertSame( [], $this->runs() );
		$this->assertSame( $started, Ledger::get( self::JOB )['started_at'] ?? 0 );
	}

	/**
	 * A job of the id, as the pull keeps it.
	 *
	 * @param string $id The job id.
	 *
	 * @return array{id: string, action: string, params: array}
	 */
	private static function job( string $id ): array {
		return [
			'id'     => $id,
			'action' => 'ping',
			'params' => [],
		];
	}

	public function test_the_ledger_keeps_a_week(): void {
		$this->assertTrue( Ledger::claim( self::job( self::JOB ), time() - 8 * DAY_IN_SECONDS ) );
		$this->assertTrue( Ledger::claim( self::job( 'b2b2b2b2-2222-4222-8222-222222222222' ), time() ) );
		$this->assertFalse( Ledger::claim( self::job( self::JOB ), time() ), 'a second claim' );

		$this->assertSame( 1, Ledger::prune( time() ) );
		$this->assertNull( Ledger::get( self::JOB ) );
		$this->assertNotNull( Ledger::get( 'b2b2b2b2-2222-4222-8222-222222222222' ) );
	}

	public function test_a_job_whose_first_heartbeat_is_refused_is_left_for_the_next_pull(): void {
		$this->jobs( [ self::ping() ] );
		$this->answers[ 'POST /manage/jobs/' . self::JOB . '/heartbeat' ] = [
			'code' => 409,
			'body' => '{"error":"job_conflict","state":"queued"}',
		];
		Controller::pull();
		$this->run_pending();

		$this->assertSame( [], $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' ), 'the lease had passed: nothing ran' );
		$this->assertSame( Ledger::SCHEDULED, Ledger::get( self::JOB )['state'] ?? '' );

		// Handed out again: now it runs, once.
		$this->answers[ 'POST /manage/jobs/' . self::JOB . '/heartbeat' ] = [
			'code' => 200,
			'body' => '{"state":"running"}',
		];
		$this->answers[ 'POST /manage/jobs/' . self::JOB . '/result' ]    = [
			'code' => 200,
			'body' => '{"state":"succeeded"}',
		];
		Controller::pull();
		$this->run_pending();
		$this->assertCount( 1, $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' ) );
		$this->assertSame( Ledger::REPORTED, Ledger::get( self::JOB )['state'] ?? '' );
	}

	/**
	 * The first request sent since the requests were last cleared.
	 *
	 * @return array{method: string, url: string, body: mixed}|null
	 */
	private function first_request(): ?array {
		return $this->requests[0] ?? null;
	}

	/**
	 * Pull the ping job, with its first heartbeat answered so, and run it.
	 *
	 * @param array<string, mixed> $heartbeat The API's answer to the heartbeat.
	 */
	private function run_with_heartbeat( array $heartbeat ): void {
		$this->jobs( [ self::ping() ] );
		$this->answers[ 'POST /manage/jobs/' . self::JOB . '/heartbeat' ] = $heartbeat;
		$this->answers[ 'POST /manage/jobs/' . self::JOB . '/result' ]    = [
			'code' => 200,
			'body' => '{"state":"succeeded"}',
		];
		Controller::pull();
		$this->run_pending();
	}

	/**
	 * First heartbeats that are no yes: the job does not run.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function not_running_answers(): array {
		return [
			'500 from the API'        => [
				[
					'code' => 500,
					'body' => '',
				],
			],
			'503 from a firewall'     => [
				[
					'code' => 503,
					'body' => '<html>Checking your browser</html>',
				],
			],
			'no answer'               => [ [ 'none' => true ] ],
			'200 without the state'   => [
				[
					'code' => 200,
					'body' => '<html>Home</html>',
				],
			],
			'200 with another state'  => [
				[
					'code' => 200,
					'body' => '{"state":"queued"}',
				],
			],
			'another 403'             => [
				[
					'code' => 403,
					'body' => '{"error":"forbidden"}',
				],
			],
		];
	}

	/**
	 * @dataProvider not_running_answers
	 *
	 * @param array<string, mixed> $heartbeat The API's answer to the heartbeat.
	 */
	public function test_a_job_starts_only_when_its_first_heartbeat_is_answered_running( array $heartbeat ): void {
		$this->run_with_heartbeat( $heartbeat );

		$this->assertCount( 1, $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/heartbeat' ) );
		$this->assertSame( [], $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' ), 'nothing ran, nothing is reported' );
		$this->assertSame( Ledger::SCHEDULED, Ledger::get( self::JOB )['state'] ?? '', 'the job is the next pull\'s again' );
		$this->assertFalse( Controller::paused() );

		// The next pull schedules it again; answered running, it runs once.
		$this->answers[ 'POST /manage/jobs/' . self::JOB . '/heartbeat' ] = [
			'code' => 200,
			'body' => '{"state":"running"}',
		];
		Controller::pull();
		$this->run_pending();
		$this->assertCount( 1, $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' ) );
		$this->assertSame( Ledger::REPORTED, Ledger::get( self::JOB )['state'] ?? '' );
	}

	public function test_a_first_heartbeat_answered_401_refreshes_the_token_on_the_next_pull(): void {
		$this->options['refresh_token'] = 'sfy_srt_refresh';
		$this->run_with_heartbeat(
			[
				'code' => 401,
				'body' => '{"error":"invalid_token"}',
			]
		);

		$this->assertSame( [], $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' ) );
		$this->assertSame( Ledger::SCHEDULED, Ledger::get( self::JOB )['state'] ?? '' );
		$this->assertLessThanOrEqual( time(), strtotime( $this->options['expires'] . ' UTC' ), 'the token counts as expired' );
		$this->assertFalse( Controller::paused() );

		$this->requests = [];
		Controller::pull();
		$first = $this->first_request();
		$this->assertSame( self::ISSUER . '/oauth/token', $first['url'] ?? '', 'the next pull refreshes the token first' );
		$this->assertSame( 'refresh_token', $first['body']['grant_type'] ?? '' );
	}

	/**
	 * The codes of a 403 that says what the grant may do.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function permission_codes(): array {
		return [
			'insufficient_scope' => [ 'insufficient_scope' ],
			'beta_required'      => [ 'beta_required' ],
		];
	}

	/**
	 * @dataProvider permission_codes
	 *
	 * @param string $code The error code of the 403.
	 */
	public function test_a_first_heartbeat_refused_for_the_scope_or_beta_pauses_the_clock_and_leaves_the_job( string $code ): void {
		Controller::sync_clock();
		$this->assertTrue( $this->pending( Controller::ACTION_PULL ) );

		$this->run_with_heartbeat(
			[
				'code' => 403,
				'body' => '{"error":"' . $code . '"}',
			]
		);

		$this->assertSame( [], $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' ), 'nothing ran' );
		$this->assertSame( Ledger::SCHEDULED, Ledger::get( self::JOB )['state'] ?? '', 'the job is left alone' );
		$this->assertTrue( Controller::paused() );
		$this->assertFalse( $this->pending( Controller::ACTION_PULL ), 'the clock stops' );
		$this->assertArrayNotHasKey( 'last_request_error', $this->options, 'not a broken connection' );

		$this->requests = [];
		Controller::pull();
		Controller::run_job( self::JOB );
		$this->assertSame( [], $this->requests, 'a paused clock pulls and runs nothing' );
	}

	public function test_a_job_whose_first_heartbeat_answer_got_lost_starts_on_the_next_pull(): void {
		$this->run_with_heartbeat( [ 'none' => true ] );
		$this->assertSame( Ledger::SCHEDULED, Ledger::get( self::JOB )['state'] ?? '' );
		$this->assertSame( [], $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' ) );

		// The heartbeat reached the API, so the job runs there and the pull
		// lists it no more; the heartbeat sent again is answered running.
		$this->jobs( [] );
		$this->answers[ 'POST /manage/jobs/' . self::JOB . '/heartbeat' ] = [
			'code' => 200,
			'body' => '{"state":"running"}',
		];
		Controller::pull();
		$this->assertSame( [ self::JOB ], $this->runs() );
		$this->run_pending();

		$this->assertCount( 2, $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/heartbeat' ) );
		$this->assertCount( 1, $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' ) );
		$this->assertSame( Ledger::REPORTED, Ledger::get( self::JOB )['state'] ?? '' );
	}

	public function test_an_unlisted_job_whose_heartbeat_is_refused_keeps_waiting(): void {
		$this->run_with_heartbeat( [ 'none' => true ] );

		// Cancelled at the API meanwhile: the heartbeat sent again gets a 409.
		$this->jobs( [] );
		$this->answers[ 'POST /manage/jobs/' . self::JOB . '/heartbeat' ] = [
			'code' => 409,
			'body' => '{"error":"job_conflict","state":"cancelled"}',
		];
		Controller::pull();
		$this->run_pending();

		$this->assertSame( [], $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' ) );
		$this->assertSame( Ledger::SCHEDULED, Ledger::get( self::JOB )['state'] ?? '' );
	}

	public function test_a_first_heartbeat_answered_404_is_left_for_the_next_pull(): void {
		$this->run_with_heartbeat(
			[
				'code' => 404,
				'body' => '{"error":"not_found"}',
			]
		);

		$this->assertSame( [], $this->sent( 'POST', '/manage/jobs/' . self::JOB . '/result' ) );
		$this->assertSame( Ledger::SCHEDULED, Ledger::get( self::JOB )['state'] ?? '' );
		$this->assertFalse( Controller::paused() );
	}

	public function test_a_pull_refused_for_the_scope_or_beta_pauses_the_clock_until_the_grant_changes(): void {
		foreach ( [ 'insufficient_scope', 'beta_required' ] as $code ) {
			$this->actions = [];
			unset( $this->options['scanfully_manage_paused'], $this->options['last_request_error'] );
			Controller::sync_clock();
			$this->answers['GET /manage/jobs'] = [
				'code' => 403,
				'body' => '{"error":"' . $code . '","message":"No."}',
			];

			Controller::pull();

			$this->assertTrue( Controller::paused(), $code );
			$this->assertFalse( $this->pending( Controller::ACTION_PULL ), $code . ': the clock stops' );
			$this->assertArrayNotHasKey( 'last_request_error', $this->options, $code . ': not a broken connection' );

			$this->requests = [];
			Controller::pull();
			$this->assertSame( [], $this->requests, $code . ': a paused clock pulls nothing' );
		}

		// The grant changes: the clock goes on.
		$this->options['scopes'] = 'wordpress:read wordpress:manage wordpress:login';
		Controller::sync_clock();
		$this->assertFalse( Controller::paused() );
		$this->assertTrue( $this->pending( Controller::ACTION_PULL ) );
	}

	public function test_a_poke_ends_the_pause(): void {
		$this->answers['GET /manage/jobs'] = [
			'code' => 403,
			'body' => '{"error":"beta_required"}',
		];
		Controller::pull();
		$this->assertTrue( Controller::paused() );

		Controller::handle_poke();

		$this->assertFalse( Controller::paused() );
		$this->assertTrue( $this->pending( Controller::ACTION_PULL ) );
		$this->assertTrue( $this->pending( Controller::ACTION_PULL_NOW ) );
	}

	public function test_another_403_still_counts_as_a_failed_request(): void {
		$this->answers['GET /manage/jobs'] = [
			'code' => 403,
			'body' => '',
		];
		Controller::pull();

		$this->assertFalse( Controller::paused() );
		$this->assertArrayHasKey( 'last_request_error', $this->options );
	}

	public function test_a_long_ledger_is_read_to_its_end(): void {
		for ( $i = 0; $i < 1200; $i++ ) {
			$this->db->add(
				Ledger::PREFIX . sprintf( '00000000-0000-4000-8000-%012d', $i ),
				(string) json_encode(
					[
						'state' => Ledger::REPORTED,
						'at'    => time(),
					]
				)
			);
		}
		$this->db->add(
			Ledger::PREFIX . self::JOB,
			(string) json_encode(
				[
					'state'  => Ledger::DONE,
					'at'     => time(),
					'result' => [ 'state' => 'succeeded' ],
				]
			)
		);

		$this->assertSame( [ self::JOB ], Ledger::open( time() - 900 ) );
	}

	/**
	 * The API's answer to the plugin's read of its connection.
	 *
	 * @param string[] $scopes The scopes.
	 */
	private function connection( array $scopes ): void {
		$this->answers['GET /plugin-connection'] = [
			'code' => 200,
			'body' => (string) json_encode(
				[
					'site_id' => self::SITE,
					'scopes'  => $scopes,
					'scope'   => implode( ' ', $scopes ),
				]
			),
		];
	}

	private function scopes_poke( string $token ): bool {
		$request = new WP_REST_Request();
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		return Scopes::check_permission( $request );
	}

	public function test_a_scopes_poke_needs_a_signed_request_for_scopes_on_an_oauth_connection(): void {
		$this->options['scopes'] = 'wordpress:read';
		$this->assertTrue(
			$this->scopes_poke(
				$this->signed(
					[
						'sub' => 'scopes',
						'scope' => 'wordpress:read',
					]
				)
			),
			'a read-only site takes it'
		);
		$this->assertFalse( $this->scopes_poke( $this->signed( [ 'sub' => 'manage' ] ) ), 'a manage poke' );
		$this->assertFalse(
			$this->scopes_poke(
				$this->signed(
					[
						'sub' => 'scopes',
						'aud' => [ 'another-site' ],
					]
				)
			),
			'another site'
		);
		$this->assertFalse( $this->scopes_poke( 'sfy_sat_token' ), 'the site token itself' );

		$this->options['flow'] = 'legacy';
		$this->assertFalse( $this->scopes_poke( $this->signed( [ 'sub' => 'scopes' ] ) ), 'a connection on connect tokens' );
	}

	public function test_a_scopes_poke_schedules_a_read_and_is_rate_limited(): void {
		$response = Scopes::handle_poke();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'status' => 'scheduled' ], $response->get_data() );
		$this->assertTrue( $this->pending( Scopes::ACTION_CHECK_NOW ) );
		$this->assertSame( [], $this->requests, 'the poke itself fetches nothing' );

		// A second change within the window: the read that ran meanwhile
		// may have missed it, so another one is scheduled for the window's end.
		$this->actions = [];
		$this->assertSame( 429, Scopes::handle_poke()->get_status() );
		$this->assertTrue( $this->pending( Scopes::ACTION_CHECK_NOW ), 'a read after the window' );
	}

	public function test_manage_switched_on_in_the_dashboard_starts_the_clock_and_ends_a_pause(): void {
		$this->options['scopes']                  = 'wordpress:read';
		$this->options['scanfully_manage_paused'] = 'wordpress:read';
		Controller::sync_clock();
		$this->assertFalse( $this->pending( Controller::ACTION_PULL ) );

		$this->connection( [ 'wordpress:read', 'wordpress:manage' ] );
		Scopes::refresh();
		Controller::sync_clock(); // What scanfully_grant_stored runs.

		$this->assertSame( 'wordpress:read wordpress:manage', $this->options['scopes'] );
		$this->assertArrayNotHasKey( 'scanfully_manage_paused', $this->options );
		$this->assertTrue( $this->pending( Controller::ACTION_PULL ) );
		$this->assertGreaterThan( 0, Scopes::changed_at(), 'the settings page says it changed in the dashboard' );
	}

	public function test_manage_switched_off_in_the_dashboard_stops_the_clock(): void {
		Controller::sync_clock();
		$this->assertTrue( $this->pending( Controller::ACTION_PULL ) );

		$this->connection( [ 'wordpress:read' ] );
		Scopes::refresh();
		Controller::sync_clock();

		$this->assertSame( 'wordpress:read', $this->options['scopes'] );
		$this->assertFalse( $this->pending( Controller::ACTION_PULL ) );
	}

	public function test_an_answer_without_scopes_or_the_same_scopes_changes_nothing(): void {
		$this->answers['GET /plugin-connection'] = [
			'code' => 404,
			'body' => '{"error":"not_found"}',
		];
		Scopes::refresh();
		$this->connection( [ 'wordpress:manage' ] );
		Scopes::refresh();
		$this->connection( [ 'wordpress:manage', 'wordpress:read', 'wordpress:database' ] );
		Scopes::refresh();

		$this->assertSame( 'wordpress:read wordpress:manage', $this->options['scopes'] );
		$this->assertSame( 0, Scopes::changed_at() );
		$this->assertArrayNotHasKey( 'last_request_error', $this->options, 'an older API is no broken connection' );
	}

	public function test_the_hourly_check_runs_on_an_oauth_connection_only(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Scopes::maybe_sync_clock();
		$this->assertTrue( $this->pending( Scopes::ACTION_CHECK ) );

		$this->options['flow'] = 'legacy';
		Scopes::sync_clock();
		$this->assertFalse( $this->pending( Scopes::ACTION_CHECK ) );

		$this->options['flow'] = 'oauth';
		Scopes::sync_clock();
		Scopes::clear();
		$this->assertFalse( $this->pending( Scopes::ACTION_CHECK ) );
	}

	public function test_the_address_is_host_port_and_path_without_scheme_case_or_trailing_slash(): void {
		$this->assertSame( 'scanfully.com', Address::normalize( 'https://scanfully.com/' ) );
		$this->assertSame( 'scanfully.com:8080', Address::normalize( 'https://scanfully.com:8080/' ) );
		$this->assertSame( 'scanfully.com:8080/blog', Address::normalize( 'http://Scanfully.com:8080/blog' ) );
		$this->assertSame( 'scanfully.com', Address::normalize( 'http://Scanfully.COM' ) );
		$this->assertSame( 'scanfully.com/blog', Address::normalize( 'https://scanfully.com/Blog/' ) );
		$this->assertSame( 'staging.scanfully.com', Address::normalize( 'https://staging.scanfully.com' ) );
		$this->assertSame( '', Address::normalize( 'not an address' ) );
	}

	public function test_a_move_to_https_or_another_case_is_the_same_site(): void {
		$this->home = 'http://scanfully.com';
		Address::remember();
		$this->home = 'https://scanfully.com';
		$this->assertFalse( Address::moved(), 'only the scheme changed' );

		$this->home = 'https://SCANFULLY.com/';
		$this->assertFalse( Address::moved() );
	}

	public function test_another_port_is_another_site(): void {
		Address::remember();
		$this->home = 'https://scanfully.com:8080';

		$this->assertTrue( Address::moved() );
	}

	public function test_a_home_url_filter_does_not_count_as_a_move(): void {
		// WPML or Polylang answer another home_url() in wp-admin, REST and cron.
		$this->assertNotSame( home_url(), home_url() );
		Address::remember();
		$this->assertSame( 'scanfully.com', $this->options['address'] ?? '' );

		$this->jobs( [] );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertFalse( Address::moved() );
			Controller::pull();
		}
		$this->assertCount( 3, $this->sent( 'GET', '/manage/jobs' ), 'every pull runs' );
	}

	public function test_a_copy_of_the_site_pulls_runs_reports_and_takes_pokes_for_nothing(): void {
		// Connected at scanfully.com, with a job pulled and one result to send.
		Address::remember();
		$this->assertSame( 'scanfully.com', $this->options['address'] ?? '' );
		Controller::sync_clock();
		$this->jobs( [ self::ping() ] );
		Controller::pull();
		$this->assertSame( [ self::JOB ], $this->runs() );
		$done = 'b2b2b2b2-2222-4222-8222-222222222222';
		$this->db->add(
			Ledger::PREFIX . $done,
			(string) json_encode(
				[
					'state'  => Ledger::DONE,
					'at'     => time(),
					'result' => [ 'state' => 'succeeded' ],
				]
			)
		);
		$this->connection( [ 'wordpress:read' ] );

		// The database is copied to staging.
		$this->home     = 'https://staging.scanfully.com';
		$this->requests = [];
		$this->assertTrue( Address::moved() );

		Controller::pull();
		$this->run_pending();
		Controller::run_job( $done );
		Scopes::refresh();
		$this->assertSame( [], $this->requests, 'no pull, no heartbeat, no result, no scopes read, no token refresh' );
		$this->assertSame( Ledger::SCHEDULED, Ledger::get( self::JOB )['state'] ?? '' );
		$this->assertSame( Ledger::DONE, Ledger::get( $done )['state'] ?? '' );
		$this->assertFalse( $this->pending( Controller::ACTION_PULL ), 'the clock stops' );

		Controller::sync_clock();
		$this->assertFalse( $this->pending( Controller::ACTION_PULL ), 'and stays stopped' );

		foreach ( [ Controller::handle_poke(), Scopes::handle_poke() ] as $poke ) {
			$this->assertSame( 409, $poke->get_status() );
			$this->assertSame( 'site_moved', $poke->get_data()['code'] ?? '' );
			$this->assertSame( 'site_moved', $poke->get_data()['status'] ?? '' );
		}
		$this->assertFalse( $this->pending( Controller::ACTION_PULL_NOW ), 'a poke schedules no pull' );
		$this->assertFalse( $this->pending( Scopes::ACTION_CHECK_NOW ), 'a scopes poke schedules no read' );
		$this->assertSame( [], $this->requests );
	}

	public function test_keeping_the_connection_adopts_the_new_address_and_resumes(): void {
		Address::remember();
		$this->home = 'https://www.scanfully.com';
		Controller::sync_clock();
		$this->assertFalse( $this->pending( Controller::ACTION_PULL ) );

		Address::keep();

		$this->assertSame( 'www.scanfully.com', $this->options['address'] ?? '' );
		$this->assertFalse( Address::moved() );
		$this->assertTrue( $this->pending( Controller::ACTION_PULL ), 'the clock runs again' );
		$this->assertTrue( $this->pending( Scopes::ACTION_CHECK_NOW ), 'the scopes are read now' );

		$this->jobs( [ self::ping() ] );
		Controller::pull();
		$this->assertSame( [ self::JOB ], $this->runs() );
		$this->assertSame( 200, Controller::handle_poke()->get_status() );
	}

	public function test_a_connection_without_an_address_adopts_the_current_one_once(): void {
		$this->assertArrayNotHasKey( 'address', $this->options, 'a connection from before this version' );
		$this->jobs( [] );

		Controller::pull();
		$this->assertSame( 'scanfully.com', $this->options['address'] ?? '' );
		$this->assertCount( 1, $this->sent( 'GET', '/manage/jobs' ) );

		// Moved after that: the stored address stays, Manage pauses.
		$this->home = 'https://scanfully-copy.com';
		Controller::pull();
		$this->assertSame( 'scanfully.com', $this->options['address'] ?? '' );
		$this->assertCount( 1, $this->sent( 'GET', '/manage/jobs' ) );

		// The scopes read adopts too, on a connection without Manage.
		unset( $this->options['address'] );
		$this->options['scopes'] = 'wordpress:read';
		$this->connection( [ 'wordpress:read' ] );
		Scopes::refresh();
		$this->assertSame( 'scanfully-copy.com', $this->options['address'] ?? '' );
		$this->assertCount( 1, $this->sent( 'GET', '/plugin-connection' ) );
	}

	public function test_nothing_is_adopted_or_moved_while_disconnected(): void {
		$this->options['is_connected'] = 'no';
		Address::adopt_if_missing();
		$this->assertArrayNotHasKey( 'address', $this->options );

		$this->options['address'] = 'scanfully.com';
		$this->home               = 'https://scanfully-copy.com';
		$this->assertFalse( Address::moved() );
	}

	public function test_a_copy_shows_the_notice_with_both_actions(): void {
		$this->stubTranslationFunctions();
		$this->stubEscapeFunctions();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'admin_url' )->alias( fn( string $path ) => 'https://scanfully.com/wp-admin/' . $path );
		Address::remember();

		ob_start();
		Address::print_page_notice();
		Address::print_admin_notice();
		$this->assertSame( '', trim( (string) ob_get_clean() ), 'no notice at the connected address' );

		$this->home = 'https://staging.scanfully.com';
		$this->assertSame(
			'This WordPress site looks like a copy of scanfully.com. Scanfully Manage is paused here. If this is the same site at a new address, keep the connection; if it is a copy (staging, a backup), reconnect it as its own site.',
			Address::notice_text()
		);
		$this->assertSame(
			'Reconnecting or disconnecting this copy leaves the Scanfully connection of scanfully.com in place.',
			Address::original_stays_text()
		);
		foreach ( [ 'print_page_notice', 'print_admin_notice' ] as $print ) {
			ob_start();
			Address::$print();
			$html = (string) ob_get_clean();
			$this->assertStringContainsString( Address::notice_text(), $html, $print );
			$this->assertStringContainsString( Address::original_stays_text(), $html, $print );
			$this->assertStringContainsString( 'Keep the connection', $html, $print );
			$this->assertStringContainsString( 'scanfully-keep-address=1', $html, $print );
			$this->assertStringContainsString( 'Reconnect as its own site', $html, $print );
			$this->assertStringContainsString( 'scanfully-reconnect=1', $html, $print );
		}

		// Without Manage in the grant only the settings page says it, and
		// says Manage will not run rather than that it is paused.
		$this->options['scopes'] = 'wordpress:read';
		ob_start();
		Address::print_admin_notice();
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
		$this->assertSame(
			'This WordPress site looks like a copy of scanfully.com. Scanfully Manage will not run here. If this is the same site at a new address, keep the connection; if it is a copy (staging, a backup), reconnect it as its own site.',
			Address::notice_text()
		);
		ob_start();
		Address::print_page_notice();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Scanfully Manage will not run here.', $html );
		$this->assertStringContainsString( 'Keep the connection', $html );
		$this->assertStringContainsString( 'Reconnect as its own site', $html );
	}

	/**
	 * Make wp_die() and the redirects throw, so the request handlers stop
	 * where WordPress would.
	 */
	private function stop_on_die_and_redirect(): void {
		Functions\when( 'wp_die' )->alias(
			function ( $message ) {
				throw new \RuntimeException( 'wp_die: ' . $message );
			}
		);
		foreach ( [ 'wp_redirect', 'wp_safe_redirect' ] as $redirect ) {
			Functions\when( $redirect )->alias(
				function ( $location ) {
					throw new \RuntimeException( 'redirect: ' . $location );
				}
			);
		}
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias( fn( string $path ) => 'https://scanfully.com/wp-admin/' . $path );
	}

	/**
	 * Run a request handler, and say how it stopped.
	 *
	 * @param callable $handler The handler.
	 */
	private function stopped( callable $handler ): string {
		try {
			$handler();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		return '';
	}

	public function test_keeping_the_connection_needs_the_nonce_and_manage_options(): void {
		$this->stop_on_die_and_redirect();
		Address::remember();
		$this->home = 'https://staging.scanfully.com';
		$can        = true;
		Functions\when( 'current_user_can' )->alias(
			function () use ( &$can ) {
				return $can;
			}
		);
		Functions\when( 'wp_verify_nonce' )->alias( fn( $nonce, $action ) => 'good' === $nonce && 'scanfully-keep-address' === $action );

		$_GET = [ 'scanfully-keep-address' => '1' ];
		$this->assertStringStartsWith( 'wp_die', $this->stopped( [ Address::class, 'catch_requests' ] ), 'no nonce' );
		$_GET['_wpnonce'] = 'forged';
		$this->assertStringStartsWith( 'wp_die', $this->stopped( [ Address::class, 'catch_requests' ] ), 'a wrong nonce' );
		$_GET['_wpnonce'] = 'good';
		$can              = false;
		$this->assertStringStartsWith( 'wp_die', $this->stopped( [ Address::class, 'catch_requests' ] ), 'a user without manage_options' );
		$this->assertTrue( Address::moved(), 'nothing was adopted' );

		$can = true;
		$this->assertStringContainsString( 'scanfully-address-kept=1', $this->stopped( [ Address::class, 'catch_requests' ] ) );
		$this->assertFalse( Address::moved() );
		$_GET = [];
	}

	/**
	 * The disconnect and reconnect requests.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public function disconnect_requests(): array {
		return [
			'disconnect' => [
				[
					'scanfully-disconnect'       => '1',
					'scanfully-disconnect-nonce' => 'good',
				],
			],
			'reconnect'  => [
				[
					'scanfully-reconnect'       => '1',
					'scanfully-reconnect-nonce' => 'good',
				],
			],
		];
	}

	/**
	 * @dataProvider disconnect_requests
	 *
	 * @param array<string, string> $get The request.
	 */
	public function test_a_copy_forgets_its_connection_without_revoking_the_original_tokens( array $get ): void {
		$this->stop_on_die_and_redirect();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->alias( fn( $nonce ) => 'good' === $nonce );
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) [
				'display_name' => 'Admin',
				'user_login'   => 'admin',
				'ID'           => 1,
			]
		);
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_generate_password' )->justReturn( str_repeat( 'a', 64 ) );
		Functions\when( 'do_action' )->justReturn( null );
		Address::remember();
		$revokes = fn() => array_filter( $this->requests, fn( array $r ) => '/connect/revoke' === substr( $r['url'], -strlen( '/connect/revoke' ) ) );

		// The site itself: its tokens are revoked at the API.
		$_GET = $get;
		$this->assertStringStartsWith( 'redirect', $this->stopped( [ \Scanfully\Connect\Controller::class, 'catch_connect_requests' ] ) );
		$this->assertCount( 1, $revokes(), 'the site revokes its tokens' );

		// A copy: it only forgets the connection.
		$this->requests = [];
		$this->options  = array_merge(
			$this->options,
			[
				'is_connected' => 'yes',
				'site_id'      => self::SITE,
				'access_token' => 'sfy_sat_token',
				'address'      => 'scanfully.com',
			]
		);
		$this->home = 'https://staging.scanfully.com';
		$this->assertStringStartsWith( 'redirect', $this->stopped( [ \Scanfully\Connect\Controller::class, 'catch_connect_requests' ] ) );
		$this->assertCount( 0, $revokes(), 'a copy revokes nothing' );
		$this->assertArrayNotHasKey( 'access_token', $this->options, 'the copy forgot its connection' );
		$_GET = [];
	}

	public function test_a_copy_never_refreshes_the_shared_tokens(): void {
		$this->options['refresh_token'] = 'sfy_srt_refresh';
		$this->options['expires']       = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		Address::remember();
		$this->home = 'https://staging.scanfully.com';

		\Scanfully\Cron\Controller::refresh_access_token_if_needed();
		$this->assertSame( [], $this->requests, 'a copy whose token expires soon refreshes nothing' );

		$this->home = 'https://scanfully.com';
		\Scanfully\Cron\Controller::refresh_access_token_if_needed();
		$first = $this->first_request();
		$this->assertSame( self::ISSUER . '/oauth/token', $first['url'] ?? '', 'the site itself refreshes' );
	}

	public function test_a_copy_leaves_its_capabilities_out_of_the_site_data(): void {
		$data = [
			'data'         => [],
			'capabilities' => [ 'actions' => [ 'ping' ] ],
		];
		Address::remember();
		$this->assertSame( $data, \Scanfully\Health\Controller::without_copied_capabilities( $data ), 'the site itself reports them' );

		$this->home = 'https://staging.scanfully.com';
		$this->assertSame(
			[
				'data'                 => [],
				'capabilities_skipped' => 'site_moved',
			],
			\Scanfully\Health\Controller::without_copied_capabilities( $data )
		);
	}
}
