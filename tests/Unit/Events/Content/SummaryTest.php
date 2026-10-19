<?php
/**
 * Content summary row unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\Content
 */

namespace Scanfully\Tests\Unit\Events\Content;

use Brain\Monkey\Functions;
use Scanfully\Events\Content\Report;
use Scanfully\Events\Content\Summary;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\Content\Summary
 */
final class SummaryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_post_type_object' )->justReturn( (object) [ 'labels' => (object) [ 'name' => 'Pages' ] ] );
		Functions\when( 'get_taxonomy' )->justReturn( (object) [ 'labels' => (object) [ 'name' => 'Tags' ] ] );
	}

	/**
	 * Post items.
	 *
	 * @param int                  $count  How many.
	 * @param array<string, mixed> $fields Fields every item has.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function items( int $count, array $fields = [] ): array {
		$items = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$items[] = array_merge(
				[
					'action' => 'trashed',
					'group'  => 'page',
					'id'     => $i,
					'title'  => 'Page ' . $i,
				],
				$fields
			);
		}

		return $items;
	}

	public function test_a_row_lists_every_post(): void {
		$rows = Summary::rows( Report::KIND_POST, $this->items( 25 ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'PostBulkAction', $rows[0]['type'] );
		$this->assertSame( 'trashed', $rows[0]['data']['action'] );
		$this->assertSame( 'page', $rows[0]['data']['post_type'] );
		$this->assertSame( 'Pages', $rows[0]['data']['type_label'] );
		$this->assertSame( 25, $rows[0]['data']['count'] );
		$this->assertCount( 25, $rows[0]['data']['posts'] );
		$this->assertSame(
			[
				'id'    => 25,
				'title' => 'Page 25',
			],
			$rows[0]['data']['posts'][24]
		);
		$this->assertArrayNotHasKey( 'part', $rows[0]['data'] );
	}

	public function test_more_posts_than_a_row_holds_make_numbered_parts(): void {
		$rows = Summary::rows( Report::KIND_POST, $this->items( Summary::MAX_ITEMS_PER_ROW + 5 ) );

		$this->assertCount( 2, $rows );
		$this->assertSame( Summary::MAX_ITEMS_PER_ROW, $rows[0]['data']['count'] );
		$this->assertSame( 5, $rows[1]['data']['count'] );
		$this->assertSame( [ 2, 2, Summary::MAX_ITEMS_PER_ROW + 5 ], [ $rows[1]['data']['part'], $rows[1]['data']['parts'], $rows[1]['data']['total'] ] );
	}

	public function test_a_bulk_edit_row_counts_the_changes_and_joins_the_terms(): void {
		$items                = $this->items(
			3,
			[
				'action'  => 'updated',
				'changed' => [ 'terms', 'author' ],
				'terms'   => [
					'category' => [
						'added'   => [ 12 ],
						'removed' => [],
					],
				],
			]
		);
		$items[2]['terms']['category']['added'][] = 14;

		$data = Summary::rows( Report::KIND_POST, $items )[0]['data'];

		$this->assertSame(
			[
				'terms'  => 3,
				'author' => 3,
			],
			$data['changed']
		);
		$this->assertSame(
			[
				'category' => [
					'added'   => [ 12, 14 ],
					'removed' => [],
				],
			],
			$data['terms']
		);
	}

	public function test_the_trash_wordpress_emptied_itself_is_marked(): void {
		$data = Summary::rows(
			Report::KIND_POST,
			$this->items(
				2,
				[
					'action'  => 'deleted',
					'expired' => true,
				]
			)
		)[0]['data'];

		$this->assertTrue( $data['expired'] );
		$this->assertSame( 30, $data['trash_days'] );
	}

	public function test_a_term_row_lists_every_term_and_adds_up_the_posts(): void {
		$items = [
			[
				'action' => 'deleted',
				'group'  => 'post_tag',
				'id'     => 3,
				'title'  => 'News',
				'posts'  => 4,
			],
			[
				'action' => 'deleted',
				'group'  => 'post_tag',
				'id'     => 5,
				'title'  => 'Sports',
				'posts'  => 2,
			],
		];

		$rows = Summary::rows( Report::KIND_TERM, $items, true );

		$this->assertSame( 'TermBulkAction', $rows[0]['type'] );
		$this->assertSame(
			[
				'action'   => 'deleted',
				'taxonomy' => 'post_tag',
				'label'    => 'Tags',
				'count'    => 2,
				'terms'    => [
					[
						'id'   => 3,
						'name' => 'News',
					],
					[
						'id'   => 5,
						'name' => 'Sports',
					],
				],
				'posts'    => 6,
				'overflow' => true,
			],
			$rows[0]['data']
		);
	}

	public function test_items_are_grouped_by_action_type_and_expiry(): void {
		$items = array_merge(
			$this->items( 2 ),
			$this->items( 1, [ 'group' => 'post' ] ),
			$this->items( 1, [ 'action' => 'deleted' ] ),
			$this->items(
				1,
				[
					'action'  => 'deleted',
					'expired' => true,
				]
			)
		);

		$this->assertSame( [ 'trashed|page', 'trashed|post', 'deleted|page', 'deleted|page|expired' ], array_keys( Summary::group( $items ) ) );
	}

	public function test_titles_are_plain_text_and_cut(): void {
		$this->assertSame( 'Bread & butter', Summary::title( 'Bread &amp; butter' ) );
		$this->assertSame( Summary::MAX_TITLE, mb_strlen( Summary::title( str_repeat( 'é', 300 ) ) ) );
	}
}
