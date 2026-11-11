<?php
/**
 * PHP error reporter unit tests: a site with two targets.
 *
 * @package Scanfully\Tests\Unit\Errors
 */

namespace Scanfully\Tests\Unit\Errors;

use Brain\Monkey\Functions;
use Scanfully\Errors\Monitor;
use Scanfully\Errors\Reporter;
use Scanfully\Errors\Store;
use Scanfully\Tests\Unit\Connect\NetworkFixture;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Errors\Reporter::send
 * @covers \Scanfully\Errors\Store::known_by_all
 * @covers \Scanfully\Errors\Store::sent
 * @covers \Scanfully\Errors\Store::save_sent
 * @covers \Scanfully\Errors\Monitor::should_run
 */
final class ReporterTargetsTest extends TestCase {

	use NetworkFixture;

	/**
	 * The store's rows.
	 *
	 * @var FakeStoreDb
	 */
	private FakeStoreDb $store;

	/**
	 * The requests sent: site id, blog header and body.
	 *
	 * @var array<int, array{site: string, blog: string, body: mixed}>
	 */
	private array $sent = [];

	/**
	 * The API's answer by site id; 202 when not set.
	 *
	 * @var array<string, int>
	 */
	private array $answers = [];

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_network();
		// The network's address is not checked here (it needs the main site's options table).
		unset( $this->site_options['scanfully_connect_address'] );
		$this->store     = new FakeStoreDb();
		$GLOBALS['wpdb'] = $this->store;
		$this->sent      = [];
		$this->answers   = [];
		Functions\when( 'wp_json_encode' )->alias( static fn( $data, $flags = 0 ) => json_encode( $data, $flags ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_post' )->alias(
			function ( string $url, array $args ): array {
				preg_match( '#/sites/([^/]+)/#', $url, $match );
				$this->sent[] = [
					'site' => $match[1],
					'blog' => $args['headers']['X-Scanfully-Blog-Id'] ?? '',
					'body' => json_decode( $args['body'], true ),
				];

				return [ 'status' => $this->answers[ $match[1] ] ?? 202 ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $response ): int => $response['status'] );
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	protected function tearDown(): void {
		$this->tear_down_network();
		parent::tearDown();
	}

	/**
	 * Store an error as Monitor does: its count, last time and sample.
	 *
	 * @param string $fingerprint 40 hex characters.
	 * @param int    $count       How often.
	 *
	 * @return string The key.
	 */
	private function store_error( string $fingerprint, int $count ): string {
		$key = $fingerprint . '_' . substr( md5( '1.0' ), 0, 8 );
		$this->store->rows[ Store::PREFIX . $key . '_n' ] = (string) ( (int) ( $this->store->rows[ Store::PREFIX . $key . '_n' ] ?? 0 ) + $count );
		$this->store->rows[ Store::PREFIX . $key . '_l' ] = (string) time();
		$this->store->rows[ Store::PREFIX . $key . '_s' ] = (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- As the store keeps it.
			[
				'fingerprint' => $fingerprint,
				'level'       => 'warning',
				'source'      => [
					'kind'    => 'plugin',
					'slug'    => 'shop',
					'version' => '1.0',
				],
				'message'     => 'Undefined array key',
				'first_seen'  => time() - 60,
			]
		);

		return $key;
	}

	/**
	 * The counts each site got, by site id.
	 *
	 * @return array<string, int[]>
	 */
	private function counts_sent(): array {
		$out = [];
		foreach ( $this->sent as $request ) {
			$out[ $request['site'] ] = array_column( $request['body']['errors'], 'count' );
		}

		return $out;
	}

	public function test_a_monitored_subsite_catches_errors_and_a_plain_one_does_not(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->on_blog( 2 );
		$this->assertTrue( Monitor::should_run() );

		$this->on_blog( 3 );
		$this->assertFalse( Monitor::should_run() );

		// A copied marker of another blog.
		$this->db->options[3]['scanfully_network_child'] = $this->db->options[2]['scanfully_network_child'];
		$this->assertFalse( Monitor::should_run() );
	}

	public function test_the_main_site_catches_errors_under_the_network_connection(): void {
		$this->on_blog( 1 );
		$this->assertArrayNotHasKey( 'scanfully_connect_is_connected', $this->db->options[1] );

		$this->assertTrue( Monitor::should_run() );

		$this->site_options['scanfully_connect_is_connected'] = 'no';
		$this->assertFalse( Monitor::should_run() );
	}

	public function test_both_targets_get_the_errors_and_the_store_gives_them_up(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->connect_own( 2, 'own-2' );
		$this->on_blog( 2 );
		$this->store_error( str_repeat( 'a', 40 ), 3 );

		Reporter::send();

		$this->assertSame(
			[
				'own-2'   => [ 3 ],
				'child-2' => [ 3 ],
			],
			$this->counts_sent()
		);
		$this->assertSame( '2', $this->sent[1]['blog'] );
		$this->assertSame( [], array_filter( array_keys( $this->store->rows ), static fn( string $name ): bool => 0 === strpos( $name, Store::PREFIX ) && false === strpos( $name, '_lock_' ) ) );
		$this->assertArrayNotHasKey( 'scanfully_php_errors_sent', $this->db->options[2] );
		$this->assertArrayNotHasKey( 'scanfully_php_errors_sent_network', $this->db->options[2] );
		$this->assertArrayHasKey( 'scanfully_php_errors_known', $this->db->options[2] );
		$this->assertArrayHasKey( 'scanfully_php_errors_known_network', $this->db->options[2] );
	}

	public function test_a_target_that_failed_gets_its_share_later_and_the_other_no_count_twice(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->connect_own( 2, 'own-2' );
		$this->on_blog( 2 );
		$key                      = $this->store_error( str_repeat( 'b', 40 ), 3 );
		$this->answers['child-2'] = 503;

		Reporter::send();

		// The store keeps the count for the child; the own connection's share is marked.
		$this->assertSame( '3', $this->store->rows[ Store::PREFIX . $key . '_n' ] );
		$this->assertSame( [ $key => 3 ], maybe_unserialize( $this->db->options[2]['scanfully_php_errors_sent'] ) );

		// Two more occurrences, and the child answers again.
		$this->store_error( str_repeat( 'b', 40 ), 2 );
		$this->sent               = [];
		$this->answers['child-2'] = 202;
		Reporter::send();

		$this->assertSame(
			[
				'own-2'   => [ 2 ],
				'child-2' => [ 5 ],
			],
			$this->counts_sent()
		);
		$this->assertArrayNotHasKey( Store::PREFIX . $key . '_n', $this->store->rows );
		$this->assertArrayNotHasKey( 'scanfully_php_errors_sent', $this->db->options[2] );
	}

	public function test_a_single_site_sends_and_marks_nothing_extra(): void {
		$this->multisite = false;
		$this->connect_own( 1, 'single' );
		$this->on_blog( 1 );
		$key = $this->store_error( str_repeat( 'c', 40 ), 4 );

		Reporter::send();

		$this->assertSame( [ 'single' => [ 4 ] ], $this->counts_sent() );
		$this->assertSame( '', $this->sent[0]['blog'] );
		$this->assertArrayNotHasKey( Store::PREFIX . $key . '_n', $this->store->rows );
		$this->assertArrayNotHasKey( 'scanfully_php_errors_sent', $this->db->options[1] );
	}

	public function test_without_a_target_nothing_is_sent_or_dropped(): void {
		$this->on_blog( 3 );
		$key = $this->store_error( str_repeat( 'd', 40 ), 1 );

		Reporter::send();

		$this->assertSame( [], $this->sent );
		$this->assertArrayHasKey( Store::PREFIX . $key . '_n', $this->store->rows );
	}

	public function test_an_error_is_known_only_once_every_target_knows_it(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->connect_own( 2, 'own-2' );
		$this->on_blog( 2 );
		$targets = \Scanfully\Connect\Targets::now();
		Store::mark_known( [ 'k1', 'k2' ], 100, $targets[0] );
		Store::mark_known( [ 'k2' ], 90, $targets[1] );

		$this->assertSame( [ 'k2' => 90 ], Store::known_by_all( $targets ) );
	}
}
