<?php
/**
 * Media batch unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\MediaChanges
 */

namespace Scanfully\Tests\Unit\Events\MediaChanges;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Scanfully\Events\Content\MinuteStore;
use Scanfully\Events\Controller;
use Scanfully\Events\MediaChanges\Batch;
use Scanfully\Tests\Unit\Events\Content\FakeOptionsTable;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\MediaChanges\Batch
 * @covers \Scanfully\Events\MediaChanges\Details
 * @covers \Scanfully\Events\Content\Summary
 */
final class BatchTest extends TestCase {

	/**
	 * The options table the notes are stored in.
	 *
	 * @var FakeOptionsTable
	 */
	private FakeOptionsTable $table;

	/**
	 * Posts by ID: attachments and the posts they belong to.
	 *
	 * @var array<int, \WP_Post>
	 */
	private array $posts = [];

	/**
	 * Files of the attachments, by ID.
	 *
	 * @var array<int, string>
	 */
	private array $files = [];

	/**
	 * Events queued: type, user and data.
	 *
	 * @var array<int, array{type: string, user: array<string, mixed>, data: array<string, mixed>}>
	 */
	private array $queued = [];

	/**
	 * Keys made so far.
	 *
	 * @var int
	 */
	private int $uuids = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->table = FakeOptionsTable::install();
		Functions\when( 'wp_generate_uuid4' )->alias( fn() => sprintf( '%08x-0000-4000-8000-000000000000', ++$this->uuids ) );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args ) {
				if ( Controller::ACTION_BUILD_CONTENT_EVENT === $hook ) {
					$this->queued[] = [
						'type' => $args['type'],
						'user' => $args['user'],
						'data' => $args['data'],
					];
				}
				return 1;
			}
		);
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'get_post' )->alias( fn( $id ) => $this->posts[ $id ] ?? null );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => '_wp_attached_file' === $key ? ( $this->files[ $id ] ?? '' ) : '' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			[
				'width'    => 800,
				'height'   => 600,
				'filesize' => 5000,
			]
		);
		Functions\when( 'wp_get_post_parent_id' )->alias( fn( $id ) => isset( $this->posts[ $id ] ) ? $this->posts[ $id ]->post_parent : false );
		Functions\when( 'get_post_type_object' )->justReturn( (object) [ 'labels' => (object) [ 'singular_name' => 'Page' ] ] );
		Functions\when( 'wp_basename' )->alias( static fn( $path ) => basename( (string) $path ) );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress is not loaded in unit tests.

		$this->page( 4, 'About us' );
	}

	/**
	 * A page files can belong to.
	 *
	 * @param int    $id    The ID.
	 * @param string $title The title.
	 *
	 * @return void
	 */
	private function page( int $id, string $title ): void {
		$this->posts[ $id ] = new \WP_Post(
			(object) [
				'ID'          => $id,
				'post_type'   => 'page',
				'post_title'  => $title,
				'post_parent' => 0,
			]
		);
	}

	/**
	 * A file in the media library.
	 *
	 * @param int    $id     The ID.
	 * @param string $file   The file in the uploads folder.
	 * @param int    $parent_id The post it is attached to.
	 *
	 * @return void
	 */
	private function attachment( int $id, string $file, int $parent_id = 0 ): void {
		$this->posts[ $id ] = new \WP_Post(
			(object) [
				'ID'             => $id,
				'post_type'      => 'attachment',
				'post_title'     => pathinfo( $file, PATHINFO_FILENAME ),
				'post_mime_type' => 'image/jpeg',
				'post_parent'    => $parent_id,
				'guid'           => 'https://scanfully.com/wp-content/uploads/' . $file,
			]
		);
		$this->files[ $id ] = $file;
	}

	/**
	 * Store a request's notes for this minute.
	 *
	 * @param array<int, array<string, mixed>> $items The notes.
	 * @param int                              $user  The user ID.
	 *
	 * @return void
	 */
	private function request( array $items, int $user = 3 ): void {
		MinuteStore::add(
			'media',
			[
				'user'  => [
					'id'   => $user,
					'name' => 'User ' . $user,
				],
				'items' => $items,
			],
			Controller::ACTION_SEND_MEDIA
		);
	}

	/**
	 * Run the minute's job and return the events it queued.
	 *
	 * @return array<int, array{type: string, user: array<string, mixed>, data: array<string, mixed>}>
	 */
	private function run_job(): array {
		Batch::send( MinuteStore::bucket() );

		return $this->queued;
	}

	public function test_an_upload_is_reported_with_the_file_as_it_is_now(): void {
		$this->attachment( 7, '2026/10/team-photo.jpg', 4 );
		$this->request(
			[
				[
					'id'       => 7,
					'uploaded' => true,
					'file'     => '2026/10/team-photo.jpg',
				],
			]
		);
		$this->request(
			[
				[
					'id'  => 7,
					'old' => [ 'alt' => '' ],
					'new' => [ 'alt' => 'The team' ],
				],
			]
		);

		$events = $this->run_job();

		$this->assertSame( [ 'MediaUploaded' ], array_column( $events, 'type' ) );
		$this->assertSame(
			[
				'id'        => 7,
				'name'      => 'team-photo.jpg',
				'file'      => '2026/10/team-photo.jpg',
				'title'     => 'team-photo',
				'mime_type' => 'image/jpeg',
				'filesize'  => 5000,
				'width'     => 800,
				'height'    => 600,
				'parent'    => [
					'id'         => 4,
					'title'      => 'About us',
					'type_label' => 'Page',
				],
			],
			$events[0]['data']
		);
		$this->assertSame( 3, $events[0]['user']['id'] );
		$this->assertSame( [], $this->table->rows );
	}

	public function test_an_edited_copy_names_the_original(): void {
		$this->attachment( 7, '2026/10/photo.jpg' );
		$this->attachment( 8, '2026/10/photo-edited.jpg' );
		$this->request(
			[
				[
					'id'          => 8,
					'uploaded'    => true,
					'edited_from' => 7,
					'edits'       => [ 'crop' ],
				],
			]
		);

		$data = $this->run_job()[0]['data'];

		$this->assertSame(
			[
				'id'   => 7,
				'name' => 'photo.jpg',
			],
			$data['edited_from']
		);
		$this->assertSame( [ 'crop' ], $data['edits'] );
	}

	public function test_details_saved_one_by_one_are_one_change(): void {
		$this->attachment( 7, '2026/10/photo.jpg' );
		$this->request(
			[
				[
					'id'  => 7,
					'old' => [ 'title' => 'photo' ],
					'new' => [ 'title' => 'Photo' ],
				],
			]
		);
		$this->request(
			[
				[
					'id'  => 7,
					'old' => [
						'title' => 'Photo',
						'alt'   => '',
					],
					'new' => [
						'title' => 'Team photo',
						'alt'   => 'The team',
					],
				],
			]
		);

		$events = $this->run_job();

		$this->assertSame( [ 'MediaChanged' ], array_column( $events, 'type' ) );
		$this->assertSame(
			[
				'title' => [
					'old' => 'photo',
					'new' => 'Team photo',
				],
				'alt'   => [
					'old' => '',
					'new' => 'The team',
				],
			],
			$events[0]['data']['changes']
		);
	}

	public function test_a_change_undone_within_the_minute_is_nothing(): void {
		$this->attachment( 7, '2026/10/photo.jpg' );
		$this->request(
			[
				[
					'id'  => 7,
					'old' => [ 'title' => 'photo' ],
					'new' => [ 'title' => 'Oops' ],
				],
			]
		);
		$this->request(
			[
				[
					'id'  => 7,
					'old' => [ 'title' => 'Oops' ],
					'new' => [ 'title' => 'photo' ],
				],
			]
		);

		$this->assertSame( [], $this->run_job() );
	}

	public function test_uploaded_and_deleted_within_the_minute_is_nothing(): void {
		$this->request(
			[
				[
					'id'       => 7,
					'uploaded' => true,
				],
			]
		);
		$this->request(
			[
				[
					'id'      => 7,
					'deleted' => [ 'file' => '2026/10/photo.jpg' ],
				],
			]
		);

		$this->assertSame( [], $this->run_job() );
	}

	public function test_a_delete_after_changes_is_only_the_delete(): void {
		$this->request(
			[
				[
					'id'  => 7,
					'old' => [ 'title' => 'a' ],
					'new' => [ 'title' => 'b' ],
				],
			]
		);
		$this->request(
			[
				[
					'id'      => 7,
					'deleted' => [
						'title'       => 'b',
						'file'        => '2026/10/photo.jpg',
						'mime_type'   => 'image/jpeg',
						'post_status' => 'trash',
						'facts'       => [ 'filesize' => 5000 ],
						'featured_on' => 2,
						'expired'     => true,
					],
				],
			]
		);

		$events = $this->run_job();

		$this->assertSame( [ 'MediaDeleted' ], array_column( $events, 'type' ) );
		$this->assertSame(
			[
				'id'          => 7,
				'name'        => 'photo.jpg',
				'file'        => '2026/10/photo.jpg',
				'title'       => 'b',
				'mime_type'   => 'image/jpeg',
				'filesize'    => 5000,
				'post_status' => 'trash',
				'featured_on' => 2,
				'expired'     => true,
				'trash_days'  => 30,
			],
			$events[0]['data']
		);
	}

	public function test_attach_trash_and_image_edits_get_their_own_actions_and_data(): void {
		$this->attachment( 7, '2026/10/a.jpg', 4 );
		$this->attachment( 8, '2026/10/b.jpg' );
		$this->attachment( 9, '2026/10/c.jpg' );
		$this->request(
			[
				[
					'id'  => 7,
					'old' => [ 'parent' => 0 ],
					'new' => [ 'parent' => 4 ],
				],
				[
					'id'  => 8,
					'old' => [ 'status' => 'inherit' ],
					'new' => [ 'status' => 'trash' ],
				],
				[
					'id'    => 9,
					'image' => [
						'edits'  => [ 'crop' ],
						'target' => 'all',
					],
				],
			]
		);
		$this->request(
			[
				[
					'id'    => 9,
					'image' => [
						'edits'  => [ 'rotate' ],
						'target' => 'thumbnail',
					],
				],
			]
		);

		$events = $this->run_job();

		$this->assertSame( [ 'MediaChanged', 'MediaChanged', 'MediaChanged' ], array_column( $events, 'type' ) );
		$this->assertSame(
			[
				'old' => [
					'id'    => 0,
					'title' => '',
				],
				'new' => [
					'id'         => 4,
					'title'      => 'About us',
					'type_label' => 'Page',
				],
			],
			$events[0]['data']['changes']['parent']
		);
		$this->assertSame(
			[
				'old' => 'inherit',
				'new' => 'trash',
			],
			$events[1]['data']['changes']['status']
		);
		$this->assertSame(
			[
				'edits'  => [ 'crop', 'rotate' ],
				'target' => 'thumbnail',
			],
			$events[2]['data']['changes']['image']
		);
	}

	public function test_a_rename_and_a_replace_say_what_happened_to_the_file(): void {
		$this->attachment( 7, '2026/10/a.webp' );
		$this->attachment( 8, '2026/10/logo.png' );
		$this->request(
			[
				[
					'id'  => 7,
					'old' => [ 'file' => '2026/10/a.png' ],
					'new' => [ 'file' => '2026/10/a.webp' ],
				],
				[
					'id'       => 8,
					'replaced' => [
						'old' => 'logo.png',
						'new' => 'logo.png',
					],
				],
			]
		);

		$events = $this->run_job();

		$this->assertSame(
			[
				'old' => 'a.png',
				'new' => 'a.webp',
			],
			$events[0]['data']['changes']['file']
		);
		$this->assertSame(
			[
				'old'      => 'logo.png',
				'new'      => 'logo.png',
				'replaced' => true,
			],
			$events[1]['data']['changes']['file']
		);
	}

	public function test_many_uploads_by_one_person_are_one_row_with_every_file(): void {
		$items = [];
		for ( $id = 101; $id <= 112; $id++ ) {
			$this->attachment( $id, '2026/10/photo-' . $id . '.jpg' );
			$items[] = [
				'id'       => $id,
				'uploaded' => true,
			];
		}
		// One request per file, as the media library uploads.
		foreach ( $items as $item ) {
			$this->request( [ $item ] );
		}
		// Someone else's upload in the same minute keeps its own row.
		$this->attachment( 200, '2026/10/logo.png' );
		$this->request(
			[
				[
					'id'       => 200,
					'uploaded' => true,
				],
			],
			5
		);

		$events = $this->run_job();

		$this->assertSame( [ 'MediaBulkAction', 'MediaUploaded' ], array_column( $events, 'type' ) );
		$data = $events[0]['data'];
		$this->assertSame( [ 'uploaded', 12 ], [ $data['action'], $data['count'] ] );
		$this->assertSame(
			[
				'id'    => 112,
				'name'  => 'photo-112.jpg',
				'title' => 'photo-112',
			],
			$data['files'][11]
		);
		$this->assertSame( 5, $events[1]['user']['id'] );
	}

	public function test_files_attached_to_one_post_at_once_name_it(): void {
		$items = [];
		for ( $id = 101; $id <= 111; $id++ ) {
			$this->attachment( $id, '2026/10/photo-' . $id . '.jpg', 4 );
			$items[] = [
				'id'  => $id,
				'old' => [ 'parent' => 0 ],
				'new' => [ 'parent' => 4 ],
			];
		}
		$this->request( $items );

		$data = $this->run_job()[0]['data'];

		$this->assertSame( [ 'attached', 11 ], [ $data['action'], $data['count'] ] );
		$this->assertSame( 'About us', $data['parent']['title'] );
	}

	public function test_past_the_per_minute_cap_the_rest_is_one_row(): void {
		Filters\expectApplied( 'scanfully_media_events_per_minute' )->andReturn( 2 );
		$items = [];
		for ( $id = 101; $id <= 105; $id++ ) {
			$this->attachment( $id, '2026/10/photo-' . $id . '.jpg' );
			$items[] = [
				'id'  => $id,
				'old' => [ 'title' => 'a' ],
				'new' => [ 'title' => 'b' ],
			];
		}
		$this->request( $items );

		$events = $this->run_job();

		$this->assertSame( [ 'MediaChanged', 'MediaChanged', 'MediaBulkAction' ], array_column( $events, 'type' ) );
		$this->assertTrue( $events[2]['data']['overflow'] );
		$this->assertSame( [ 103, 104, 105 ], array_column( $events[2]['data']['files'], 'id' ) );
		$this->assertSame( [ 'title' => 3 ], $events[2]['data']['changed'] );
	}

	public function test_a_filter_can_leave_a_change_out(): void {
		$this->attachment( 7, '2026/10/photo.jpg' );
		Filters\expectApplied( 'scanfully_media_should_fire' )->andReturn( false );
		$this->request(
			[
				[
					'id'       => 7,
					'uploaded' => true,
				],
			]
		);

		$this->assertSame( [], $this->run_job() );
	}
}
