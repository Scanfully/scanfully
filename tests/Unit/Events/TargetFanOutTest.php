<?php
/**
 * Activity fan-out unit tests: events of a subsite with two targets.
 *
 * @package Scanfully\Tests\Unit\Events
 */

namespace Scanfully\Tests\Unit\Events;

use Brain\Monkey\Functions;
use RuntimeException;
use Scanfully\Events\ActivatedPlugin;
use Scanfully\Events\DeactivatedPlugin;
use Scanfully\Events\Content\Builder as ContentBuilder;
use Scanfully\Events\Controller;
use Scanfully\Tests\Unit\Connect\NetworkFixture;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\Event::listener_callback
 * @covers \Scanfully\Events\Event::schedule
 * @covers \Scanfully\Events\Controller::send_event
 * @covers \Scanfully\Events\Controller::deliver
 * @covers \Scanfully\Events\Controller::deliver_all
 * @covers \Scanfully\Events\Content\Builder::build
 * @covers \Scanfully\Events\ActivatedPlugin
 * @covers \Scanfully\API\Request
 */
final class TargetFanOutTest extends TestCase {

	use NetworkFixture;

	/**
	 * The requests sent: URL, headers and decoded body.
	 *
	 * @var array<int, array{url: string, headers: array<string, string>, body: mixed}>
	 */
	private array $sent = [];

	/**
	 * The API's answer by site id; 202 when not set.
	 *
	 * @var array<string, int>
	 */
	private array $answers = [];

	/**
	 * Transients.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_network();
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_post' )->alias(
			function ( string $url, array $args ): array {
				$this->sent[] = [
					'url'     => $url,
					'headers' => $args['headers'],
					'body'    => json_decode( $args['body'], true ),
				];
				preg_match( '#/sites/([^/]+)/#', $url, $match );

				return [ 'status' => $this->answer_for( $match[1] ) ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $response ): int => $response['status'] );
		Functions\when( 'get_transient' )->alias( fn( string $name ) => $this->transients[ $name ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $name, $value ): bool {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $name ): bool {
				unset( $this->transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) [
				'ID'           => 5,
				'display_name' => 'Ann',
			]
		);

		// Blog 2: monitored by the network, and connected on its own to another account.
		$this->monitor( [ 2 => 'child-2' ] );
		$this->connect_own( 2, 'own-2' );
		$this->on_blog( 2 );
	}

	protected function tearDown(): void {
		$this->tear_down_network();
		parent::tearDown();
	}

	/**
	 * The API's answer for a site.
	 *
	 * @param string $site The site id.
	 *
	 * @return int
	 */
	private function answer_for( string $site ): int {
		return $this->answers[ $site ] ?? 202;
	}

	/**
	 * A request sent.
	 *
	 * @param int $index Which one.
	 *
	 * @return array{url: string, headers: array<string, string>, body: mixed}
	 */
	private function request( int $index ): array {
		return $this->sent[ $index ];
	}

	/**
	 * A plugin activation event.
	 *
	 * @return ActivatedPlugin
	 */
	private static function event(): ActivatedPlugin {
		Functions\when( 'get_plugin_data' )->justReturn(
			[
				'Name'        => 'Shop',
				'Version'     => '1.0',
				'AuthorName'  => 'Shop Inc',
				'RequiresWP'  => '6.0',
				'RequiresPHP' => '8.0',
			]
		);

		return new ActivatedPlugin();
	}

