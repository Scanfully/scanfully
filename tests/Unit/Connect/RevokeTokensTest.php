<?php
/**
 * Token revoke unit tests.
 *
 * @package Scanfully\Tests\Unit\Connect
 */

namespace Scanfully\Tests\Unit\Connect;

use Brain\Monkey\Functions;
use Scanfully\Connect\Controller;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Connect\Controller::revoke_tokens
 * @covers \Scanfully\Connect\Controller::report_deactivated
 */
final class RevokeTokensTest extends TestCase {

	/**
	 * Stored options, keyed by option name.
	 *
	 * @var array<string, string>
	 */
	private array $options = [];

	/**
	 * The signed-in user.
	 *
	 * @var object
	 */
	private object $user;

	protected function setUp(): void {
		parent::setUp();
		$this->options = [];
		$this->user    = (object) [
			'ID'           => 7,
			'display_name' => 'Jane',
			'first_name'   => 'Jane',
			'last_name'    => 'Doe',
		];
		Functions\when( 'get_option' )->alias( fn( string $name ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : false );
		Functions\when( 'wp_get_current_user' )->alias( fn() => $this->user );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'is_email' )->alias( static fn( $value ) => false !== filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : false );
	}

	/**
	 * Expect one request to a connect route of site-123, with the body.
	 *
	 * @param string $route The connect route.
	 * @param array  $body  The body it must send.
	 *
	 * @return void
	 */
	private function expect_request( string $route, array $body ): void {
		Functions\expect( 'wp_remote_post' )
			->once()
			->with(
				'https://api.scanfully.com/v1/sites/site-123/connect/' . $route,
				\Mockery::on(
					static function ( array $args ) use ( $body ): bool {
						return 'Bearer access-abc' === $args['headers']['Authorization']
							&& 'application/json' === $args['headers']['Content-Type']
							&& json_decode( $args['body'], true ) === $body
							&& $args['timeout'] <= 5
							&& true === $args['sslverify'];
					}
				)
			)
			->andReturn( [ 'response' => [ 'code' => 204 ] ] );
	}

	public function test_it_revokes_the_tokens_of_a_connected_site(): void {
		$this->options = [
			'scanfully_connect_site_id'      => 'site-123',
			'scanfully_connect_access_token' => 'access-abc',
		];
		$this->expect_request(
			'revoke',
			[
				'reason' => 'disconnected',
				'by'     => 'Jane',
			]
		);

		Controller::revoke_tokens();
		$this->addToAssertionCount( 1 );
	}

	public function test_a_deactivation_is_reported_with_who_did_it(): void {
		$this->options = [
			'scanfully_connect_site_id'      => 'site-123',
			'scanfully_connect_access_token' => 'access-abc',
		];
		$this->expect_request( 'deactivated', [ 'by' => 'Jane' ] );

		Controller::report_deactivated();
		$this->addToAssertionCount( 1 );
	}

	public function test_no_email_address_leaves_the_site(): void {
		$this->options            = [
			'scanfully_connect_site_id'      => 'site-123',
			'scanfully_connect_access_token' => 'access-abc',
		];
		$this->user->display_name = 'jane@scanfully.com';
		$this->expect_request( 'deactivated', [ 'by' => 'Jane Doe' ] );

		Controller::report_deactivated();
		$this->addToAssertionCount( 1 );
	}

	public function test_a_deactivation_without_a_connection_sends_nothing(): void {
		Functions\expect( 'wp_remote_post' )->never();

		Controller::report_deactivated();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @dataProvider provide_incomplete_connections
	 *
	 * @param array<string, string> $options Stored options.
	 */
	public function test_nothing_is_sent_without_a_site_id_and_access_token( array $options ): void {
		$this->options = $options;

		Functions\expect( 'wp_remote_post' )->never();

		Controller::revoke_tokens();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @return array<string, array{array<string, string>}>
	 */
	public function provide_incomplete_connections(): array {
		return [
			'not connected'   => [ [] ],
			'no access token' => [ [ 'scanfully_connect_site_id' => 'site-123' ] ],
			'no site id'      => [ [ 'scanfully_connect_access_token' => 'access-abc' ] ],
		];
	}

	public function test_an_error_response_is_ignored(): void {
		$this->options = [
			'scanfully_connect_site_id'      => 'site-123',
			'scanfully_connect_access_token' => 'access-abc',
		];

		Functions\when( 'wp_remote_post' )->justReturn( [ 'response' => [ 'code' => 500 ] ] );

		Controller::revoke_tokens();
		$this->addToAssertionCount( 1 );
	}
}
