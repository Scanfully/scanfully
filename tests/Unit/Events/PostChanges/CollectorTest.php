<?php
/**
 * Post change collector unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\PostChanges
 */

namespace Scanfully\Tests\Unit\Events\PostChanges;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Scanfully\Events\ContentEvent;
use Scanfully\Events\PostChanges\Collector;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\PostChanges\Collector
 * @covers \Scanfully\Events\PostChanges\Fields
 * @covers \Scanfully\Events\PostDeleted
 */
final class CollectorTest extends TestCase {

	/**
	 * The meta cache, per post ID.
	 *
	 * @var array<int, array<string, array<int, string>>>
	 */
	private array $meta_cache = [];

	/**
	 * What the collector reported: the action's arguments.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $reported = [];

	/**
	 * Content events reported: type and data.
	 *
	 * @var array<int, array{type: string, data: array<string, mixed>}>
	 */
	private array $content = [];

	/**
	 * How many revisions WordPress keeps.
	 *
	 * @var int
	 */
	private int $revisions_to_keep = -1;

	protected function setUp(): void {
		parent::setUp();
		Collector::reset();

		Functions\when( 'wp_cache_get' )->alias( fn( $id, $group ) => 'post_meta' === $group ? ( $this->meta_cache[ $id ] ?? false ) : false );
		Functions\when( 'maybe_unserialize' )->alias( static fn( $value ) => is_string( $value ) && 0 === strpos( $value, 'a:' ) ? unserialize( $value ) : $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Test double of maybe_unserialize().
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'get_taxonomy' )->alias( static fn( $name ) => in_array( $name, [ 'category', 'post_tag' ], true ) ? (object) [ 'show_ui' => true ] : (object) [ 'show_ui' => false ] );
		Functions\when( 'wp_revisions_to_keep' )->alias( fn() => $this->revisions_to_keep );
		// What the report needs: a connected site, post types with a screen, room in the per-minute cap.
		Functions\when( 'get_option' )->justReturn( 'yes' );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'get_post_type_object' )->alias(
			static fn( string $type ) => (object) [
				'show_ui' => in_array( $type, [ 'post', 'page' ], true ),
				'labels'  => (object) [
					'name'          => 'page' === $type ? 'Pages' : 'Posts',
					'singular_name' => 'page' === $type ? 'Page' : 'Post',
				],
			]
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Actions\expectDone( Collector::ACTION )->zeroOrMoreTimes()->whenHappen(
			function ( ...$args ) {
				$this->reported[] = $args;
			}
		);
		$this->content = [];
		foreach ( [ 'PostDeleted', 'PostBulkAction' ] as $type ) {
			Actions\expectDone( ContentEvent::hook( $type ) )->zeroOrMoreTimes()->whenHappen(
				function ( $data ) use ( $type ) {
					$this->content[] = [
						'type' => $type,
						'data' => $data,
					];
				}
			);
		}
	}

	protected function tearDown(): void {
		Collector::reset();
		parent::tearDown();
	}

	/**
	 * A post.
	 *
	 * @param array<string, mixed> $fields Fields that differ from the defaults.
	 *
	 * @return \WP_Post
	 */
	private function post( array $fields = [] ): \WP_Post {
		return new \WP_Post(
			(object) array_merge(
				[
					'ID'             => 7,
					'post_title'     => 'About',
					'post_name'      => 'about',
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'post_content'   => '<p>Hello</p>',
					'post_excerpt'   => '',
					'post_parent'    => 0,
					'post_author'    => 1,
					'menu_order'     => 0,
					'post_date'      => '2026-10-01 10:00:00',
					'post_password'  => '',
					'comment_status' => 'closed',
				],
				$fields
			)
		);
	}

	/**
	 * The changes between two posts, with no fields, terms or revisions.
	 *
	 * @param array<string, mixed> $before Fields of the post before.
	 * @param array<string, mixed> $after  Fields of the post after.
	 *
	 * @return array<string, mixed>|null
	 */
	private function changes( array $before, array $after ): ?array {
		return Collector::changes( $this->post( $before ), $this->post( $after ), [], [], null );
	}

	public function test_a_save_that_changes_nothing_has_no_changes(): void {
		$this->assertNull( $this->changes( [], [] ) );
	}

	public function test_a_status_change_alone_is_a_change_without_details(): void {
		$this->assertSame( [], $this->changes( [ 'post_status' => 'draft' ], [] ) );
	}

