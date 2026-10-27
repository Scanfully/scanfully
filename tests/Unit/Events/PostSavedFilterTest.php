<?php
/**
 * PostSaved filtering unit tests.
 *
 * @package Scanfully\Tests\Unit\Events
 */

namespace Scanfully\Tests\Unit\Events;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Scanfully\Events\PostSaved;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\PostSaved
 * @covers \Scanfully\Events\Event::listener_callback
 */
final class PostSavedFilterTest extends TestCase {

	/**
	 * In-memory transient store.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	/**
	 * Post types that have an admin screen.
	 *
	 * @var array<int, string>
	 */
	private array $ui_types = [ 'post', 'page', 'product', 'shop_order', 'seopress_404', 'event_magic_tickets' ];

	protected function setUp(): void {
		parent::setUp();
		$this->transients = [];

		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wc_get_order_types' )->justReturn( [ 'shop_order', 'shop_order_refund' ] );
		Functions\when( 'get_post_type_object' )->alias(
			fn( string $type ) => (object) [ 'show_ui' => in_array( $type, $this->ui_types, true ) ]
		);
		Functions\when( 'get_transient' )->alias( fn( string $key ) => array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
	}

	/**
	 * Whether saving a post with the given type and status would fire.
	 *
	 * @param string $type    Post type.
	 * @param string $status  Post status.
	 * @param int    $post_id Post ID.
	 *
	 * @return bool
	 */
	private function fires( string $type, string $status = 'publish', int $post_id = 1 ): bool {
		$post = (object) [
			'ID'          => $post_id,
			'post_type'   => $type,
			'post_status' => $status,
		];

		return ( new PostSaved() )->should_fire( [ $post_id, $post, true, null ] );
	}

	public function test_a_published_post_fires(): void {
		$this->assertTrue( $this->fires( 'post' ) );
	}

	/**
	 * @dataProvider provide_ignored_saves
	 *
	 * @param string $type   Post type.
	 * @param string $status Post status.
	 */
	public function test_background_saves_do_not_fire( string $type, string $status ): void {
		$this->assertFalse( $this->fires( $type, $status ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function provide_ignored_saves(): array {
		return [
			'HPOS placeholder for an order'      => [ 'shop_order_placehold', 'draft' ],
			'HPOS backup order with sync on'     => [ 'shop_order', 'draft' ],
			'trashed legacy order'               => [ 'shop_order', 'trash' ],
			'refund'                             => [ 'shop_order_refund', 'draft' ],
			'ACF field'                          => [ 'acf-field', 'publish' ],
			'oEmbed cache'                       => [ 'oembed_cache', 'publish' ],
			'SEOPress 404 record'                => [ 'seopress_404', 'publish' ],
			'SEOPress 404 record as a draft'     => [ 'seopress_404', 'draft' ],
			'trashed SEOPress 404 record'        => [ 'seopress_404', 'trash' ],
			'FooEvents ticket'                   => [ 'event_magic_tickets', 'publish' ],
			'trashed FooEvents ticket'           => [ 'event_magic_tickets', 'trash' ],
			'custom type without an admin screen' => [ 'my_internal_type', 'publish' ],
			'pending review status'              => [ 'post', 'pending' ],
		];
	}

	public function test_publishing_right_after_saving_a_draft_is_reported(): void {
		$this->assertTrue( $this->fires( 'post', 'draft', 5 ) );
		$this->assertTrue( $this->fires( 'post', 'publish', 5 ), 'The publish event must not be dropped as a duplicate of the draft save.' );
	}

	public function test_saves_seconds_apart_are_not_deduplicated_by_time(): void {
		// The Collector reports a post once per request and only when it
		// changed; there is no time window that drops a real change.
		$this->assertTrue( $this->fires( 'post', 'publish', 6 ) );
		$this->assertTrue( $this->fires( 'post', 'publish', 6 ) );
		$this->assertSame( [], array_filter( array_keys( $this->transients ), static fn( string $key ): bool => 0 === strpos( $key, 'scanfully_post_event_' ) ) );
	}

	/**
	 * @dataProvider provide_ajax_edits
	 *
	 * @param string $action The admin-ajax action.
	 */
	public function test_ajax_edits_are_reported( string $action ): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		$_POST['action'] = $action;

		$fires = $this->fires( 'post', 'publish', 7 );
		unset( $_POST['action'] );

		$this->assertTrue( $fires );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function provide_ajax_edits(): array {
		return [
			'Quick Edit'        => [ 'inline-save' ],
			'Elementor'         => [ 'elementor_ajax' ],
			'Beaver Builder'    => [ 'fl_builder_save' ],
		];
	}

	public function test_heartbeat_requests_are_not_reported(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		$_POST['action'] = 'heartbeat';

		$fires = $this->fires( 'post', 'publish', 8 );
		unset( $_POST['action'] );

		$this->assertFalse( $fires );
	}

	public function test_sites_can_exclude_saves_with_a_filter(): void {
		Filters\expectApplied( 'scanfully_post_saved_should_fire' )->andReturn( false );

		$this->assertFalse( $this->fires( 'post' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_imports_do_not_fire(): void {
		define( 'WP_IMPORTING', true );

		$this->assertFalse( $this->fires( 'post' ) );
	}

	public function test_nothing_is_queued_while_the_site_is_not_connected(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\expect( 'as_schedule_single_action' )->never();

		$post = (object) [
			'ID'          => 1,
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => 'Hello',
		];
		( new PostSaved() )->listener_callback( 1, $post, true, null );
	}
}
