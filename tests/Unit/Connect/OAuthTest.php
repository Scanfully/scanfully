<?php
/**
 * OAuth client unit tests.
 *
 * @package Scanfully\Tests\Unit\Connect
 */

namespace Scanfully\Tests\Unit\Connect;

use Brain\Monkey\Functions;
use Scanfully\Connect\Controller;
use Scanfully\Connect\OAuth;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Connect\OAuth
 */
final class OAuthTest extends TestCase {

	/**
	 * In-memory transient store.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	/**
	 * The token requests sent, with their arguments.
	 *
	 * @var array<int, array{string, array<string, mixed>}>
	 */
	private array $posts = [];

	/**
	 * The next answer of the token endpoint: status and body.
	 *
	 * @var array{int, string}
	 */
	private array $answer = [ 200, '' ];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://wp.scanfully.com/wp-admin/' . $path );
		Functions\when( 'home_url' )->justReturn( 'https://wp.scanfully.com' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value, $url ) {
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value;
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'wp_generate_password' )->alias( static fn( $length ) => str_repeat( 'v', $length ) );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_post' )->alias(
			function ( string $url, array $args ) {
				$this->posts[] = [ $url, $args ];
				return $this->answer;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $response ) => $response[0] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $response ) => $response[1] );
	}

	public function test_a_connection_starts_on_scanfully_oauth_server(): void {
		$url = OAuth::authorize_url();
		$this->assertStringStartsWith( 'https://api.scanfully.com/oauth/authorize?', $url );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'wordpress-plugin', $query['client_id'] );
		$this->assertSame( 'code', $query['response_type'] );
		$this->assertSame( 'https://api.scanfully.com/v1/sites', $query['resource'] );
		$this->assertSame( 'wordpress:read', $query['scope'] );
		$this->assertSame( 'https://wp.scanfully.com', $query['site'] );
		$this->assertSame( OAuth::redirect_uri(), $query['redirect_uri'] );
		$this->assertStringStartsWith( 'https://wp.scanfully.com/wp-admin/', $query['redirect_uri'] );
		$this->assertStringContainsString( 'scanfully-oauth=1', $query['redirect_uri'] );
		$this->assertSame( 'S256', $query['code_challenge_method'] );
		$this->assertSame( Controller::code_challenge( Controller::get_code_verifier() ), $query['code_challenge'] );
		$this->assertSame( Controller::get_state(), $query['state'] );
	}

	public function test_a_code_is_exchanged_with_its_verifier_and_return_address(): void {
		$this->answer = [
			200,
			(string) json_encode(
				[
					'access_token' => 'sfy_sat_a',
					'refresh_token' => 'sfy_srt_r',
					'expires_in' => 2419200,
					'scope' => 'wordpress:read',
					'site_id' => 'site-1',
				]
			),
		];

		$tokens = OAuth::exchange_code( 'the-code', 'the-verifier' );

		[ $url, $args ] = $this->posts[0];
		$this->assertSame( 'https://api.scanfully.com/oauth/token', $url );
		$this->assertSame( 'application/x-www-form-urlencoded', $args['headers']['Content-Type'] );
		$this->assertSame( 'authorization_code', $args['body']['grant_type'] );
		$this->assertSame( 'the-verifier', $args['body']['code_verifier'] );
		$this->assertSame( OAuth::redirect_uri(), $args['body']['redirect_uri'] );
		$this->assertSame( 'site-1', $tokens['site_id'] );
		$this->assertSame( [ 'wordpress:read' ], $tokens['scopes'] );
		$this->assertEqualsWithDelta( time() + 2419200, strtotime( $tokens['expires'] ), 5 );
	}

	public function test_an_answer_without_tokens_or_expiry_is_nothing(): void {
		$this->assertSame(
			[],
			OAuth::normalize(
				[
					'access_token' => 'a',
					'refresh_token' => 'r',
				]
			)
		);
		$this->assertSame(
			[],
			OAuth::normalize(
				[
					'access_token' => 'a',
					'expires_in' => 60,
				]
			)
		);
		$this->assertSame(
			[],
			OAuth::normalize(
				[
					'access_token' => 'a',
					'refresh_token' => 'r',
					'expires_in' => 0,
				]
			)
		);
	}

	public function test_a_refresh_tells_an_api_without_the_plugin_client_from_a_failure(): void {
		$this->answer = [ 400, '{"error":"invalid_client"}' ];
		$this->assertNull( OAuth::refresh( 'r', 'site-1' ) );
		$this->answer = [ 404, '' ];
		$this->assertNull( OAuth::refresh( 'r', 'site-1' ) );
		$this->answer = [ 400, '{"error":"invalid_grant"}' ];
		$this->assertSame( [], OAuth::refresh( 'r', 'site-1' ) );
		$this->assertSame( 'site-1', $this->posts[2][1]['body']['site_id'] );
	}
}