	public function test_a_title_change_keeps_both_titles(): void {
		$this->assertSame(
			[
				'title' => [
					'old' => 'About',
					'new' => 'About us',
				],
			],
			$this->changes( [], [ 'post_title' => 'About us' ] )
		);
	}

	public function test_a_draft_getting_its_first_slug_is_not_a_slug_change(): void {
		$this->assertSame(
			[],
			$this->changes(
				[
					'post_status' => 'draft',
					'post_name'   => '',
				],
				[]
			)
		);
	}

	public function test_the_trashed_slug_is_not_a_slug_change(): void {
		$this->assertSame(
			[],
			$this->changes(
				[],
				[
					'post_status' => 'trash',
					'post_name' => 'about__trashed',
				]
			)
		);
		$this->assertSame(
			[],
			$this->changes(
				[
					'post_status' => 'trash',
					'post_name' => 'about__trashed',
				],
				[ 'post_status' => 'draft' ]
			)
		);
	}

	public function test_a_slug_change_keeps_both_slugs(): void {
		$changes = $this->changes( [], [ 'post_name' => 'about-us' ] );

		$this->assertSame(
			[
				'old' => 'about',
				'new' => 'about-us',
			],
			$changes['slug'] ?? null
		);
	}

	public function test_a_drafts_moving_date_is_not_a_change(): void {
		$this->assertNull(
			$this->changes(
				[ 'post_status' => 'draft' ],
				[
					'post_status' => 'draft',
					'post_date'   => '2026-10-02 09:00:00',
				]
			)
		);
	}

	public function test_a_published_posts_date_change_is_a_change(): void {
		$changes = $this->changes( [], [ 'post_date' => '2026-09-01 10:00:00' ] );

		$this->assertSame( '2026-09-01 10:00:00', $changes['date']['new'] ?? null );
	}

