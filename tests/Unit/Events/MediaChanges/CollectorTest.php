<?php
/**
 * Media change collector unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\MediaChanges
 */

namespace Scanfully\Tests\Unit\Events\MediaChanges;

use Brain\Monkey\Functions;
use Scanfully\Events\Controller;
use Scanfully\Events\MediaChanges\Collector;
use Scanfully\Tests\Unit\Events\Content\FakeOptionsTable;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\MediaChanges\Collector
 */
final class CollectorTest extends TestCase {

	/**
	 * The options table the notes are stored in.
	 *
	 * @var FakeOptionsTable
	 */
	private FakeOptionsTable $table;

	/**
	 * The meta cache, per post ID: key => [ serialized value ].
	 *
	 * @var array<int, array<string, array<int, mixed>>>
	 */
	private array $meta = [];

	/**
	 * The post cache, per post ID.
	 *
	 * @var array<int, object>
	 */
	private array $posts = [];

	/**
	 * Whether the site is connected.
	 *
	 * @var string
	 */
	private string $connected = 'yes';

	/**
	 * Scheduled jobs: hook and arguments.
	 *
	 * @var array<int, array{hook: string, args: array<mixed>}>
	 */
	private array $scheduled = [];

	/**
	 * Keys made so far.
	 *
	 * @var int
	 */
	private int $uuids = 0;

