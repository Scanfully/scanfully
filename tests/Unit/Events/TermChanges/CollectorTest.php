<?php
/**
 * Term change collector unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\TermChanges
 */

namespace Scanfully\Tests\Unit\Events\TermChanges;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Scanfully\Events\ContentEvent;
use Scanfully\Events\PostChanges\Collector as PostCollector;
use Scanfully\Events\TermChanges\Collector;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\TermChanges\Collector
 */
final class CollectorTest extends TestCase {

	/**
	 * The term cache, per term ID.
	 *
	 * @var array<int, object>
	 */
	private array $cache = [];

	/**
	 * What the collector reported: event type and data.
	 *
	 * @var array<int, array{type: string, data: array<string, mixed>}>
	 */
	private array $reported = [];

	protected function setUp(): void {
		parent::setUp();
		Collector::reset();
		PostCollector::reset();

		Functions\when( 'get_option' )->justReturn( 'yes' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->alias( fn( $id, $group ) => 'terms' === $group ? ( $this->cache[ $id ] ?? false ) : false );
		Functions\when( 'get_taxonomy' )->alias(
			static fn( $name ) => (object) [
				'show_ui' => in_array( $name, [ 'category', 'post_tag', 'post_format' ], true ),
				'labels'  => (object) [
					'name'          => 'category' === $name ? 'Categories' : 'Tags',
					'singular_name' => 'category' === $name ? 'Category' : 'Tag',
				],
			]
		);

		foreach ( [ 'TermCreated', 'TermChanged', 'TermDeleted', 'TermBulkAction' ] as $type ) {
			Actions\expectDone( ContentEvent::hook( $type ) )->zeroOrMoreTimes()->whenHappen(
				function ( $data ) use ( $type ) {
					$this->reported[] = [
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
	 * Create a term the way wp_insert_term() calls the hooks.
	 *
	 * @param int    $term_id  The term ID (also its term taxonomy ID).
	 * @param string $name     The name, as stored.
	 * @param string $taxonomy The taxonomy.
	 * @param int    $parent_id The parent.
	 *
	 * @return void
	 */
	private function create( int $term_id, string $name, string $taxonomy = 'category', int $parent_id = 0 ): void {
		Collector::note_inserting(
			[
				'name'       => $name,
				'slug'       => strtolower( $name ),
				'term_group' => 0,
			],
			$taxonomy,
			[ 'parent' => $parent_id ]
		);
		Collector::note_created( $term_id, $term_id, $taxonomy );
	}

	/**
	 * Change term 5 the way wp_update_term() calls the hooks.
	 *
	 * @param array<string, mixed> $old The term before.
	 * @param array<string, mixed> $new The new values.
	 *
	 * @return void
	 */
	private function update( array $old, array $new ): void {
		$before          = array_merge(
			[
				'name'        => 'News',
				'slug'        => 'news',
				'parent'      => 0,
				'description' => '',
			],
			$old
		);
		$after           = array_merge( $before, $new );
		$this->cache[5]  = (object) $before;

		Collector::note_updating( $after['parent'], 5, 'category', [ 'description' => $after['description'] ] );
		Collector::note_update_data(
			[
				'name' => $after['name'],
				'slug' => $after['slug'],
			],
			5,
			'category'
		);
		Collector::note_edited( 5, 5, 'category' );
	}

	/**
	 * Delete a term the way wp_delete_term() calls the hook.
	 *
	 * @param int    $term_id  The term ID.
	 * @param string $name     The name.
	 * @param int    $posts    How many posts had it.
	 * @param string $taxonomy The taxonomy.
	 *
	 * @return void
	 */
	private function delete( int $term_id, string $name, int $posts = 0, string $taxonomy = 'category' ): void {
		$term = new \WP_Term(
			(object) [
				'term_id' => $term_id,
				'name'    => $name,
				'slug'    => strtolower( $name ),
			]
		);
		Collector::note_deleted( $term_id, $term_id, $taxonomy, $term, $posts > 0 ? range( 1, $posts ) : [] );
	}

	public function test_a_new_term_is_reported_with_its_parent(): void {
		$this->create( 9, 'Bread &amp; butter', 'category', 4 );
		Collector::flush();

		$this->assertSame(
			[
				[
					'type' => 'TermCreated',
					'data' => [
						'id'       => 9,
						'name'     => 'Bread & butter',
						'slug'     => 'bread &amp; butter',
						'taxonomy' => 'category',
						'label'    => 'Category',
						'parent'   => [ 'id' => 4 ],
					],
				],
			],
			$this->reported
		);
	}

	public function test_terms_of_taxonomies_without_a_screen_and_post_formats_are_not_reported(): void {
		$this->create( 9, 'Aside', 'post_format' );
		$this->create( 10, 'Main menu', 'nav_menu' );
		$this->delete( 11, 'Footer', 0, 'nav_menu' );
		Collector::flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_a_rename_reports_the_old_and_new_name_and_slug(): void {
		$this->update(
			[],
			[
				'name' => 'Updates',
				'slug' => 'updates',
			]
		);
		Collector::flush();

		$this->assertSame( 'TermChanged', $this->reported[0]['type'] );
		$this->assertSame( 'Updates', $this->reported[0]['data']['name'] );
		$this->assertSame(
			[
				'name' => [
					'old' => 'News',
					'new' => 'Updates',
				],
				'slug' => [
					'old' => 'news',
					'new' => 'updates',
				],
			],
			$this->reported[0]['data']['changes']
		);
	}

	public function test_a_new_parent_and_description_are_changes(): void {
		$this->update(
			[ 'parent' => 2 ],
			[
				'parent' => 3,
				'description' => str_repeat( 'a', 1200 ),
			]
		);
		Collector::flush();

		$changes = $this->reported[0]['data']['changes'];
		$this->assertSame(
			[
				'old' => [ 'id' => 2 ],
				'new' => [ 'id' => 3 ],
			],
			$changes['parent']
		);
		$this->assertSame( '', $changes['description']['old'] );
		$this->assertSame( 1000, strlen( $changes['description']['new'] ) );
	}

	public function test_saving_a_term_without_changes_is_not_reported(): void {
		$this->update( [], [] );
		Collector::flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_a_term_not_in_the_cache_is_not_compared(): void {
		Collector::note_updating( 0, 5, 'category', [] );
		Collector::note_update_data( [ 'name' => 'Updates' ], 5, 'category' );
		Collector::note_edited( 5, 5, 'category' );
		Collector::flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_a_deleted_term_reports_how_many_posts_had_it(): void {
		$this->delete( 5, 'News', 12 );
		Collector::flush();

		$this->assertSame( 'TermDeleted', $this->reported[0]['type'] );
		$this->assertSame( 12, $this->reported[0]['data']['posts'] );
		$this->assertSame( 'News', $this->reported[0]['data']['name'] );
	}

	public function test_a_term_made_and_deleted_in_one_request_is_not_reported(): void {
		$this->create( 9, 'Temp' );
		$this->delete( 9, 'Temp' );
		Collector::flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_a_term_changed_and_deleted_is_only_reported_as_deleted(): void {
		$this->update( [], [ 'name' => 'Updates' ] );
		$this->delete( 5, 'Updates' );
		Collector::flush();

		$this->assertSame( [ 'TermDeleted' ], array_column( $this->reported, 'type' ) );
	}

	public function test_a_new_term_renamed_in_the_same_request_is_reported_once_as_it_is_now(): void {
		$this->create( 5, 'News' );
		$this->update( [], [ 'name' => 'Updates' ] );
		Collector::flush();

		$this->assertSame( [ 'TermCreated' ], array_column( $this->reported, 'type' ) );
		$this->assertSame( 'Updates', $this->reported[0]['data']['name'] );
	}

	public function test_many_deleted_tags_are_one_row_with_every_tag(): void {
		for ( $id = 1; $id <= 12; $id++ ) {
			$this->delete( $id, 'Tag ' . $id, 1, 'post_tag' );
		}
		Collector::flush();

		$this->assertSame( [ 'TermBulkAction' ], array_column( $this->reported, 'type' ) );
		$data = $this->reported[0]['data'];
		$this->assertSame( 'deleted', $data['action'] );
		$this->assertSame( 'Tags', $data['label'] );
		$this->assertSame( 12, $data['count'] );
		$this->assertSame( 12, $data['posts'] );
		$this->assertSame( 'Tag 12', $data['terms'][11]['name'] );
	}
}