	public function test_a_password_is_never_kept_only_whether_there_is_one(): void {
		$changes = $this->changes( [ 'post_password' => 'old-secret' ], [ 'post_password' => 'new-secret' ] );

		$this->assertSame(
			[
				'old' => true,
				'new' => true,
			],
			$changes['password'] ?? null
		);
		$this->assertStringNotContainsString( 'secret', (string) json_encode( $changes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
	}

	public function test_parent_author_and_menu_order_changes_keep_ids(): void {
		$changes = $this->changes(
			[],
			[
				'post_parent' => 12,
				'post_author' => 3,
				'menu_order'  => 2,
			]
		);

		$this->assertSame(
			[
				'old' => 0,
				'new' => 12,
			],
			$changes['parent'] ?? null
		);
		$this->assertSame(
			[
				'old' => 1,
				'new' => 3,
			],
			$changes['author'] ?? null
		);
		$this->assertSame(
			[
				'old' => 0,
				'new' => 2,
			],
			$changes['menu_order'] ?? null
		);
	}

	public function test_changed_content_is_compared_from_revisions_when_wordpress_keeps_them(): void {
		$changes = Collector::changes(
			$this->post(),
			$this->post( [ 'post_content' => '<p>Hi</p>' ] ),
			[],
			[],
			[
				'first'   => 20,
				'last'    => 21,
				'earlier' => true,
			]
		);

		$this->assertSame(
			[
				'revisions' => [
					'first' => 20,
					'last'  => 21,
				],
			],
			$changes['content'] ?? null
		);
	}

	public function test_changed_content_is_kept_for_the_job_without_revisions(): void {
		$this->revisions_to_keep = 0;

		$changes = Collector::changes(
			$this->post(),
			$this->post( [ 'post_content' => '<p>Hi</p>' ] ),
			[],
			[],
			[
				'first'   => 20,
				'last'    => 20,
				'earlier' => true,
			]
		);

		$this->assertSame(
			[
				'stash' => [
					'old' => '<p>Hello</p>',
					'new' => '<p>Hi</p>',
				],
			],
			$changes['content'] ?? null
		);
	}

	public function test_a_single_kept_revision_is_not_enough_to_compare(): void {
		$this->revisions_to_keep = 1;

		$changes = Collector::changes(
			$this->post(),
			$this->post( [ 'post_content' => '<p>Hi</p>' ] ),
			[],
			[],
			[
				'first'   => 20,
				'last'    => 20,
				'earlier' => true,
			]
		);

		$this->assertArrayHasKey( 'stash', $changes['content'] ?? [] );
	}

	public function test_a_posts_first_revision_has_no_content_before_it_so_the_content_is_kept(): void {
		$changes = Collector::changes(
			$this->post(),
			$this->post( [ 'post_content' => '<p>Hi</p>' ] ),
			[],
			[],
			[
				'first'   => 20,
				'last'    => 20,
				'earlier' => false,
			]
		);

		$this->assertArrayHasKey( 'stash', $changes['content'] ?? [] );
	}

	public function test_very_large_content_without_revisions_is_not_kept(): void {
		$changes = $this->changes( [], [ 'post_content' => str_repeat( 'a', Collector::MAX_CONTENT + 1 ) ] );

		$this->assertSame( [ 'too_large' => true ], $changes['content'] ?? null );
	}

	/**
	 * Save post 7 with the given fields before and after, then flush.
	 *
	 * @param array<string, mixed> $before Fields of the post before.
	 * @param array<string, mixed> $after  Fields of the post after.
	 * @param bool                 $update Whether an existing post was updated.
	 *
	 * @return void
	 */
	private function save_and_flush( array $before = [], array $after = [], bool $update = true ): void {
		Collector::note_saved( 7, $this->post( $after ), $update, $this->post( $before ) );
		Collector::flush();
	}

	public function test_a_custom_field_change_is_reported_with_both_values(): void {
		$this->meta_cache[7] = [ 'subtitle' => [ 'Old' ] ];
		Collector::note_meta_updated( 1, 7, 'subtitle', 'New' );
		$this->save_and_flush();

		$this->assertCount( 1, $this->reported );
		$this->assertSame(
			[
				'subtitle' => [
					'change' => 'changed',
					'old'    => 'Old',
					'new'    => 'New',
				],
			],
			$this->reported[0][4]['fields'] ?? null
		);
	}

	public function test_setting_a_field_to_the_same_value_is_not_a_change(): void {
		$this->meta_cache[7] = [ 'colors' => [ serialize( [ 'red' ] ) ] ]; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Stored meta is serialized.
		Collector::note_meta_updated( 1, 7, 'colors', [ 'red' ] );
		$this->save_and_flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_added_and_removed_fields_are_reported(): void {
		$this->meta_cache[7] = [ 'gone' => [ 'Bye' ] ];
		Collector::note_meta_added( 1, 7, 'fresh', 'Hi' );
		Collector::note_meta_deleted( [ 2 ], 7, 'gone', '' );
		$this->save_and_flush();

		$fields = $this->reported[0][4]['fields'] ?? [];
		$this->assertSame(
			[
				'change' => 'added',
				'new'    => 'Hi',
			],
			$fields['fresh'] ?? null
		);
		$this->assertSame(
			[
				'change' => 'removed',
				'old'    => 'Bye',
			],
			$fields['gone'] ?? null
		);
	}

	public function test_a_field_added_and_removed_in_one_request_is_not_reported(): void {
		Collector::note_meta_added( 1, 7, 'temp', 'x' );
		Collector::note_meta_deleted( [ 1 ], 7, 'temp', '' );
		$this->save_and_flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_a_large_field_value_is_only_marked_large(): void {
		$this->meta_cache[7] = [ '_elementor_data' => [ str_repeat( 'x', 300 ) ] ];
		Collector::note_meta_updated( 1, 7, '_elementor_data', str_repeat( 'y', 300 ) );
		$this->save_and_flush();

		$this->assertSame(
			[
				'change' => 'changed',
				'large'  => true,
			],
			$this->reported[0][4]['fields']['_elementor_data'] ?? null
		);
	}

	public function test_bookkeeping_fields_are_not_changes(): void {
		$this->meta_cache[7] = [ '_edit_lock' => [ '1:1' ] ];
		Collector::note_meta_updated( 1, 7, '_edit_lock', '2:1' );
		Collector::note_meta_updated( 1, 7, '_yoast_wpseo_content_score', '60' );
		$this->save_and_flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_a_field_changed_without_a_save_is_not_reported(): void {
		Collector::note_meta_updated( 1, 7, 'views', '12' );
		Collector::flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_terms_added_and_removed_are_reported(): void {
		Collector::note_terms( 7, [], [ 3, 5 ], 'category', false, [ 1, 3 ] );
		$this->save_and_flush();

		$this->assertSame(
			[
				'category' => [
					'added'   => [ 5 ],
					'removed' => [ 1 ],
				],
			],
			$this->reported[0][4]['terms'] ?? null
		);
	}

	public function test_appended_terms_keep_the_existing_ones(): void {
		Collector::note_terms( 7, [], [ 5 ], 'post_tag', true, [ 1 ] );
		$this->save_and_flush();

		$this->assertSame(
			[
				'added'   => [ 5 ],
				'removed' => [],
			],
			$this->reported[0][4]['terms']['post_tag'] ?? null
		);
	}

	public function test_terms_of_internal_taxonomies_are_not_changes(): void {
		Collector::note_terms( 7, [], [ 9 ], 'internal_tax', false, [] );
		$this->save_and_flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_a_new_post_is_reported_without_changes(): void {
		Collector::note_saved( 7, $this->post(), false, null );
		Collector::flush();

		$this->assertCount( 1, $this->reported );
		$this->assertSame( [], $this->reported[0][4] );
	}

	public function test_a_post_opened_as_auto_draft_and_saved_is_new(): void {
		$this->save_and_flush(
			[
				'post_status' => 'auto-draft',
				'post_title'  => '',
			],
			[ 'post_status' => 'draft' ]
		);

		$this->assertSame( [], $this->reported[0][4] );
	}

	public function test_several_saves_in_one_request_are_reported_once_from_first_to_last(): void {
		Collector::note_saved( 7, $this->post( [ 'post_title' => 'B' ] ), true, $this->post( [ 'post_title' => 'A' ] ) );
		Collector::note_saved( 7, $this->post( [ 'post_title' => 'C' ] ), true, $this->post( [ 'post_title' => 'B' ] ) );
		Collector::flush();

		$this->assertCount( 1, $this->reported );
		$this->assertSame(
			[
				'old' => 'A',
				'new' => 'C',
			],
			$this->reported[0][4]['title'] ?? null
		);
	}

	public function test_a_flush_forgets_what_it_reported(): void {
		$this->save_and_flush( [], [ 'post_title' => 'New' ] );
		Collector::flush();

		$this->assertCount( 1, $this->reported );
	}

	public function test_a_revision_is_noted_for_its_post(): void {
		$this->assertTrue( Collector::note_earlier_revision( true, (object) [ 'ID' => 19 ], (object) [ 'ID' => 7 ] ) );
		Collector::note_revision( 20, 7 );
		Collector::note_revision( 21, 7 );
		$this->save_and_flush( [], [ 'post_content' => '<p>Changed</p>' ] );

		$this->assertSame(
			[
				'revisions' => [
					'first' => 20,
					'last'  => 21,
				],
			],
			$this->reported[0][4]['content'] ?? null
		);
	}

	/**
	 * Delete post 7 (or another) the way wp_delete_post() calls the hooks.
	 *
	 * @param array<string, mixed> $fields The post's fields.
	 * @param bool                 $done   Whether the delete went through.
	 *
	 * @return void
	 */
	private function delete( array $fields = [], bool $done = true ): void {
		$post = $this->post( $fields );
		Collector::note_deleting( $post->ID, $post );
		if ( $done ) {
			Collector::note_deleted( $post->ID );
		}
	}

	public function test_a_permanent_delete_is_reported_with_its_title_type_and_status(): void {
		$this->delete( [ 'post_status' => 'trash' ] );
		Collector::flush();

		$this->assertSame(
			[
				[
					'type' => 'PostDeleted',
					'data' => [
						'id'          => 7,
						'title'       => 'About',
						'post_type'   => 'page',
						'type_label'  => 'Page',
						'post_status' => 'trash',
					],
				],
			],
			$this->content
		);
	}

	public function test_a_delete_that_did_not_go_through_is_not_reported(): void {
		$this->delete( [], false );
		Collector::flush();

		$this->assertSame( [], $this->content );
	}

	public function test_the_trash_wordpress_empties_itself_is_marked_as_expired(): void {
		Actions\expectDone( 'wp_scheduled_delete' )->once()->whenHappen(
			function () {
				$this->delete( [ 'post_status' => 'trash' ] );
			}
		);
		do_action( 'wp_scheduled_delete' );
		Collector::flush();

		$this->assertTrue( $this->content[0]['data']['expired'] ?? null );
		$this->assertSame( 30, $this->content[0]['data']['trash_days'] ?? null );
	}

	public function test_auto_drafts_and_types_without_a_screen_are_not_reported_when_deleted(): void {
		$this->delete( [ 'post_status' => 'auto-draft' ] );
		$this->delete(
			[
				'ID'        => 8,
				'post_type' => 'revision',
			]
		);
		Collector::flush();

		$this->assertSame( [], $this->content );
	}

	public function test_a_post_trashed_and_deleted_in_one_request_is_only_reported_as_deleted(): void {
		Collector::note_saved( 7, $this->post( [ 'post_status' => 'trash' ] ), true, $this->post() );
		$this->delete( [ 'post_status' => 'trash' ] );
		Collector::flush();

		$this->assertSame( [], $this->reported );
		$this->assertSame( [ 'PostDeleted' ], array_column( $this->content, 'type' ) );
	}

	public function test_a_post_made_and_deleted_in_one_request_is_not_reported(): void {
		Collector::note_saved( 7, $this->post( [ 'post_status' => 'draft' ] ), false, null );
		$this->delete( [ 'post_status' => 'draft' ] );
		Collector::flush();

		$this->assertSame( [], $this->reported );
		$this->assertSame( [], $this->content );
	}

	public function test_fields_removed_while_a_post_is_deleted_are_not_noted(): void {
		$this->meta_cache[7] = [ 'subtitle' => [ 'Old' ] ];
		Collector::note_deleting( 7, $this->post() );
		Collector::note_meta_deleted( [ 1 ], 7, 'subtitle', 'Old' );
		Collector::note_saved( 7, $this->post(), true, $this->post() );
		Collector::flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_many_posts_moved_to_the_trash_are_one_row_with_every_post(): void {
		for ( $id = 1; $id <= 11; $id++ ) {
			Collector::note_saved(
				$id,
				$this->post(
					[
						'ID'          => $id,
						'post_title'  => 'Page ' . $id,
						'post_status' => 'trash',
					]
				),
				true,
				$this->post( [ 'ID' => $id ] )
			);
		}
		Collector::flush();

		$this->assertSame( [], $this->reported );
		$this->assertSame( [ 'PostBulkAction' ], array_column( $this->content, 'type' ) );
		$data = $this->content[0]['data'];
		$this->assertSame( [ 'trashed', 'page', 'Pages', 11 ], [ $data['action'], $data['post_type'], $data['type_label'], $data['count'] ] );
		$this->assertSame( 'Page 11', $data['posts'][10]['title'] );
	}

	public function test_a_few_posts_keep_rows_of_their_own(): void {
		for ( $id = 1; $id <= 3; $id++ ) {
			Collector::note_saved( $id, $this->post( [ 'post_status' => 'trash' ] ), true, $this->post() );
		}
		Collector::flush();

		$this->assertCount( 3, $this->reported );
		$this->assertSame( [], $this->content );
	}

	public function test_a_bulk_edit_row_says_which_terms_were_added(): void {
		for ( $id = 1; $id <= 12; $id++ ) {
			Collector::note_terms( $id, [ 'news' ], [ 12 ], 'category', true, [ 1 ] );
			Collector::note_saved( $id, $this->post( [ 'ID' => $id ] ), true, $this->post( [ 'ID' => $id ] ) );
		}
		Collector::flush();

		$data = $this->content[0]['data'] ?? [];
		$this->assertSame( 'updated', $data['action'] ?? null );
		$this->assertSame( [ 'terms' => 12 ], $data['changed'] ?? null );
		$this->assertSame(
			[
				'category' => [
					'added'   => [ 12 ],
					'removed' => [],
				],
			],
			$data['terms'] ?? null
		);
		$this->assertSame( [ 12 => true ], Collector::added_terms() );
	}

	public function test_posts_past_the_first_hundred_are_counted_into_the_row(): void {
		for ( $id = 1; $id <= 150; $id++ ) {
			Collector::note_saved( $id, $this->post( [ 'post_title' => 'Renamed ' . $id ] ), true, $this->post() );
		}
		// The same post saved again past the cap is counted once.
		Collector::note_saved( 150, $this->post( [ 'post_title' => 'Again' ] ), true, $this->post() );
		Collector::flush();

		$data = $this->content[0]['data'] ?? [];
		$this->assertSame( 150, $data['count'] ?? null );
		$this->assertSame( [ 'title' => 150 ], $data['changed'] ?? null );
		$this->assertSame( 'Renamed 150', $data['posts'][149]['title'] ?? null );
	}

	public function test_posts_past_the_first_hundred_that_changed_nothing_are_not_counted(): void {
		for ( $id = 1; $id <= 120; $id++ ) {
			Collector::note_saved( $id, $this->post(), true, $this->post() );
		}
		Collector::flush();

		$this->assertSame( [], $this->content );
		$this->assertSame( [], $this->reported );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_nothing_is_noted_during_an_import(): void {
		define( 'WP_IMPORTING', true );

		$this->save_and_flush( [], [ 'post_title' => 'Imported' ] );

		$this->assertSame( [], $this->reported );
	}
}
