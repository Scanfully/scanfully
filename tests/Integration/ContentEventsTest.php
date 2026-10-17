<?php
/**
 * Permanent delete, bulk action and term event integration tests.
 *
 * Runs against real WordPress (wp-env) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Events\Controller as EventsController;
use Scanfully\Events\PostChanges\Collector;
use Scanfully\Events\TermChanges\Collector as TermCollector;

/**
 * Deletes posts, runs bulk actions and edits terms in real WordPress, and
 * checks the events sent to the API.
 */
final class ContentEventsTest extends TestCase {

	/**
	 * Posts created by a test.
	 *
	 * @var array<int, int>
	 */
	private array $posts = [];

	/**
	 * Terms created by a test: term ID and taxonomy.
	 *
	 * @var array<int, array{0: int, 1: string}>
	 */
	private array $terms = [];

	/**
	 * Event bodies sent to the API.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * The signed-in user before the test.
	 *
	 * @var int
	 */
	private int $user = 0;

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'wp_insert_post' ) ) {
			$this->markTestSkipped( 'WordPress not available. Run this suite under wp-env.' );
		}

		update_option( 'scanfully_connect_is_connected', 'yes' );
		as_unschedule_all_actions( '', [], 'scanfully' );
		Collector::reset();
		TermCollector::reset();
		$this->sent = [];
		$this->user = get_current_user_id();
		wp_set_current_user( 1 );
		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );
		// No per-minute cap unless a test sets one: the suite changes more
		// posts a minute than a site does.
		add_filter( 'scanfully_post_saved_events_per_minute', '__return_zero' );
		add_filter( 'scanfully_term_events_per_minute', '__return_zero' );
	}

	protected function tearDown(): void {
		if ( function_exists( 'wp_delete_post' ) ) {
			remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
			remove_all_filters( 'scanfully_post_saved_events_per_minute' );
			remove_all_filters( 'scanfully_term_events_per_minute' );
			foreach ( array_reverse( $this->posts ) as $id ) {
				wp_delete_post( $id, true );
			}
			foreach ( $this->terms as [ $id, $taxonomy ] ) {
				wp_delete_term( $id, $taxonomy );
			}
			Collector::reset();
			TermCollector::reset();
			as_unschedule_all_actions( '', [], 'scanfully' );
			delete_option( 'scanfully_connect_is_connected' );
			wp_set_current_user( $this->user );
		}
		parent::tearDown();
	}

	/**
	 * `pre_http_request`: keep the event sent to the API and accept it.
	 *
	 * @param mixed  $preempt Whether to preempt the request.
	 * @param array  $args    The request arguments.
	 * @param string $url     The URL.
	 *
	 * @return mixed
	 */
	public function capture_request( $preempt, $args, $url ) {
		if ( false === strpos( (string) $url, '/timeline' ) ) {
			return $preempt;
		}

		$this->sent[] = json_decode( (string) ( $args['body'] ?? '' ), true );

		return [
			'headers'  => [],
			'body'     => '',
			'response' => [
				'code'    => 202,
				'message' => 'Accepted',
			],
			'cookies'  => [],
		];
	}

	/**
	 * Posts made for a test, in an earlier "request".
	 *
	 * @param int                  $count  How many.
	 * @param array<string, mixed> $fields Fields of each post.
	 *
	 * @return array<int, int>
	 */
	private function make_posts( int $count, array $fields = [] ): array {
		$ids = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$id            = wp_insert_post(
				array_merge(
					[
						'post_title'  => 'Bulk post ' . $i,
						'post_type'   => 'post',
						'post_status' => 'publish',
					],
					$fields
				)
			);
			$ids[]         = $id;
			$this->posts[] = $id;
		}

		$this->end_request();
		$this->sent = [];
		as_unschedule_all_actions( '', [], 'scanfully' );

		return $ids;
	}

	/**
	 * A term made for a test, in an earlier "request".
	 *
	 * @param string $name     The name.
	 * @param string $taxonomy The taxonomy.
	 * @param int    $parent_id The parent.
	 *
	 * @return int The term ID.
	 */
	private function make_term( string $name, string $taxonomy = 'category', int $parent_id = 0 ): int {
		$term = wp_insert_term( $name, $taxonomy, [ 'parent' => $parent_id ] );
		$this->assertIsArray( $term );
		$this->terms[] = [ (int) $term['term_id'], $taxonomy ];

		$this->end_request();
		$this->sent = [];
		as_unschedule_all_actions( '', [], 'scanfully' );

		return (int) $term['term_id'];
	}

	/**
	 * End the "request": report the changed posts, then the terms.
	 *
	 * @return void
	 */
	private function end_request(): void {
		Collector::flush();
		TermCollector::flush();
	}

	/**
	 * Run the pending event jobs, as Action Scheduler would, until none is
	 * left, and return the events sent: type and data.
	 *
	 * @return array<int, array{type: string, data: array<string, mixed>}>
	 */
	private function run_jobs(): array {
		$hooks = [ EventsController::ACTION_BUILD_POST_EVENT, EventsController::ACTION_BUILD_CONTENT_EVENT, EventsController::ACTION_SEND_EVENT, EventsController::ACTION_SEND_OVERFLOW ];
		for ( $round = 0; $round < 5; $round++ ) {
			$actions = as_get_scheduled_actions(
				[
					'hook__in' => $hooks,
					'group'    => 'scanfully',
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
					'orderby'  => 'none',
				]
			);
			if ( [] === $actions ) {
				break;
			}
			foreach ( $actions as $id => $action ) {
				\ActionScheduler::store()->mark_complete( $id );
				do_action_ref_array( $action->get_hook(), array_values( $action->get_args() ) );
			}
		}

		return array_map(
			static fn( array $body ): array => [
				'type' => (string) $body['type'],
				'data' => (array) $body['data'],
			],
			$this->sent
		);
	}

	/**
	 * The types of the events sent.
	 *
	 * @param array<int, array{type: string, data: array<string, mixed>}> $events The events.
	 *
	 * @return array<int, string>
	 */
	private static function types( array $events ): array {
		return array_column( $events, 'type' );
	}

	public function test_delete_permanently_reports_the_post(): void {
		[ $id ] = $this->make_posts( 1, [ 'post_title' => 'Old news' ] );
		wp_trash_post( $id );
		$this->end_request();
		$this->sent = [];
		as_unschedule_all_actions( '', [], 'scanfully' );

		wp_delete_post( $id, true );
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'PostDeleted' ], self::types( $events ) );
		$this->assertSame(
			[
				'id'          => $id,
				'title'       => 'Old news',
				'post_type'   => 'post',
				'type_label'  => 'Post',
				'post_status' => 'trash',
			],
			$events[0]['data']
		);
	}

	public function test_a_force_delete_of_a_published_post_says_it_was_published(): void {
		[ $id ] = $this->make_posts( 1 );

		wp_delete_post( $id, true );
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'PostDeleted' ], self::types( $events ) );
		$this->assertSame( 'publish', $events[0]['data']['post_status'] );
	}

	public function test_emptying_the_trash_is_one_row_with_every_post(): void {
		$ids = $this->make_posts( 12, [ 'post_status' => 'trash' ] );

		// What edit.php does for "Empty Trash".
		foreach ( $ids as $id ) {
			wp_delete_post( $id );
		}
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'PostBulkAction' ], self::types( $events ) );
		$data = $events[0]['data'];
		$this->assertSame( [ 'deleted', 'post', 'Posts', 12 ], [ $data['action'], $data['post_type'], $data['type_label'], $data['count'] ] );
		$this->assertSame( $ids, array_column( $data['posts'], 'id' ) );
		$this->assertSame( 'Bulk post 12', $data['posts'][11]['title'] );
	}

	public function test_the_trash_wordpress_empties_itself_is_marked(): void {
		$ids = $this->make_posts( 2 );
		foreach ( $ids as $id ) {
			wp_trash_post( $id );
			update_post_meta( $id, '_wp_trash_meta_time', time() - 40 * DAY_IN_SECONDS );
		}
		$this->end_request();
		$this->sent = [];
		as_unschedule_all_actions( '', [], 'scanfully' );

		do_action( 'wp_scheduled_delete' );
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'PostDeleted', 'PostDeleted' ], self::types( $events ) );
		$this->assertTrue( $events[0]['data']['expired'] );
		$this->assertSame( EMPTY_TRASH_DAYS, $events[0]['data']['trash_days'] );
	}

	public function test_a_bulk_trash_of_thirty_posts_is_one_row(): void {
		$ids = $this->make_posts( 30 );

		foreach ( $ids as $id ) {
			wp_trash_post( $id );
		}
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'PostBulkAction' ], self::types( $events ) );
		$this->assertSame( 'trashed', $events[0]['data']['action'] );
		$this->assertSame( 30, $events[0]['data']['count'] );
		$this->assertCount( 30, $events[0]['data']['posts'] );
	}

	public function test_a_bulk_edit_adding_a_category_says_which(): void {
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$ids      = $this->make_posts( 30 );
		$category = $this->make_term( 'Bulk news' );

		bulk_edit_posts(
			[
				'post_type'     => 'post',
				'post'          => $ids,
				'_status'       => '-1',
				'post_author'   => '-1',
				'post_category' => [ $category ],
			]
		);
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'PostBulkAction' ], self::types( $events ) );
		$data = $events[0]['data'];
		$this->assertSame( 'updated', $data['action'] );
		$this->assertSame( 30, $data['changed']['terms'] ?? null );
		$this->assertSame(
			[
				[
					'taxonomy' => 'category',
					'label'    => 'Categories',
					'added'    => [ 'Bulk news' ],
					// Bulk Edit takes the default category off posts it gives a category.
					'removed'  => [ 'Uncategorized' ],
				],
			],
			$data['terms'] ?? null
		);
	}

	public function test_a_few_posts_trashed_keep_rows_of_their_own(): void {
		$ids = $this->make_posts( 3 );

		foreach ( $ids as $id ) {
			wp_trash_post( $id );
		}
		$this->end_request();

		$this->assertSame( [ 'PostSaved', 'PostSaved', 'PostSaved' ], self::types( $this->run_jobs() ) );
	}

	public function test_saves_over_the_per_minute_cap_arrive_as_one_row(): void {
		$ids = $this->make_posts( 5 );
		remove_all_filters( 'scanfully_post_saved_events_per_minute' );
		delete_transient( 'scanfully_post_events_' . (int) floor( time() / MINUTE_IN_SECONDS ) );
		// An earlier run in this minute left the marker of a job setUp() unscheduled.
		delete_transient( 'scanfully_minute_overflow_post_' . (int) floor( time() / MINUTE_IN_SECONDS ) . '_job' );
		add_filter( 'scanfully_post_saved_events_per_minute', static fn() => 2 );

		// Five requests, each renaming one post.
		foreach ( $ids as $index => $id ) {
			wp_update_post(
				[
					'ID'         => $id,
					'post_title' => 'Renamed ' . $index,
				]
			);
			$this->end_request();
		}
		$events = $this->run_jobs();

		$this->assertSame( [ 'PostSaved', 'PostSaved', 'PostBulkAction' ], self::types( $events ) );
		$data = $events[2]['data'];
		$this->assertTrue( $data['overflow'] );
		$this->assertSame( 'updated', $data['action'] );
		$this->assertSame( array_slice( $ids, 2 ), array_column( $data['posts'], 'id' ) );
		$this->assertSame( [ 'title' => 3 ], $data['changed'] );
	}

	public function test_a_new_term_is_reported_with_its_parent(): void {
		$parent = $this->make_term( 'Company' );

		$term          = wp_insert_term( 'Team', 'category', [ 'parent' => $parent ] );
		$this->terms[] = [ (int) $term['term_id'], 'category' ];
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'TermCreated' ], self::types( $events ) );
		$this->assertSame(
			[
				'id'       => (int) $term['term_id'],
				'name'     => 'Team',
				'slug'     => 'team',
				'taxonomy' => 'category',
				'label'    => 'Category',
				'parent'   => [
					'id'   => $parent,
					'name' => 'Company',
				],
			],
			$events[0]['data']
		);
	}

	public function test_renaming_and_moving_a_term_reports_both(): void {
		$parent = $this->make_term( 'Company' );
		$term   = $this->make_term( 'Updates' );

		wp_update_term(
			$term,
			'category',
			[
				'name'        => 'News & updates',
				'slug'        => 'news',
				'parent'      => $parent,
				'description' => 'What is new.',
			]
		);
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'TermChanged' ], self::types( $events ) );
		$this->assertSame(
			[
				'name'        => [
					'old' => 'Updates',
					'new' => 'News & updates',
				],
				'slug'        => [
					'old' => 'updates',
					'new' => 'news',
				],
				'description' => [
					'old' => '',
					'new' => 'What is new.',
				],
				'parent'      => [
					'old' => [
						'id'   => 0,
						'name' => '',
					],
					'new' => [
						'id'   => $parent,
						'name' => 'Company',
					],
				],
			],
			$events[0]['data']['changes']
		);
	}

	public function test_saving_a_term_unchanged_sends_nothing(): void {
		$term = $this->make_term( 'Updates' );

		wp_update_term( $term, 'category', [ 'name' => 'Updates' ] );
		$this->end_request();

		$this->assertSame( [], $this->run_jobs() );
	}

	public function test_a_deleted_term_says_how_many_posts_had_it(): void {
		$term = $this->make_term( 'Old tag', 'post_tag' );
		$ids  = $this->make_posts( 3, [ 'tags_input' => [ 'Old tag' ] ] );
		$this->assertCount( 3, $ids );

		wp_delete_term( $term, 'post_tag' );
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'TermDeleted' ], self::types( $events ) );
		$this->assertSame( 'Old tag', $events[0]['data']['name'] );
		$this->assertSame( 3, $events[0]['data']['posts'] );
	}

	public function test_deleting_many_tags_is_one_row_with_every_tag(): void {
		$ids = [];
		for ( $i = 1; $i <= 12; $i++ ) {
			$ids[] = $this->make_term( 'Tag ' . $i, 'post_tag' );
		}

		foreach ( $ids as $id ) {
			wp_delete_term( $id, 'post_tag' );
		}
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'TermBulkAction' ], self::types( $events ) );
		$this->assertSame( [ 'deleted', 'post_tag', 'Tags', 12 ], [ $events[0]['data']['action'], $events[0]['data']['taxonomy'], $events[0]['data']['label'], $events[0]['data']['count'] ] );
		$this->assertSame( 'Tag 12', $events[0]['data']['terms'][11]['name'] );
	}

	public function test_a_tag_typed_while_saving_a_post_is_only_on_the_posts_row(): void {
		[ $id ] = $this->make_posts( 1 );

		wp_update_post(
			[
				'ID'         => $id,
				'tags_input' => [ 'Brand new tag' ],
			]
		);
		$new           = get_term_by( 'name', 'Brand new tag', 'post_tag' );
		$this->terms[] = [ (int) $new->term_id, 'post_tag' ];
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'PostSaved' ], self::types( $events ) );
		$this->assertSame( [ 'Brand new tag' ], $events[0]['data']['changes']['terms'][0]['added'] ?? null );
	}

	public function test_a_post_type_without_a_screen_is_not_reported_when_deleted(): void {
		register_post_type( 'scanfully_internal', [ 'show_ui' => false ] );
		$id = wp_insert_post(
			[
				'post_title'  => 'Internal',
				'post_type'   => 'scanfully_internal',
				'post_status' => 'publish',
			]
		);
		$this->end_request();
		$this->sent = [];
		as_unschedule_all_actions( '', [], 'scanfully' );

		wp_delete_post( $id, true );
		$this->end_request();

		$this->assertSame( [], $this->run_jobs() );
		unregister_post_type( 'scanfully_internal' );
	}
}
