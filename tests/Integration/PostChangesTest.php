<?php
/**
 * Post change integration tests.
 *
 * Runs against real WordPress (wp-env) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Events\Controller as EventsController;
use Scanfully\Events\PostChanges\Collector;

/**
 * Saves real posts and checks what the post events report as changed.
 */
final class PostChangesTest extends TestCase {

	/**
	 * Posts, terms and attachments created by a test.
	 *
	 * @var array<int, int>
	 */
	private array $posts = [];

	/**
	 * Event bodies sent to the API.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * The permalink structure before the test.
	 *
	 * @var string
	 */
	private string $permalinks = '';

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'wp_insert_post' ) ) {
			$this->markTestSkipped( 'WordPress not available. Run this suite under wp-env.' );
		}

		update_option( 'scanfully_connect_is_connected', 'yes' );
		as_unschedule_all_actions( '', [], 'scanfully' );
		Collector::reset();
		$this->sent = [];
		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );
		// No per-minute cap: the suite saves more posts a minute than a site does.
		add_filter( 'scanfully_post_saved_events_per_minute', '__return_zero' );

		global $wp_rewrite;
		$this->permalinks = (string) get_option( 'permalink_structure' );
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
	}

	protected function tearDown(): void {
		if ( function_exists( 'wp_delete_post' ) ) {
			remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
			remove_filter( 'wp_revisions_to_keep', '__return_zero' );
			remove_filter( 'scanfully_post_saved_events_per_minute', '__return_zero' );
			foreach ( array_reverse( $this->posts ) as $id ) {
				wp_delete_post( $id, true );
			}
			global $wp_rewrite;
			$wp_rewrite->set_permalink_structure( $this->permalinks );
			Collector::reset();
			as_unschedule_all_actions( '', [], 'scanfully' );
			delete_option( 'scanfully_connect_is_connected' );
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
	 * A published page with two paragraphs; its own "created" event is
	 * already sent and forgotten.
	 *
	 * @param array<string, mixed> $fields Fields that differ from the defaults.
	 *
	 * @return int
	 */
	private function publish( array $fields = [] ): int {
		$id            = wp_insert_post(
			array_merge(
				[
					'post_title'   => 'About',
					'post_name'    => 'about-scanfully-test',
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_content' => "<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Two</p>\n<!-- /wp:paragraph -->",
				],
				$fields
			)
		);
		$this->posts[] = $id;

		Collector::flush();
		as_unschedule_all_actions( '', [], 'scanfully' );

		return $id;
	}

	/**
	 * End the "request": report the changed posts.
	 *
	 * @return void
	 */
	private function end_request(): void {
		Collector::flush();
	}

	/**
	 * The arguments of the pending post event jobs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function jobs(): array {
		$actions = as_get_scheduled_actions(
			[
				'hook'     => EventsController::ACTION_BUILD_POST_EVENT,
				'group'    => 'scanfully',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			]
		);

		return array_values( array_map( static fn( $action ) => $action->get_args(), $actions ) );
	}

	/**
	 * Run the pending post event jobs and return the changes each sent.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function run_jobs(): array {
		foreach ( $this->jobs() as $args ) {
			do_action_ref_array( EventsController::ACTION_BUILD_POST_EVENT, array_values( $args ) );
		}

		return array_map( static fn( array $body ): array => $body['data']['changes'] ?? [], $this->sent );
	}

	public function test_a_save_that_changes_nothing_queues_nothing(): void {
		$id = $this->publish();

		wp_update_post( [ 'ID' => $id ] );
		$this->end_request();

		$this->assertSame( [], $this->jobs() );
	}

	public function test_a_new_post_queues_one_event_without_changes(): void {
		$this->posts[] = wp_insert_post(
			[
				'post_title'  => 'New',
				'post_status' => 'publish',
			]
		);
		$this->end_request();

		$this->assertCount( 1, $this->jobs() );
		$this->assertSame( [ [] ], $this->run_jobs() );
	}

	public function test_a_renamed_page_reports_its_title_slug_and_url(): void {
		$id = $this->publish();

		wp_update_post(
			[
				'ID'         => $id,
				'post_title' => 'About us',
				'post_name'  => 'about-us-scanfully-test',
			]
		);
		$this->end_request();
		$changes = $this->run_jobs()[0];

		$this->assertSame(
			[
				'old' => 'About',
				'new' => 'About us',
			],
			$changes['title']
		);
		$this->assertSame( 'about-us-scanfully-test', $changes['slug']['new'] );
		$this->assertStringEndsWith( '/about-scanfully-test/', $changes['url']['old'] );
		$this->assertStringEndsWith( '/about-us-scanfully-test/', $changes['url']['new'] );
	}

	public function test_a_content_change_is_sent_as_a_short_diff(): void {
		$id = $this->publish();

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => "<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Two and a half</p>\n<!-- /wp:paragraph -->",
			]
		);
		$this->end_request();
		$content = $this->run_jobs()[0]['content'];

		$this->assertSame( 1, $content['added'] );
		$this->assertSame( 1, $content['removed'] );
		$this->assertContains(
			[
				'op'   => '-',
				'text' => 'Two',
			],
			$content['lines']
		);
		$this->assertContains(
			[
				'op'   => '+',
				'text' => 'Two and a half',
			],
			$content['lines']
		);
	}

	public function test_a_later_edit_is_compared_with_the_revision_before_it(): void {
		$id = $this->publish();
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => '<p>One</p><p>Two</p><p>Three</p>',
			]
		);
		$this->end_request();
		as_unschedule_all_actions( '', [], 'scanfully' );

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => '<p>One</p><p>2</p><p>Three</p>',
			]
		);
		$this->end_request();
		$this->assertArrayHasKey( 'revisions', $this->jobs()[0]['data']['changes']['content'], 'Compared from revisions, nothing stored.' );
		$this->assertSame( '', $this->jobs()[0]['stash'] );

		$content = $this->run_jobs()[0]['content'];

		$this->assertSame(
			[
				[
					'op'   => '=',
					'text' => 'One',
				],
				[
					'op'   => '-',
					'text' => 'Two',
				],
				[
					'op'   => '+',
					'text' => '2',
				],
				[
					'op'   => '=',
					'text' => 'Three',
				],
			],
			$content['lines']
		);
	}

	public function test_content_is_compared_without_revisions_and_the_copy_is_removed(): void {
		add_filter( 'wp_revisions_to_keep', '__return_zero' );
		$id = $this->publish();

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => '<p>Something else</p>',
			]
		);
		$this->end_request();
		$stash = $this->jobs()[0]['stash'];
		$this->assertNotSame( '', $stash );

		$content = $this->run_jobs()[0]['content'];

		$this->assertSame( 1, $content['added'] );
		$this->assertSame( 2, $content['removed'] );
		$this->assertFalse( get_transient( 'scanfully_post_change_' . $stash ) );
	}

	public function test_custom_fields_and_the_featured_image_are_reported(): void {
		$id = $this->publish();
		update_post_meta( $id, 'subtitle', 'Old' );
		update_post_meta( $id, '_edit_last', '1' );
		$this->posts[] = $image = wp_insert_attachment( // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found -- Created and cleaned up.
			[
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Team',
				'post_status'    => 'inherit',
			],
			'team-photo.jpg'
		);
		Collector::reset();

		// What a save does: the fields first, then the post.
		update_post_meta( $id, 'subtitle', 'New' );
		update_post_meta( $id, '_edit_last', '2' );
		set_post_thumbnail( $id, $image );
		wp_update_post( [ 'ID' => $id ] );
		$this->end_request();
		$fields = array_column( $this->run_jobs()[0]['fields'], null, 'key' );

		$this->assertSame(
			[
				'key'    => 'subtitle',
				'change' => 'changed',
				'old'    => 'Old',
				'new'    => 'New',
			],
			$fields['subtitle']
		);
		$this->assertSame( 'added', $fields['_thumbnail_id']['change'] );
		$this->assertSame( 'team-photo.jpg', $fields['_thumbnail_id']['new'] );
		$this->assertArrayNotHasKey( '_edit_last', $fields );
	}

	public function test_categories_added_and_removed_are_reported_by_name(): void {
		$name    = 'News ' . wp_generate_password( 8, false );
		$news    = wp_insert_term( $name, 'category' );
		$news_id = (int) $news['term_id'];
		$id      = $this->publish( [ 'post_type' => 'post' ] );

		wp_update_post(
			[
				'ID'            => $id,
				'post_category' => [ $news_id ],
			]
		);
		$this->end_request();
		$terms = $this->run_jobs()[0]['terms'];
		wp_delete_term( $news_id, 'category' );

		$this->assertSame( 'category', $terms[0]['taxonomy'] );
		$this->assertSame( [ $name ], $terms[0]['added'] );
		$this->assertSame( [ get_cat_name( (int) get_option( 'default_category' ) ) ], $terms[0]['removed'] );
	}

	public function test_a_new_parent_is_reported_by_title(): void {
		$parent = $this->publish(
			[
				'post_title' => 'Company',
				'post_name'  => 'company-scanfully-test',
			]
		);
		$id     = $this->publish();

		wp_update_post(
			[
				'ID'          => $id,
				'post_parent' => $parent,
			]
		);
		$this->end_request();
		$changes = $this->run_jobs()[0];

		$this->assertSame(
			[
				'id'    => $parent,
				'title' => 'Company',
			],
			$changes['parent']['new']
		);
		$this->assertStringEndsWith( '/company-scanfully-test/about-scanfully-test/', $changes['url']['new'] );
	}

	public function test_two_quick_edits_are_both_reported(): void {
		$id = $this->publish();

		wp_update_post(
			[
				'ID'         => $id,
				'post_title' => 'First edit',
			]
		);
		$this->end_request();
		wp_update_post(
			[
				'ID'         => $id,
				'post_title' => 'Second edit',
			]
		);
		$this->end_request();

		$this->assertCount( 2, $this->jobs() );
	}

	public function test_the_password_itself_is_never_sent(): void {
		$id = $this->publish();

		wp_update_post(
			[
				'ID'            => $id,
				'post_password' => 'scanfully-secret',
			]
		);
		$this->end_request();
		$changes = $this->run_jobs()[0];

		$this->assertSame(
			[
				'old' => false,
				'new' => true,
			],
			$changes['password']
		);
		$this->assertStringNotContainsString( 'scanfully-secret', (string) wp_json_encode( $this->sent ) );
	}
}
