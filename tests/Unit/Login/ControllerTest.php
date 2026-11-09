<?php
/**
 * Login controller unit tests: the checks of a sign-in link, in order.
 *
 * @package Scanfully\Tests\Unit\Login
 */

namespace Scanfully\Tests\Unit\Login;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Scanfully\Login\Attempts;
use Scanfully\Login\Burns;
use Scanfully\Login\Controller;
use Scanfully\Login\Log;
use Scanfully\Manage\JobToken;
use Scanfully\Sync\SignedRequest;

/**
 * @covers \Scanfully\Login\Controller
 * @covers \Scanfully\Login\Attempts
 * @covers \Scanfully\Login\Log
 */
final class ControllerTest extends LoginTestCase {

	/**
	 * Open a link.
	 *
	 * @param string $token  The token.
	 * @param int    $now    The time.
	 * @param string $method The request method.
	 * @param bool   $https  Whether the request came over HTTPS.
	 *
	 * @return array{reason: string, user: \WP_User|null, login: array{name: string, via: string}}
	 */
	private function open( string $token, int $now = self::NOW, string $method = 'GET', bool $https = true ): array {
		return Controller::attempt( $token, $method, $https, $now );
	}

	public function test_a_valid_link_signs_the_administrator_in(): void {
		$result = $this->open( $this->signed() );

		$this->assertSame( '', $result['reason'] );
		$this->assertSame( $this->users[1], $result['user'] );
		$this->assertSame(
			[
				'name' => 'Barry Kooij',
				'via'  => 'dashboard',
			],
			$result['login']
		);
		$this->assertSame( [], Log::recent(), 'a login is not a refusal' );
		$this->assertCount( 1, json_decode( $this->db->rows[ Burns::OPTION ], true ), 'its jti is burned' );
	}

	public function test_the_hook_runs_on_init_after_action_scheduler_is_ready(): void {
		Actions\expectAdded( 'init' )->once()->with( [ Controller::class, 'maybe_handle' ], 2 );

		Controller::setup();
	}

	public function test_a_request_without_the_parameter_is_left_alone(): void {
		unset( $_GET[ Controller::PARAM ] );
		Functions\expect( 'status_header' )->never();

		Controller::maybe_handle();

		$this->assertSame( [], Log::recent() );
	}

