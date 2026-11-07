<?php
/**
 * Manage controller unit tests: the poke, the pull and the runs.
 *
 * @package Scanfully\Tests\Unit\Manage
 */

namespace Scanfully\Tests\Unit\Manage;

use Brain\Monkey\Functions;
use Scanfully\Manage\Controller;
use Scanfully\Manage\Ledger;
use WP_REST_Request;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Manage\Controller
 * @covers \Scanfully\Manage\Ledger
 * @covers \Scanfully\API\ManageJobsRequest
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
			fn( string $name, $default = '' ) => $this->options[ str_replace( 'scanfully_connect_', '', $name ) ] ?? ( false === $default ? false : '' )
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
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'add_query_arg' )->alias( fn( array $query, string $url ) => $url . '?' . http_build_query( $query ) );
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
			fn( string $url, array $args ) => $this->answer( 'POST', $url, isset( $args['body'] ) ? json_decode( $args['body'], true ) : null )
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
	 * @return array{code: int, body: string}
	 */
	private function answer( string $method, string $url, $body ): array {
		$this->requests[] = [
			'method' => $method,
			'url'    => $url,
			'body'   => $body,
		];
		$path = substr( $url, strlen( self::API . '/sites/' . self::SITE ) );
		return $this->answers[ $method . ' ' . $path ] ?? [
			'code' => 500,
			'body' => '',
		];
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
}
