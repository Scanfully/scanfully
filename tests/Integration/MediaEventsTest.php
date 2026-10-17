<?php
/**
 * Media library event integration tests.
 *
 * Runs against real WordPress (wp-env) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Events\Controller as EventsController;
use Scanfully\Events\MediaChanges\Collector as MediaCollector;
use Scanfully\Events\PostChanges\Collector;
use Scanfully\Events\TermChanges\Collector as TermCollector;

/**
 * Uploads, edits and deletes files in real WordPress, one "request" at a
 * time, and checks the events the minute's job sends to the API.
 */
final class MediaEventsTest extends TestCase {

	/**
	 * Attachments and posts created by a test.
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

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/image-edit.php';

		update_option( 'scanfully_connect_is_connected', 'yes' );
		as_unschedule_all_actions( '', [], 'scanfully' );
		self::clear_minute_store();
		Collector::reset();
		TermCollector::reset();
		MediaCollector::reset();
		$this->sent = [];
		$this->user = get_current_user_id();
		wp_set_current_user( 1 );
		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );
		add_filter( 'scanfully_post_saved_events_per_minute', '__return_zero' );
		add_filter( 'scanfully_media_events_per_minute', '__return_zero' );
	}

	protected function tearDown(): void {
		if ( function_exists( 'wp_delete_post' ) ) {
			remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
			remove_all_filters( 'scanfully_post_saved_events_per_minute' );
			remove_all_filters( 'scanfully_media_events_per_minute' );
			foreach ( array_reverse( $this->posts ) as $id ) {
				wp_delete_post( $id, true );
			}
			Collector::reset();
			TermCollector::reset();
			MediaCollector::reset();
			self::clear_minute_store();
			as_unschedule_all_actions( '', [], 'scanfully' );
			delete_option( 'scanfully_connect_is_connected' );
			wp_set_current_user( $this->user );
			$_REQUEST = [];
		}
		parent::tearDown();
	}

	/**
	 * Remove what earlier runs left in the minute store, with the markers
	 * that say a minute's job is scheduled (the tests unschedule the jobs).
	 *
	 * @return void
	 */
	private static function clear_minute_store(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test cleanup.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'scanfully_minute_' ) . '%', $wpdb->esc_like( '_transient_scanfully_minute_' ) . '%' ) );
		foreach ( $names as $name ) {
			if ( 0 === strpos( $name, '_transient_' ) ) {
				delete_transient( substr( $name, strlen( '_transient_' ) ) );
			} else {
				delete_option( $name );
			}
		}
	}

	/**
	 * Wait until the minute has at least 15 seconds left, so the requests of
	 * a test land in one minute, as they would when someone works quickly.
	 *
	 * @return void
	 */
	private static function start_of_minute(): void {
		$second = (int) gmdate( 's' );
		if ( $second >= 45 ) {
			sleep( 61 - $second );
		}
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
	 * End the "request": what the collectors do at `shutdown`.
	 *
	 * @return void
	 */
	private function end_request(): void {
		Collector::flush();
		TermCollector::flush();
		MediaCollector::flush();
	}

	/**
	 * Forget the events of the setup of a test.
	 *
	 * @return void
	 */
	private function forget(): void {
		$this->end_request();
		self::clear_minute_store();
		as_unschedule_all_actions( '', [], 'scanfully' );
		$this->sent = [];
	}

	/**
	 * Run the pending event jobs, as Action Scheduler would, until none is
	 * left, and return the events sent: type and data.
	 *
	 * @return array<int, array{type: string, data: array<string, mixed>}>
	 */
	private function run_jobs(): array {
		$hooks = [ EventsController::ACTION_BUILD_POST_EVENT, EventsController::ACTION_BUILD_CONTENT_EVENT, EventsController::ACTION_SEND_EVENT, EventsController::ACTION_SEND_OVERFLOW, EventsController::ACTION_SEND_MEDIA ];
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

	/**
	 * A post files can belong to, made in an earlier "request".
	 *
	 * @param string $title The title.
	 *
	 * @return int
	 */
	private function make_post( string $title = 'About us' ): int {
		$id = wp_insert_post(
			[
				'post_title'  => $title,
				'post_type'   => 'page',
				'post_status' => 'publish',
			]
		);

		$this->posts[] = $id;

		return $id;
	}

	/**
	 * Upload a JPEG the way the media library does, to the post given.
	 *
	 * @param string $name      The file name.
	 * @param int    $parent_id The post it is uploaded to.
	 * @param int    $width     The width.
	 * @param int    $height    The height.
	 *
	 * @return int The attachment ID.
	 */
	private function upload( string $name, int $parent_id = 0, int $width = 640, int $height = 480 ): int {
		$tmp   = wp_tempnam( $name );
		$image = imagecreatetruecolor( $width, $height );
		imagefill( $image, 0, 0, (int) imagecolorallocate( $image, 30, 120, 200 ) );
		imagejpeg( $image, $tmp );
		imagedestroy( $image );

		$id = media_handle_sideload(
			[
				'name'     => $name,
				'tmp_name' => $tmp,
			],
			$parent_id
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		$this->posts[] = $id;

		return $id;
	}

	public function test_an_upload_is_one_row_with_the_file_as_it_is_a_minute_later(): void {
		self::start_of_minute();
		$page = $this->make_post();
		$this->forget();

		$id = $this->upload( 'team-photo.jpg', $page );
		$this->end_request();
		// The alt text, saved in its own request right after.
		update_post_meta( $id, '_wp_attachment_image_alt', 'The team' );
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'MediaUploaded' ], self::types( $events ) );
		$data = $events[0]['data'];
		$this->assertSame( $id, $data['id'] );
		$this->assertSame( 'team-photo.jpg', $data['name'] );
		$this->assertMatchesRegularExpression( '#^\d{4}/\d{2}/team-photo\.jpg$#', $data['file'] );
		$this->assertSame( 'team-photo', $data['title'] );
		$this->assertSame( 'image/jpeg', $data['mime_type'] );
		$this->assertSame( [ 640, 480 ], [ $data['width'], $data['height'] ] );
		$this->assertGreaterThan( 0, $data['filesize'] );
		$this->assertSame(
			[
				'id'         => $page,
				'title'      => 'About us',
				'type_label' => 'Page',
			],
			$data['parent']
		);
	}

	public function test_a_big_image_wordpress_scales_is_still_one_upload(): void {
		self::start_of_minute();

		// WordPress scales images over 2560 pixels; a lower threshold takes
		// the same path with less memory.
		$threshold = static fn(): int => 1000;
		add_filter( 'big_image_size_threshold', $threshold );

		$id = $this->upload( 'poster.jpg', 0, 1200, 800 );
		remove_filter( 'big_image_size_threshold', $threshold );
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'MediaUploaded' ], self::types( $events ) );
		$this->assertSame( 'poster-scaled.jpg', $events[0]['data']['name'] );
		$this->assertSame( $id, $events[0]['data']['id'] );
	}

	public function test_details_saved_field_by_field_are_one_change(): void {
		self::start_of_minute();
		$id    = $this->upload( 'photo.jpg' );
		$title = get_post( $id )->post_title;
		$this->forget();

		// The grid view saves each field in its own request.
		wp_update_post(
			[
				'ID'         => $id,
				'post_title' => 'Team photo',
			]
		);
		$this->end_request();
		update_post_meta( $id, '_wp_attachment_image_alt', 'The team & friends' );
		$this->end_request();
		wp_update_post(
			[
				'ID'           => $id,
				'post_excerpt' => 'Our team in 2026',
			]
		);
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'MediaChanged' ], self::types( $events ) );
		$this->assertSame(
			[
				'title'   => [
					'old' => $title,
					'new' => 'Team photo',
				],
				'alt'     => [
					'old' => '',
					'new' => 'The team & friends',
				],
				'caption' => [
					'old' => '',
					'new' => 'Our team in 2026',
				],
			],
			$events[0]['data']['changes']
		);
	}

	public function test_saving_the_details_unchanged_sends_nothing(): void {
		$id = $this->upload( 'photo.jpg' );
		$this->forget();

		wp_update_post(
			[
				'ID'         => $id,
				'post_title' => get_post( $id )->post_title,
			]
		);
		$this->end_request();

		$this->assertSame( [], $this->run_jobs() );
	}

	public function test_an_image_editor_save_and_restore(): void {
		self::start_of_minute();
		$id = $this->upload( 'photo.jpg' );
		$this->forget();

		$_REQUEST = [
			'action'  => 'image-editor',
			'do'      => 'save',
			'postid'  => (string) $id,
			'history' => '[{"r":90},{"f":1}]',
			'target'  => 'all',
			'context' => '',
		];
		MediaCollector::note_image_editor();
		$saved = wp_save_image( $id );
		$this->assertTrue( empty( $saved->error ), (string) ( $saved->error ?? '' ) );
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'MediaChanged' ], self::types( $events ) );
		$this->assertSame(
			[
				'image' => [
					'edits'  => [ 'rotate', 'flip' ],
					'target' => 'all',
				],
			],
			$events[0]['data']['changes'],
			'The editor\'s new file name is part of the edit, not a change of its own.'
		);

		$this->sent = [];
		$_REQUEST   = [
			'action' => 'image-editor',
			'do'     => 'restore',
			'postid' => (string) $id,
		];
		MediaCollector::note_image_editor();
		wp_restore_image( $id );
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'MediaChanged' ], self::types( $events ) );
		$this->assertSame( [ 'image' => [ 'restored' => true ] ], $events[0]['data']['changes'] );
	}

	public function test_the_block_editors_edited_copy_names_the_original(): void {
		self::start_of_minute();
		$id = $this->upload( 'photo.jpg' );
		$this->forget();

		$request = new \WP_REST_Request( 'POST', '/wp/v2/media/' . $id . '/edit' );
		$request->set_param( 'src', wp_get_attachment_image_url( $id, 'full' ) );
		$request->set_param(
			'modifiers',
			[
				[
					'type' => 'rotate',
					'args' => [ 'angle' => 90 ],
				],
			]
		);
		$response = rest_do_request( $request );
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$copy          = (int) $response->get_data()['id'];
		$this->posts[] = $copy;
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'MediaUploaded' ], self::types( $events ) );
		$this->assertSame( $copy, $events[0]['data']['id'] );
		$this->assertSame(
			[
				'id'   => $id,
				'name' => wp_basename( (string) get_attached_file( $id ) ),
			],
			$events[0]['data']['edited_from']
		);
		$this->assertSame( [ 'rotate' ], $events[0]['data']['edits'] );
	}

	public function test_a_file_swapped_by_the_browser_after_an_upload_is_no_change(): void {
		$id = $this->upload( 'photo.jpg' );
		$this->forget();

		// WordPress 7.0's client-side processing sends the scaled file later.
		$request = new \WP_REST_Request( 'POST', '/wp/v2/media/' . $id . '/sideload' );
		apply_filters( 'rest_request_before_callbacks', null, [], $request );
		update_attached_file( $id, str_replace( 'photo.jpg', 'photo-big.jpg', (string) get_attached_file( $id ) ) );
		apply_filters( 'rest_request_after_callbacks', null, [], $request );
		$this->end_request();

		$this->assertSame( [], $this->run_jobs() );
	}

	public function test_attaching_in_the_list_view(): void {
		self::start_of_minute();
		$page = $this->make_post( 'Contact' );
		$id   = $this->upload( 'map.jpg' );
		$this->forget();

		// What wp_media_attach_action() does before it redirects.
		global $wpdb;
		get_post( $id );
		$wpdb->update( $wpdb->posts, [ 'post_parent' => $page ], [ 'ID' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- As WordPress does it.
		do_action( 'wp_media_attach_action', 'attach', $id, $page );
		clean_attachment_cache( $id );
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'MediaChanged' ], self::types( $events ) );
		$this->assertSame(
			[
				'old' => [
					'id'    => 0,
					'title' => '',
				],
				'new' => [
					'id'         => $page,
					'title'      => 'Contact',
					'type_label' => 'Page',
				],
			],
			$events[0]['data']['changes']['parent']
		);
	}

	public function test_trash_and_restore(): void {
		self::start_of_minute();
		$id = $this->upload( 'photo.jpg' );
		$this->forget();

		wp_trash_post( $id );
		$this->end_request();
		$events = $this->run_jobs();
		$this->assertSame( [ 'MediaChanged' ], self::types( $events ) );
		$this->assertSame(
			[
				'status' => [
					'old' => 'inherit',
					'new' => 'trash',
				],
			],
			$events[0]['data']['changes']
		);

		$this->sent = [];
		wp_untrash_post( $id );
		$this->end_request();
		$events = $this->run_jobs();
		$this->assertSame( [ 'status' ], array_keys( $events[0]['data']['changes'] ?? [] ) );
		$this->assertSame( 'trash', $events[0]['data']['changes']['status']['old'] ?? null );
	}

	public function test_deleting_a_featured_image_says_how_many_posts_had_it(): void {
		self::start_of_minute();
		$id    = $this->upload( 'header.jpg' );
		$posts = [ $this->make_post( 'A' ), $this->make_post( 'B' ), $this->make_post( 'C' ) ];
		foreach ( $posts as $post ) {
			set_post_thumbnail( $post, $id );
		}
		$this->forget();

		wp_delete_attachment( $id, true );
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'MediaDeleted' ], self::types( $events ) );
		$data = $events[0]['data'];
		$this->assertSame( [ 'header.jpg', 'header', 'image/jpeg', 'inherit', 3 ], [ $data['name'], $data['title'], $data['mime_type'], $data['post_status'], $data['featured_on'] ] );
		$this->assertSame( [ 640, 480 ], [ $data['width'], $data['height'] ] );
		$this->assertArrayNotHasKey( 'expired', $data );
	}

	public function test_the_media_trash_wordpress_empties_itself_is_marked(): void {
		$id = $this->upload( 'old.jpg' );
		wp_trash_post( $id );
		update_post_meta( $id, '_wp_trash_meta_time', time() - 40 * DAY_IN_SECONDS );
		$this->forget();

		do_action( 'wp_scheduled_delete' );
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame( [ 'MediaDeleted' ], self::types( $events ) );
		$this->assertTrue( $events[0]['data']['expired'] );
		$this->assertSame( 'trash', $events[0]['data']['post_status'] );
	}

	public function test_a_grid_delete_of_twelve_files_one_request_each_is_one_row(): void {
		self::start_of_minute();
		$ids = [];
		for ( $i = 1; $i <= 12; $i++ ) {
			$ids[] = $this->upload( 'bulk-' . $i . '.jpg', 0, 64, 64 );
		}
		$this->forget();

		foreach ( $ids as $id ) {
			wp_delete_attachment( $id, true );
			$this->end_request();
		}
		$events = $this->run_jobs();

		$this->assertSame( [ 'MediaBulkAction' ], self::types( $events ) );
		$data = $events[0]['data'];
		$this->assertSame( [ 'deleted', 12 ], [ $data['action'], $data['count'] ] );
		$this->assertSame( $ids, array_column( $data['files'], 'id' ) );
		$this->assertSame( 'bulk-12.jpg', $data['files'][11]['name'] );
	}

	public function test_an_upload_deleted_within_the_minute_is_nothing(): void {
		self::start_of_minute();

		$id = $this->upload( 'oops.jpg' );
		$this->end_request();
		wp_delete_attachment( $id, true );
		$this->end_request();

		$this->assertSame( [], $this->run_jobs() );
	}

	public function test_a_replace_through_enable_media_replace(): void {
		// Enable Media Replace is not installed here; it fires this hook
		// once it replaced the file, under the same name by default.
		$id = $this->upload( 'logo.jpg' );
		$this->forget();
		do_action( 'enable-media-replace-upload-done', 'https://scanfully.com/wp-content/uploads/logo.jpg', 'https://scanfully.com/wp-content/uploads/logo.jpg', $id ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Enable Media Replace's hook.
		$this->end_request();
		$events = $this->run_jobs();

		$this->assertSame(
			[
				'old'      => 'logo.jpg',
				'new'      => 'logo.jpg',
				'replaced' => true,
			],
			$events[0]['data']['changes']['file'] ?? null
		);
	}
}