	/**
	 * Every way a link is refused, each with one thing wrong.
	 *
	 * @return array<string, array{0: callable, 1: string}>
	 */
	public static function refusals(): array {
		return [
			'not a GET'                    => [ fn( self $t ) => $t->open( $t->signed(), self::NOW, 'POST' ), Controller::REASON_METHOD ],
			'a HEAD'                       => [ fn( self $t ) => $t->open( $t->signed(), self::NOW, 'HEAD' ), Controller::REASON_METHOD ],
			'plain HTTP'                   => [ fn( self $t ) => $t->open( $t->signed(), self::NOW, 'GET', false ), Controller::REASON_NOT_HTTPS ],
			'empty'                        => [ fn( self $t ) => $t->open( '' ), Controller::REASON_MALFORMED ],
			'not a JWT'                    => [ fn( self $t ) => $t->open( 'hello' ), Controller::REASON_MALFORMED ],
			'too long'                     => [ fn( self $t ) => $t->open( 'eyJ' . str_repeat( 'a', 4096 ) . '.a.a' ), Controller::REASON_MALFORMED ],
			'a JWT with other characters'  => [ fn( self $t ) => $t->open( $t->signed() . '<script>' ), Controller::REASON_MALFORMED ],
			'a trailing newline'           => [ fn( self $t ) => $t->open( $t->signed() . "\n" ), Controller::REASON_MALFORMED ],
			'signed with another key'      => [ fn( self $t ) => $t->open( $t->signed( [], [], self::$other_key ) ), Controller::REASON_SIGNATURE ],
			'an unknown kid'               => [ fn( self $t ) => $t->open( $t->signed( [], [ 'kid' => 'key-9' ] ) ), Controller::REASON_SIGNATURE ],
			'alg none'                     => [ fn( self $t ) => $t->open( $t->signed( [], [ 'alg' => 'none' ] ) ), Controller::REASON_SIGNATURE ],
			'HS256'                        => [ fn( self $t ) => $t->open( $t->signed( [], [ 'alg' => 'HS256' ] ) ), Controller::REASON_SIGNATURE ],
			'a changed claim'              => [ fn( self $t ) => $t->open( $t->tampered() ), Controller::REASON_SIGNATURE ],
			'another issuer'               => [ fn( self $t ) => $t->open( $t->signed( [ 'iss' => 'https://evil.example' ] ) ), Controller::REASON_ISSUER ],
			'another site'                 => [ fn( self $t ) => $t->open( $t->signed( [ 'aud' => 'e5e5e5e5-5555-4555-8555-555555555555' ] ) ), Controller::REASON_AUDIENCE ],
			'this site and another'        => [ fn( self $t ) => $t->open( $t->signed( [ 'aud' => [ self::SITE, 'e5e5e5e5-5555-4555-8555-555555555555' ] ] ) ), Controller::REASON_AUDIENCE ],
			'no audience'                  => [ fn( self $t ) => $t->open( $t->signed( [ 'aud' => null ] ) ), Controller::REASON_AUDIENCE ],
			'another host'                 => [ fn( self $t ) => $t->open( $t->signed( [ 'host' => 'evil.example' ] ) ), Controller::REASON_ADDRESS ],
			'a host under this one'        => [ fn( self $t ) => $t->open( $t->signed( [ 'host' => 'scanfully.com.evil.example' ] ) ), Controller::REASON_ADDRESS ],
			'this host with a path'        => [ fn( self $t ) => $t->open( $t->signed( [ 'host' => 'scanfully.com/blog' ] ) ), Controller::REASON_ADDRESS ],
			'this host with a scheme'      => [ fn( self $t ) => $t->open( $t->signed( [ 'host' => 'https://scanfully.com' ] ) ), Controller::REASON_ADDRESS ],
			'this host not normalized'     => [ fn( self $t ) => $t->open( $t->signed( [ 'host' => 'Scanfully.com' ] ) ), Controller::REASON_ADDRESS ],
			'an empty host'                => [ fn( self $t ) => $t->open( $t->signed( [ 'host' => '' ] ) ), Controller::REASON_ADDRESS ],
			'no host'                      => [ fn( self $t ) => $t->open( $t->signed( [ 'host' => null ] ) ), Controller::REASON_ADDRESS ],
			'a host as a list'             => [ fn( self $t ) => $t->open( $t->signed( [ 'host' => [ 'scanfully.com' ] ] ) ), Controller::REASON_ADDRESS ],
			'a job token'                  => [ fn( self $t ) => $t->open( $t->signed( [ 'sub' => 'update_plugin' ] ) ), Controller::REASON_PURPOSE ],
			'a manage poke'                => [ fn( self $t ) => $t->open( $t->signed( [ 'sub' => 'manage' ] ) ), Controller::REASON_PURPOSE ],
			'expired beyond the skew'      => [ fn( self $t ) => $t->open( $t->signed(), self::NOW + 60 + 31 ), Controller::REASON_EXPIRED ],
			'issued in the future'         => [ fn( self $t ) => $t->open( $t->signed(), self::NOW - 31 ), Controller::REASON_NOT_YET_VALID ],
			'not before in the future'     => [ fn( self $t ) => $t->open( $t->signed( [ 'nbf' => self::NOW + 31 ] ) ), Controller::REASON_NOT_YET_VALID ],
			'living longer than a minute'  => [ fn( self $t ) => $t->open( $t->signed( [ 'exp' => self::NOW + 61 ] ) ), Controller::REASON_LIFETIME ],
			'expiring before it is issued' => [ fn( self $t ) => $t->open( $t->signed( [ 'exp' => self::NOW - 1 ] ) ), Controller::REASON_LIFETIME ],
			'exp as a string'              => [ fn( self $t ) => $t->open( $t->signed( [ 'exp' => (string) ( self::NOW + 60 ) ] ) ), Controller::REASON_CLAIMS ],
			'no iat'                       => [ fn( self $t ) => $t->open( $t->signed( [ 'iat' => null ] ) ), Controller::REASON_CLAIMS ],
			'no jti'                       => [ fn( self $t ) => $t->open( $t->signed( [ 'jti' => null ] ) ), Controller::REASON_CLAIMS ],
			'a very long jti'              => [ fn( self $t ) => $t->open( $t->signed( [ 'jti' => str_repeat( 'a', 129 ) ] ) ), Controller::REASON_CLAIMS ],
			'no uid'                       => [ fn( self $t ) => $t->open( $t->signed( [ 'uid' => null ] ) ), Controller::REASON_CLAIMS ],
			'no wp_user'                   => [ fn( self $t ) => $t->open( $t->signed( [ 'wp_user' => null ] ) ), Controller::REASON_CLAIMS ],
			'wp_user as a string'          => [ fn( self $t ) => $t->open( $t->signed( [ 'wp_user' => '1' ] ) ), Controller::REASON_CLAIMS ],
			'no name'                      => [ fn( self $t ) => $t->open( $t->signed( [ 'name' => null ] ) ), Controller::REASON_CLAIMS ],
			'a name over 120 characters'   => [ fn( self $t ) => $t->open( $t->signed( [ 'name' => str_repeat( 'é', 121 ) ] ) ), Controller::REASON_CLAIMS ],
			'another via'                  => [ fn( self $t ) => $t->open( $t->signed( [ 'via' => 'email' ] ) ), Controller::REASON_CLAIMS ],
			'a user that is gone'          => [ fn( self $t ) => $t->open( $t->signed( [ 'wp_user' => 99 ] ) ), Controller::REASON_USER ],
			'a user that is no admin'      => [ fn( self $t ) => $t->open( $t->signed( [ 'wp_user' => 2 ] ) ), Controller::REASON_USER ],
		];
	}

