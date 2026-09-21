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
 */
final class RevokeTokensTest extends TestCase {

	/**
	 * Stored options, keyed by option name.
	 *
	 * @var array<string, string>
	 */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		$this->options = [];
		Functions\when( 'get_option' )->alias( fn( string $name ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : false );
	}

	public function test_it_revokes_the_tokens_of_a_connected_site(): void {
		$this->options = [
			'scanfully_connect_site_id'      => 'site-123',
			'scanfully_connect_access_token' => 'access-abc',
		];

		Functions\expect( 'wp_remote_post' )
			->once()
			->with(
				'https://api.scanfully.com/v1/sites/site-123/connect/revoke',
				\Mockery::on(
					static function ( array $args ): bool {
						return 'Bearer access-abc' === $args['headers']['Authorization']
							&& $args['timeout'] <= 5
							&& true === $args['sslverify'];
					}
				)
			)
			->andReturn( [ 'response' => [ 'code' => 204 ] ] );

		Controller::revoke_tokens();
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
