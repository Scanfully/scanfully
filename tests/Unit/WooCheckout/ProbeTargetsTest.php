<?php
/**
 * WooCheckout unit tests: a subsite with two targets.
 *
 * @package Scanfully\Tests\Unit\WooCheckout
 */

namespace Scanfully\Tests\Unit\WooCheckout;

use Brain\Monkey\Functions;
use ReflectionProperty;
use Scanfully\Tests\Unit\Connect\NetworkFixture;
use Scanfully\WooCheckout\Controller;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\WooCheckout\Controller::is_probe_request
 * @covers \Scanfully\WooCheckout\Controller::probe_secrets
 * @covers \Scanfully\WooCheckout\Controller::verified_probe_secret
 * @covers \Scanfully\WooCheckout\Controller::report
 */
final class ProbeTargetsTest extends TestCase {

	use NetworkFixture;

	private const OWN_SECRET   = 'own-secret';
	private const CHILD_SECRET = 'child-secret';

	/**
	 * The config requests sent: site id, blog header.
	 *
	 * @var array<int, array{site: string, blog: string}>
	 */
	private array $sent = [];

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_network();
		unset( $this->site_options['scanfully_connect_address'] );
		$this->reset_cache();
		$this->sent = [];
		unset( $_SERVER['HTTP_X_SCANFULLY_PROBE'], $_SERVER['REDIRECT_HTTP_X_SCANFULLY_PROBE'] );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$this->monitor( [ 2 => 'child-2' ] );
		$this->connect_own( 2, 'own-2' );
		$this->db->options[2][ Controller::OPTION_PROBE_SECRET ]              = self::OWN_SECRET;
		$this->db->options[2][ Controller::OPTION_PROBE_SECRET . '_network' ] = self::CHILD_SECRET;
		$this->on_blog( 2 );
	}

	protected function tearDown(): void {
		$this->reset_cache();
		unset( $_SERVER['HTTP_X_SCANFULLY_PROBE'] );
		$this->tear_down_network();
		parent::tearDown();
	}

	/**
	 * Clear the memoised probe result between checks.
	 */
	private function reset_cache(): void {
		$property = new ReflectionProperty( Controller::class, 'probe_request_cache' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/**
	 * A probe header signed with a secret.
	 *
	 * @param string $secret The secret.
	 *
	 * @return string
	 */
	private static function header( string $secret ): string {
		$scan_id = 'sf-' . time() . '-abcd';

		return $scan_id . ':' . hash_hmac( 'sha256', $scan_id, $secret );
	}

	public function test_a_probe_of_either_target_is_accepted_and_answered_with_its_secret(): void {
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = self::header( self::CHILD_SECRET );
		$this->assertTrue( Controller::is_probe_request() );
		$this->assertSame( self::CHILD_SECRET, Controller::verified_probe_secret() );

		$this->reset_cache();
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = self::header( self::OWN_SECRET );
		$this->assertTrue( Controller::is_probe_request() );
		$this->assertSame( self::OWN_SECRET, Controller::verified_probe_secret() );

		$this->reset_cache();
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = self::header( 'someone-else' );
		$this->assertFalse( Controller::is_probe_request() );
		$this->assertSame( '', Controller::verified_probe_secret() );
	}

	public function test_the_childs_secret_stops_working_when_the_subsite_is_no_longer_monitored(): void {
		unset( $this->db->options[2]['scanfully_network_child'] );
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = self::header( self::CHILD_SECRET );

		$this->assertFalse( Controller::is_probe_request() );
		$this->assertSame( [ self::OWN_SECRET ], Controller::probe_secrets() );
	}

	public function test_the_config_goes_to_each_target_and_each_keeps_its_own_secret(): void {
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_post' )->alias(
			function ( string $url, array $args ): array {
				preg_match( '#/sites/([^/]+)/#', $url, $match );
				$this->sent[] = [
					'site' => $match[1],
					'blog' => $args['headers']['X-Scanfully-Blog-Id'] ?? '',
				];

				return [
					'status' => 200,
					'body'   => json_encode( [ 'probe_secret' => str_repeat( 'own-2' === $match[1] ? 'a' : 'b', 64 ) ] ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The API's answer.
				];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $response ): int => $response['status'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( array $response ): string => $response['body'] );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );

		Controller::report();

		$this->assertSame(
			[
				[
					'site' => 'own-2',
					'blog' => '',
				],
				[
					'site' => 'child-2',
					'blog' => '2',
				],
			],
			$this->sent
		);
		$this->assertSame( str_repeat( 'a', 64 ), $this->db->options[2][ Controller::OPTION_PROBE_SECRET ] );
		$this->assertSame( str_repeat( 'b', 64 ), $this->db->options[2][ Controller::OPTION_PROBE_SECRET . '_network' ] );
	}
}