	/**
	 * A signed token with one claim changed after signing.
	 *
	 * @return string
	 */
	public function tampered(): string {
		[ $head, , $signature ] = explode( '.', $this->signed() );
		$claims                 = array_merge( $this->login_claims(), [ 'wp_user' => 2 ] );

		return $head . '.' . self::b64url( (string) json_encode( $claims ) ) . '.' . $signature;
	}

	/**
	 * @dataProvider refusals
	 *
	 * @param callable $open   Opens the link.
	 * @param string   $reason The reason it is refused.
	 */
	public function test_a_link_is_refused( callable $open, string $reason ): void {
		$result = $open( $this );

		$this->assertSame( $reason, $result['reason'] );
		$this->assertNull( $result['user'] );
		$this->assertSame( Controller::REASON_METHOD === $reason ? [] : [ $reason ], array_column( Log::recent(), 'reason' ), 'the reason is logged, except a HEAD or POST' );
	}

	public function test_the_clock_skew_is_30_seconds_each_way(): void {
		$this->assertSame( '', $this->open( $this->signed(), self::NOW + 60 + 30 )['reason'], 'exp 30 seconds ago' );
		$this->assertSame( '', $this->open( $this->signed(), self::NOW - 30 )['reason'], 'iat 30 seconds ahead' );
	}

	public function test_an_api_token_and_mcp_may_ask_too(): void {
		$this->assertSame( 'api_token', $this->open( $this->signed( [ 'via' => 'api_token' ] ) )['login']['via'] );
		$this->assertSame( 'mcp', $this->open( $this->signed( [ 'via' => 'mcp' ] ) )['login']['via'] );
	}

	public function test_a_list_audience_naming_only_this_site_is_accepted(): void {
		$this->assertSame( '', $this->open( $this->signed( [ 'aud' => [ self::SITE ] ] ) )['reason'] );
	}

	public function test_the_key_set_could_not_be_read(): void {
		$this->keys_status = 503;

		$this->assertSame( Controller::REASON_KEYS_UNAVAILABLE, $this->open( $this->signed() )['reason'] );
	}

	public function test_a_copy_of_the_site_signs_nobody_in(): void {
		$this->options['home'] = 'https://staging.scanfully.com';

		$this->assertSame( Controller::REASON_SITE_MOVED, $this->open( $this->signed() )['reason'] );
	}

	public function test_a_link_sent_to_another_address_is_refused_and_not_burned(): void {
		// Scanfully's domain was pointed at another host: the link minted for
		// it is caught there and replayed here, within its minute.
		$token = $this->signed( [ 'host' => 'evil.example' ] );

		$this->assertSame( Controller::REASON_ADDRESS, $this->open( $token )['reason'] );
		$this->assertArrayNotHasKey( Burns::OPTION, $this->db->rows, 'refused before its burn' );
		$this->assertSame( [ Controller::REASON_ADDRESS ], array_column( Log::recent(), 'reason' ) );
	}

	public function test_the_host_is_the_raw_home_with_its_port_and_path(): void {
		$this->options['home']                      = 'https://Scanfully.com:8443/blog/';
		$this->options['scanfully_connect_address'] = hash( 'sha256', 'scanfully.com:8443/blog' );

		$this->assertSame( Controller::REASON_ADDRESS, $this->open( $this->signed() )['reason'], 'the bare host is not this address' );
		$this->assertSame( '', $this->open( $this->signed( [ 'host' => 'scanfully.com:8443/blog' ] ) )['reason'] );
	}