	public function test_an_event_is_one_job_per_target_named_by_its_key(): void {
		self::event()->listener_callback( 'shop/shop.php', false );

		$jobs = $this->as_calls_of( Controller::ACTION_SEND_EVENT );
		$this->assertCount( 2, $jobs );
		$this->assertSame( [ 'own', 'network:child-2' ], array_column( array_column( $jobs, 3 ), 'target' ) );
		$this->assertSame( [ 2, 2 ], array_column( $jobs, 1 ) );
		$this->assertStringNotContainsString( 'token', (string) json_encode( $jobs ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- A check of the job arguments.
	}

	public function test_an_event_on_an_unmonitored_subsite_without_a_connection_schedules_nothing(): void {
		$this->on_blog( 3 );

		self::event()->listener_callback( 'shop/shop.php', false );

		$this->assertSame( [], $this->as_calls );
	}

	public function test_an_event_raised_on_another_site_of_the_network_goes_to_that_sites_targets(): void {
		$this->on_blog( 1 );
		switch_to_blog( 2 );

		self::event()->listener_callback( 'shop/shop.php', false );

		restore_current_blog();
		$jobs = $this->as_calls_of( Controller::ACTION_SEND_EVENT );
		$this->assertSame( [ 'own', 'network:child-2' ], array_column( array_column( $jobs, 3 ), 'target' ) );
		$this->assertSame( [ 2, 2 ], array_column( $jobs, 1 ) );

		// The jobs run in blog 2's queue: its own site and its child, never the network's site.
		$this->on_blog( 2 );
		foreach ( array_column( $jobs, 3 ) as $args ) {
			Controller::send_event( ...array_values( $args ) );
		}
		$this->assertSame(
			[ 'https://api.scanfully.com/v1/sites/own-2/timeline', 'https://api.scanfully.com/v1/sites/child-2/timeline' ],
			array_column( $this->sent, 'url' )
		);
	}

	public function test_the_main_sites_events_go_to_the_networks_site_only(): void {
		$this->on_blog( 1 );

		self::event()->listener_callback( 'shop/shop.php', true );
		foreach ( array_column( $this->as_calls_of( Controller::ACTION_SEND_EVENT ), 3 ) as $args ) {
			Controller::send_event( ...array_values( $args ) );
		}

		$this->assertSame( [ 'https://api.scanfully.com/v1/sites/net-site/timeline' ], array_column( $this->sent, 'url' ) );
		$this->assertTrue( $this->request( 0 )['body']['data']['network_wide'] );
	}

	public function test_a_build_job_sends_to_the_targets_the_site_had_when_it_was_queued(): void {
		\Scanfully\Events\ContentEvent::queue( 'Test', [ 'id' => 5 ], [ 'value' => 'x' ] );

		$jobs = $this->as_calls_of( Controller::ACTION_BUILD_CONTENT_EVENT );
		$this->assertCount( 1, $jobs );
		$this->assertSame( [ 'own', 'network:child-2' ], $jobs[0][3]['targets'] );
		$this->assertSame( 0, $jobs[0][3]['attempt'] );

		// Without a target, nothing is queued.
		$this->on_blog( 3 );
		\Scanfully\Events\ContentEvent::queue( 'Test', [ 'id' => 5 ], [ 'value' => 'x' ] );
		$this->assertCount( 1, $this->as_calls_of( Controller::ACTION_BUILD_CONTENT_EVENT ) );
	}

	public function test_the_child_gets_the_event_with_the_networks_token_and_the_blog_id(): void {
		Controller::send_event( 'Test', [ 'id' => 5 ], [ 'value' => 'x' ], 0, 'network:child-2' );
		Controller::send_event( 'Test', [ 'id' => 5 ], [ 'value' => 'x' ], 0, 'own' );

		$this->assertSame( 'https://api.scanfully.com/v1/sites/child-2/timeline', $this->sent[0]['url'] );
		$this->assertSame( 'Bearer net-token', $this->sent[0]['headers']['Authorization'] );
		$this->assertSame( '2', $this->sent[0]['headers']['X-Scanfully-Blog-Id'] );
		$this->assertSame(
			[
				'type' => 'Test',
				'user' => [ 'id' => 5 ],
				'data' => [ 'value' => 'x' ],
			],
			$this->sent[0]['body']
		);
		$this->assertSame( 'https://api.scanfully.com/v1/sites/own-2/timeline', $this->sent[1]['url'] );
		$this->assertSame( 'Bearer own-token-2', $this->sent[1]['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'X-Scanfully-Blog-Id', $this->sent[1]['headers'] );
	}

	public function test_a_job_for_a_child_that_is_no_longer_monitored_sends_nothing(): void {
		$this->site_options['scanfully_connect_children'] = [];

		Controller::send_event( 'Test', [ 'id' => 5 ], [ 'value' => 'x' ], 0, 'network:child-2' );

		$this->assertSame( [], $this->sent );
	}

	public function test_a_job_queued_before_targets_goes_to_the_own_connection(): void {
		Controller::send_event( 'Test', [ 'id' => 5 ], [ 'value' => 'x' ] );

		$this->assertCount( 1, $this->sent );
		$this->assertStringContainsString( '/sites/own-2/', $this->sent[0]['url'] );
	}

	public function test_a_failed_child_request_is_kept_apart_from_the_own_connection(): void {
		$this->answers['child-2'] = 503;

		try {
			Controller::send_event( 'Test', [ 'id' => 5 ], [ 'value' => 'x' ], 0, 'network:child-2' );
			$this->fail( 'A failure throws.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'HTTP 503', $e->getMessage() );
		}

		$this->assertSame( 503, $this->db->options[2]['scanfully_connect_last_request_error_network'] ? maybe_unserialize( $this->db->options[2]['scanfully_connect_last_request_error_network'] )['status'] : 0 );
		$this->assertArrayNotHasKey( 'scanfully_connect_last_request_error', $this->db->options[2] );
		$this->assertSame( [ [ 'Test', [ 'id' => 5 ], [ 'value' => 'x' ], 1, 'network:child-2' ] ], array_column( $this->as_calls_of( Controller::ACTION_SEND_EVENT ), 3 ) );
	}

	public function test_a_child_request_that_worked_says_nothing_about_the_own_connection(): void {
		Controller::send_event( 'Test', [ 'id' => 5 ], [ 'value' => 'x' ], 0, 'network:child-2' );

		$this->assertArrayNotHasKey( 'scanfully_connect_last_used', $this->db->options[2] );

		Controller::send_event( 'Test', [ 'id' => 5 ], [ 'value' => 'x' ], 0, 'own' );
		$this->assertArrayHasKey( 'scanfully_connect_last_used', $this->db->options[2] );
	}

	public function test_a_build_job_resolves_once_and_retries_only_the_target_that_failed(): void {
		$stash                                         = str_repeat( 'a', 32 );
		$this->transients[ 'scanfully_post_change_' . $stash ] = [ 'data' => [ 'value' => 'big' ] ];
		$this->answers['child-2']                      = 500;

		try {
			ContentBuilder::build( 'Test', [ 'id' => 5 ], [ 'stashed' => true ], $stash );
			$this->fail( 'A failure throws.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'HTTP 500', $e->getMessage() );
		}

		$this->assertCount( 2, $this->sent );
		$retries = $this->as_calls_of( Controller::ACTION_BUILD_CONTENT_EVENT );
		$this->assertSame( [ [ 'Test', [ 'id' => 5 ], [ 'stashed' => true ], $stash, 1, [ 'network:child-2' ] ] ], array_column( $retries, 3 ) );
		// The stash stays for the target that is left.
		$this->assertArrayHasKey( 'scanfully_post_change_' . $stash, $this->transients );

		$this->sent               = [];
		$this->answers['child-2'] = 202;
		ContentBuilder::build( 'Test', [ 'id' => 5 ], [ 'stashed' => true ], $stash, 1, [ 'network:child-2' ] );

		$this->assertCount( 1, $this->sent );
		$this->assertStringContainsString( '/sites/child-2/', $this->request( 0 )['url'] );
		$this->assertSame( [ 'value' => 'big' ], $this->request( 0 )['body']['data'] );
		$this->assertArrayNotHasKey( 'scanfully_post_change_' . $stash, $this->transients );
	}

	public function test_a_network_wide_activation_says_so(): void {
		$event = self::event();

		$this->assertTrue( $event->get_post_body( [ 'shop/shop.php', true ] )['network_wide'] );
		$this->assertFalse( $event->get_post_body( [ 'shop/shop.php', false ] )['network_wide'] );
		$this->assertFalse( $event->get_post_body( [ 'shop/shop.php' ] )['network_wide'] );
	}

	public function test_a_network_wide_deactivation_says_so(): void {
		self::event();
		$event = new DeactivatedPlugin();

		$this->assertTrue( $event->get_post_body( [ 'shop/shop.php', true ] )['network_wide'] );
		$this->assertFalse( $event->get_post_body( [ 'shop/shop.php', false ] )['network_wide'] );
		$this->assertFalse( $event->get_post_body( [ 'shop/shop.php' ] )['network_wide'] );
	}
}