	protected function setUp(): void {
		parent::setUp();
		Collector::reset();

		$this->table = FakeOptionsTable::install();
		Functions\when( 'get_option' )->alias( fn() => $this->connected );
		Functions\when( 'wp_cache_get' )->alias(
			function ( $id, $group ) {
				if ( 'post_meta' === $group ) {
					return $this->meta[ $id ] ?? false;
				}
				return 'posts' === $group ? ( $this->posts[ $id ] ?? false ) : false;
			}
		);
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) [
				'ID'           => 3,
				'display_name' => 'Editor',
			]
		);
		Functions\when( 'wp_generate_uuid4' )->alias( fn() => sprintf( '%08x-0000-4000-8000-000000000000', ++$this->uuids ) );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args ) {
				$this->scheduled[] = [
					'hook' => $hook,
					'args' => $args,
				];
				return 1;
			}
		);
		Functions\when( 'sanitize_key' )->alias( static fn( $key ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress is not loaded in unit tests.
		Functions\when( 'wp_basename' )->alias( static fn( $path ) => basename( (string) $path ) );
	}

	protected function tearDown(): void {
		Collector::reset();
		$_REQUEST = [];
		parent::tearDown();
	}

	/**
	 * End the "request" and return the items it stored for the minute.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function end_request(): array {
		$before = $this->table->rows;
		Collector::flush();
		$new = array_diff_key( $this->table->rows, $before );
		if ( [] === $new ) {
			return [];
		}

		$entry = array_values( $new )[0];
		$this->assertSame(
			[
				'id'   => 3,
				'name' => 'Editor',
			],
			$entry['user']
		);

		return $entry['items'];
	}

	/**
	 * An attachment post.
	 *
	 * @param array<string, mixed> $fields Fields that differ from the default.
	 *
	 * @return object
	 */
	private static function post( array $fields = [] ): object {
		return (object) array_merge(
			[
				'post_title'     => 'Team photo',
				'post_excerpt'   => '',
				'post_content'   => '',
				'post_name'      => 'team-photo',
				'post_parent'    => 0,
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			],
			$fields
		);
	}

	public function test_a_details_save_notes_old_and_new(): void {
		Collector::note_updated( 7, self::post( [ 'post_title' => 'Team &amp; friends' ] ), self::post() );

		$this->assertSame(
			[
				[
					'id'  => 7,
					'old' => [ 'title' => 'Team photo' ],
					'new' => [ 'title' => 'Team & friends' ],
				],
			],
			$this->end_request()
		);
		$this->assertSame( Controller::ACTION_SEND_MEDIA, $this->scheduled[0]['hook'] );
	}

	public function test_alt_text_takes_the_old_value_from_the_meta_cache(): void {
		$this->meta[7] = [ '_wp_attachment_image_alt' => [ 'Old alt' ] ];

		Collector::note_meta_updating( 1, 7, '_wp_attachment_image_alt', 'New alt' );

		$this->assertSame(
			[
				[
					'id'  => 7,
					'old' => [ 'alt' => 'Old alt' ],
					'new' => [ 'alt' => 'New alt' ],
				],
			],
			$this->end_request()
		);
	}

	public function test_alt_text_added_and_removed(): void {
		Collector::note_meta_added( 1, 7, '_wp_attachment_image_alt', 'First alt' );
		$this->assertSame( [ 'alt' => '' ], $this->end_request()[0]['old'] );

		$this->meta[7] = [ '_wp_attachment_image_alt' => [ 'First alt' ] ];
		Collector::note_meta_deleting( [ 1 ], 7, '_wp_attachment_image_alt', '' );
		$this->assertSame( [ 'alt' => '' ], $this->end_request()[0]['new'] );
	}

	public function test_a_save_that_changes_nothing_stores_nothing(): void {
		Collector::note_updated( 7, self::post(), self::post() );
		Collector::note_updated( 8, self::post( [ 'post_title' => 'B' ] ), self::post() );
		Collector::note_updated( 8, self::post(), self::post( [ 'post_title' => 'B' ] ) );

		$this->assertSame( [], $this->end_request() );
		$this->assertSame( [], $this->scheduled );
	}

	public function test_an_upload_keeps_its_file_and_takes_in_later_changes(): void {
		Collector::note_meta_added( 1, 7, '_wp_attached_file', '2026/10/team-photo.jpg' );
		Collector::note_added( 7 );
		Collector::note_updated( 7, self::post( [ 'post_title' => 'Team' ] ), self::post() );
		Collector::note_meta_added( 2, 7, '_wp_attachment_image_alt', 'The team' );
		Collector::note_meta_updating( 3, 7, '_wp_attached_file', '2026/10/team-photo-scaled.jpg' );

		$this->assertSame(
			[
				[
					'id'       => 7,
					'uploaded' => true,
					'file'     => '2026/10/team-photo.jpg',
				],
			],
			$this->end_request()
		);
	}

	public function test_wordpress_own_follow_up_files_are_no_change(): void {
		$this->meta[7] = [ '_wp_attached_file' => [ '2026/10/photo.jpg' ] ];

		Collector::note_meta_updating( 1, 7, '_wp_attached_file', '2026/10/photo-scaled.jpg' );

		$this->assertSame( [], $this->end_request() );
	}

	public function test_a_converted_or_renamed_file_is_a_change(): void {
		$this->meta[7] = [ '_wp_attached_file' => [ '2026/10/photo.jpg' ] ];

		Collector::note_meta_updating( 1, 7, '_wp_attached_file', '2026/10/photo.webp' );

		$items = $this->end_request();
		$this->assertCount( 1, $items );
		$this->assertSame( [ 'file' => '2026/10/photo.jpg' ], $items[0]['old'] ?? null );
		$this->assertSame( [ 'file' => '2026/10/photo.webp' ], $items[0]['new'] ?? null );
	}

	public function test_same_file(): void {
		$this->assertTrue( Collector::same_file( '2026/10/a.jpg', '2026/10/a-scaled.jpg' ) );
		$this->assertTrue( Collector::same_file( '2026/10/a.jpg', '2026/10/a-rotated.jpg' ) );
		$this->assertTrue( Collector::same_file( '2026/10/a-scaled.jpg', '2026/10/a.jpg' ) );
		$this->assertFalse( Collector::same_file( '2026/10/a.jpg', '2026/10/b.jpg' ) );
		$this->assertFalse( Collector::same_file( '2026/10/a.png', '2026/10/a.webp' ) );
		$this->assertFalse( Collector::same_file( '2026/10/a.jpg', '2026/11/a.jpg' ) );
	}

	public function test_a_permanent_delete_keeps_what_the_row_says(): void {
		$this->meta[7] = [
			'_wp_attached_file'       => [ '2026/10/team-photo.jpg' ],
			'_wp_attachment_metadata' => [
				[
					'width'    => 2400,
					'height'   => 1600,
					'filesize' => 1200000,
					'sizes'    => [],
				],
			],
		];
		Functions\when( 'maybe_unserialize' )->returnArg();

		Collector::note_deleting( 7, self::post() );
		Collector::note_meta_deleting( [ 11, 12, 13 ], 0, '_thumbnail_id', 7 );
		Collector::note_meta_deleting( [ 14 ], 7, '_wp_attachment_image_alt', '' );
		Collector::note_deleted( 7 );

		$this->assertSame(
			[
				[
					'id'      => 7,
					'deleted' => [
						'title'       => 'Team photo',
						'file'        => '2026/10/team-photo.jpg',
						'mime_type'   => 'image/jpeg',
						'post_status' => 'inherit',
						'parent'      => 0,
						'facts'       => [
							'filesize' => 1200000,
							'width'    => 2400,
							'height'   => 1600,
						],
						'featured_on' => 3,
						'expired'     => false,
					],
				],
			],
			$this->end_request()
		);
	}

	public function test_a_delete_that_did_not_go_through_is_not_stored(): void {
		Collector::note_deleting( 7, self::post() );

		$this->assertSame( [], $this->end_request() );
	}

	public function test_a_file_uploaded_and_deleted_in_one_request_is_nothing(): void {
		Collector::note_added( 7 );
		Collector::note_deleting( 7, self::post() );
		Collector::note_deleted( 7 );

		$this->assertSame( [], $this->end_request() );
	}

	public function test_an_image_editor_save_is_noted_once_wordpress_wrote_the_image(): void {
		$_REQUEST      = [
			'do'      => 'save',
			'postid'  => '7',
			'history' => '[{"c":{"x":0,"y":0,"w":10,"h":10}},{"r":90},{"r":90}]',
			'target'  => 'all',
		];
		$this->meta[7] = [ '_wp_attached_file' => [ '2026/10/photo.jpg' ] ];

		Collector::note_image_editor();
		$this->assertSame( [], $this->end_request(), 'Refused or failed: nothing written, nothing noted.' );

		Collector::note_image_editor();
		Collector::note_meta_updating( 1, 7, '_wp_attached_file', '2026/10/photo-e1697651234567.jpg' );
		Collector::note_meta_updating( 2, 7, '_wp_attachment_metadata', [] );

		$this->assertSame(
			[
				[
					'id'    => 7,
					'image' => [
						'edits'  => [ 'crop', 'rotate' ],
						'target' => 'all',
					],
				],
			],
			$this->end_request()
		);
	}

	public function test_restoring_the_original_image(): void {
		$_REQUEST = [
			'do'     => 'restore',
			'postid' => '7',
		];

		Collector::note_image_editor();
		Collector::note_meta_updating( 1, 7, '_wp_attachment_backup_sizes', [] );

		$this->assertSame( [ 'restored' => true ], $this->end_request()[0]['image'] );
	}

	public function test_history_edits(): void {
		$this->assertSame( [ 'flip', 'rotate' ], Collector::history_edits( [ [ 'f' => 1 ], [ 'r' => 90 ], 'junk' ] ) );
		$this->assertSame( [], Collector::history_edits( [] ) );
	}

	public function test_a_replace_through_enable_media_replace(): void {
		$this->meta[7] = [ '_wp_attached_file' => [ '2026/10/logo.png' ] ];

		Collector::note_meta_updating( 1, 7, '_wp_attached_file', '2026/10/logo-new.png' );
		Collector::note_replaced( 'https://scanfully.com/wp-content/uploads/2026/10/logo-new.png', 'https://scanfully.com/wp-content/uploads/2026/10/logo.png', 7 );

		$this->assertSame(
			[
				[
					'id'       => 7,
					'replaced' => [
						'old' => 'logo.png',
						'new' => 'logo-new.png',
					],
				],
			],
			$this->end_request()
		);
	}

	public function test_attach_in_list_view_takes_the_old_parent_from_the_post_cache(): void {
		$this->posts[7] = (object) [ 'post_parent' => 4 ];

		Collector::note_attach( 'attach', 7, 12 );
		Collector::note_attach( 'detach', 8, 5 );

		$this->assertSame(
			[
				[
					'id'  => 7,
					'old' => [ 'parent' => 4 ],
					'new' => [ 'parent' => 12 ],
				],
				[
					'id'  => 8,
					'old' => [ 'parent' => 5 ],
					'new' => [ 'parent' => 0 ],
				],
			],
			$this->end_request()
		);
	}

	public function test_nothing_is_stored_while_the_site_is_not_connected(): void {
		$this->connected = 'no';
		Collector::note_updated( 7, self::post( [ 'post_title' => 'New' ] ), self::post() );

		$this->assertSame( [], $this->end_request() );
		$this->assertSame( [], $this->scheduled );
	}
}