	public function test_a_site_that_is_not_connected_signs_nobody_in(): void {
		$this->options['scanfully_connect_is_connected'] = '';
		$this->options['scanfully_connect_site_id']      = '';

		$this->assertSame( Controller::REASON_AUDIENCE, $this->open( $this->signed() )['reason'] );
	}

	public function test_the_plugins_copy_of_the_grant_decides(): void {
		$this->options['scanfully_connect_scopes'] = 'wordpress:read wordpress:manage';
		$this->assertSame( Controller::REASON_SCOPE, $this->open( $this->signed() )['reason'], 'the scope was revoked' );

		$this->options['scanfully_connect_scopes'] = 'wordpress:read wordpress:login';
		$this->options['scanfully_connect_flow']   = '';
		$this->assertSame( Controller::REASON_SCOPE, $this->open( $this->signed() )['reason'], 'a connection from before the OAuth server has no login' );
	}

	public function test_a_replayed_link_is_refused(): void {
		$token = $this->signed();

		$this->assertSame( '', $this->open( $token )['reason'] );
		$this->assertSame( Controller::REASON_REPLAYED, $this->open( $token, self::NOW + 5 )['reason'] );
	}

	public function test_a_link_refused_after_its_burn_cannot_be_tried_again(): void {
		$this->options['scanfully_connect_scopes'] = 'wordpress:read';
		$token                                     = $this->signed();
		$this->assertSame( Controller::REASON_SCOPE, $this->open( $token )['reason'] );

		$this->options['scanfully_connect_scopes'] = 'wordpress:read wordpress:login';
		$this->assertSame( Controller::REASON_REPLAYED, $this->open( $token, self::NOW + 1 )['reason'], 'burned on first sight' );
	}

	public function test_a_link_refused_before_its_burn_burns_nothing(): void {
		$this->open( $this->signed( [ 'sub' => 'manage' ] ) );

		$this->assertArrayNotHasKey( Burns::OPTION, $this->db->rows );
	}

	public function test_the_checks_run_in_the_contracts_order(): void {
		// Every claim wrong: each fix moves the refusal one check further.
		$steps = [
			Controller::REASON_ISSUER        => [ 'iss' => self::ISSUER ],
			Controller::REASON_AUDIENCE      => [ 'aud' => self::SITE ],
			Controller::REASON_ADDRESS       => [ 'host' => 'scanfully.com' ],
			Controller::REASON_PURPOSE       => [ 'sub' => 'login' ],
			Controller::REASON_EXPIRED       => [ 'exp' => self::NOW + 60 ],
			Controller::REASON_CLAIMS        => [ 'via' => 'dashboard' ],
			Controller::REASON_REPLAYED      => [ 'jti' => 'fresh-jti' ],
			Controller::REASON_SCOPE         => [],
			Controller::REASON_USER          => [ 'wp_user' => 1 ],
		];
		$claims = [
			'iss'     => 'https://evil.example',
			'aud'     => 'another-site',
			'host'    => 'evil.example',
			'sub'     => 'update_plugin',
			'exp'     => self::NOW - 600,
			'iat'     => self::NOW - 660,
			'via'     => 'email',
			'jti'     => 'seen-jti',
			'wp_user' => 2,
		];
		Burns::burn( 'seen-jti', self::NOW + 60, self::NOW );
		$this->options['scanfully_connect_scopes'] = 'wordpress:read';
		$now                                       = self::NOW;

		foreach ( $steps as $reason => $fix ) {
			$this->assertSame( $reason, $this->open( $this->signed( $claims ), $now )['reason'] );
			$claims = array_merge( $claims, $fix );
			if ( isset( $fix['exp'] ) ) {
				$claims['iat'] = self::NOW;
			}
			if ( Controller::REASON_SCOPE === $reason ) {
				$this->options['scanfully_connect_scopes'] = 'wordpress:read wordpress:login';
			}
			if ( in_array( $reason, [ Controller::REASON_SCOPE, Controller::REASON_USER ], true ) ) {
				// The token was burned at its jti check: the next is a new one.
				$claims['jti'] = 'fresh-jti-after-' . $reason;
			}
		}
		$this->assertSame( '', $this->open( $this->signed( $claims ), $now )['reason'] );
	}

	public function test_junk_never_counts_against_the_limit_and_logs_ten_lines_a_minute(): void {
		$junk = [
			fn() => $this->open( 'not a token', self::NOW, 'GET', false ),
			fn() => $this->open( 'x' ),
			fn() => $this->open( $this->signed( [], [], self::$other_key ) ),
		];
		for ( $i = 0; $i < 30; $i++ ) {
			$junk[ $i % 3 ]();
		}

		$this->assertSame(
			array_merge( array_slice( array_merge( ...array_fill( 0, 4, [ Controller::REASON_NOT_HTTPS, Controller::REASON_MALFORMED, Controller::REASON_SIGNATURE ] ) ), 0, Attempts::LIMIT ), [ Controller::REASON_UNSIGNED_BURST ] ),
			array_column( Log::recent(), 'reason' ),
			'thirty links that are not Scanfully\'s write eleven log lines'
		);
		$this->assertArrayNotHasKey( Attempts::TRANSIENT, $this->transients, 'none counted against the limit' );
		$this->assertSame( '', $this->open( $this->signed(), self::NOW + 1 )['reason'], 'a real link still gets in' );
		$this->assertSame( Controller::REASON_MALFORMED, $this->open( 'x', self::NOW + 2 )['reason'], 'junk beyond the log limit is still refused for its reason' );
		$this->assertCount( Attempts::LIMIT + 1, Log::recent(), 'without a line' );

		$this->open( 'x', self::NOW + 60 );
		$this->assertCount( Attempts::LIMIT + 2, Log::recent(), 'the next minute logs afresh' );
	}

	public function test_beyond_ten_signed_links_a_minute_nothing_past_the_signature_is_checked(): void {
		for ( $i = 0; $i < Attempts::LIMIT; $i++ ) {
			$this->assertSame( '', $this->open( $this->signed(), self::NOW + 1 )['reason'] );
		}
		$burned = $this->db->rows[ Burns::OPTION ];

		$this->assertSame( Controller::REASON_RATE_LIMITED, $this->open( $this->signed(), self::NOW + 2 )['reason'] );
		$this->assertSame( Controller::REASON_RATE_LIMITED, $this->open( $this->signed( [ 'aud' => 'another-site' ] ), self::NOW + 3 )['reason'], 'no claim is read' );
		$this->assertSame( Controller::REASON_MALFORMED, $this->open( 'junk', self::NOW + 4 )['reason'], 'junk is refused for its own reason' );

		$this->assertSame( $burned, $this->db->rows[ Burns::OPTION ], 'no burn list touched' );
		$this->assertSame( [ Controller::REASON_RATE_LIMITED, Controller::REASON_MALFORMED ], array_column( Log::recent(), 'reason' ), 'a burst is logged once' );

		$this->assertSame(
			'',
			$this->open(
				$this->signed(
					[
						'iat' => self::NOW + 60,
						'exp' => self::NOW + 120,
					]
				),
				self::NOW + 60
			)['reason'],
			'the next minute counts afresh'
		);
	}

	public function test_the_key_set_is_fetched_at_most_once_per_few_minutes_whatever_is_sent(): void {
		for ( $i = 0; $i < 30; $i++ ) {
			$this->open( $this->signed( [], [ 'kid' => 'unknown-' . $i ] ) );
		}

		$this->assertSame( 1, $this->fetches );
		$this->assertArrayNotHasKey( Attempts::TRANSIENT, $this->transients );
	}

	public function test_a_head_from_a_link_previewer_does_not_count_against_the_limit(): void {
		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertSame( Controller::REASON_METHOD, $this->open( $this->signed(), self::NOW, 'HEAD' )['reason'] );
		}

		$this->assertArrayNotHasKey( Attempts::TRANSIENT, $this->transients, 'no attempt counted' );
		$this->assertSame( [], Log::recent(), 'and nothing logged' );
		$this->assertSame( '', $this->open( $this->signed() )['reason'], 'the GET that follows still gets in' );
	}

	public function test_the_log_never_holds_the_token(): void {
		Actions\expectDone( 'scanfully_login_refused' )->once()->with( Controller::REASON_USER );
		$token = $this->signed( [ 'wp_user' => 2 ] );

		$this->open( $token );

		$logged = (string) json_encode( $this->options[ Log::OPTION ] );
		$this->assertStringNotContainsString( $token, $logged );
		$this->assertStringNotContainsString( explode( '.', $token )[1], $logged );
		$this->assertSame(
			[
				[
					'time' => self::NOW,
					'reason' => Controller::REASON_USER,
				],
			],
			Log::recent()
		);
	}

	public function test_the_log_keeps_the_last_twenty(): void {
		for ( $i = 0; $i < 25; $i++ ) {
			Log::refused( 'reason-' . $i, self::NOW + $i );
		}

		$this->assertCount( Log::KEEP, Log::recent() );
		$this->assertSame( 'reason-24', Log::recent()[ Log::KEEP - 1 ]['reason'] );
	}

	public function test_a_network_signs_in_its_super_admins_only(): void {
		$this->network      = true;
		$this->super_admins = [ 'editor' ];

		$this->assertSame( Controller::REASON_USER, $this->open( $this->signed() )['reason'], 'a site administrator who is not a super admin' );
		$this->assertSame( $this->users[2], $this->open( $this->signed( [ 'wp_user' => 2 ] ) )['user'] );
	}

	public function test_a_login_token_works_for_nothing_else(): void {
		$token = $this->signed( [ 'scope' => 'wordpress:read wordpress:manage wordpress:login' ] );

		$this->assertFalse( SignedRequest::verify( $token, self::SITE, 'manage', 'wordpress:manage', self::NOW ), 'not as a manage poke' );
		$this->assertFalse( SignedRequest::verify( $token, self::SITE, 'sync', 'wordpress:read', self::NOW ), 'not as a sync' );
		$this->assertFalse( SignedRequest::verify( $token, self::SITE, 'scopes', 'wordpress:read', self::NOW ), 'not as a scopes poke' );
		$job = [
			'id'        => $this->login_claims()['jti'],
			'action'    => 'update_plugin',
			'params'    => [],
			'attempt'   => 0,
			'job_token' => $token,
		];
		$this->assertSame( JobToken::INVALID, JobToken::check( $job, self::SITE, self::NOW ), 'not as a job token' );
		$this->assertSame( JobToken::INVALID, JobToken::check( array_merge( $job, [ 'action' => 'login' ] ), self::SITE, self::NOW ), 'not even for a job named login' );
		$this->assertFalse( \Scanfully\Manage\Runner::knows( 'login' ), 'no job runs a login' );
	}

	public function test_every_answer_is_never_cached_and_never_passed_on(): void {
		$headers = Controller::headers();

		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
		$this->assertSame( 'no-referrer', $headers['Referrer-Policy'] );
		$this->assertStringContainsString( 'noindex', $headers['X-Robots-Tag'] );
	}

	public function test_the_refusal_page_is_plain_and_says_nothing_of_why(): void {
		$this->stubTranslationFunctions();
		$this->stubEscapeFunctions();
		Functions\when( 'get_bloginfo' )->justReturn( 'en-US' );
		Functions\when( 'home_url' )->justReturn( 'https://scanfully.com/' );
		Functions\when( 'esc_url' )->returnArg();

		$page = Controller::refusal_page();

		$this->assertStringContainsString( '<h1>This sign-in link is not valid</h1>', $page );
		$this->assertStringContainsString( '<a href="https://scanfully.com/">Go to the front page</a>', $page );
		$this->assertStringContainsString( '<meta name="referrer" content="no-referrer">', $page );
		$this->assertStringContainsString( '<script>try{history.replaceState(null,"",location.pathname)}catch(e){}</script>', $page, 'the token leaves the address bar and the history' );
		$this->assertSame( 1, substr_count( $page, '<script' ), 'one inline script, nothing loaded' );
		$this->assertStringNotContainsString( 'eyJ', $page, 'nothing of the token' );
		foreach ( [ 'expired', 'replayed', 'signature', 'administrator', 'scope' ] as $detail ) {
			$this->assertStringNotContainsString( $detail, $page );
		}
	}

	public function test_the_site_can_sign_in_over_https_with_the_scope(): void {
		$this->assertTrue( Controller::available() );

		$this->options['scanfully_connect_scopes'] = 'wordpress:read wordpress:manage';
		$this->assertFalse( Controller::available(), 'without wordpress:login' );

		$this->options['scanfully_connect_scopes'] = 'wordpress:read wordpress:login';
		Functions\when( 'wp_is_using_https' )->justReturn( false );
		$this->assertFalse( Controller::available(), 'a site served over plain HTTP' );
	}
}
