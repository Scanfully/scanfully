<?php
/**
 * Menu event builder unit tests: moves and block menu changes.
 *
 * @package Scanfully\Tests\Unit\Events\MenuChanges
 */

namespace Scanfully\Tests\Unit\Events\MenuChanges;

use Brain\Monkey\Functions;
use Scanfully\Events\MenuChanges\Builder;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\MenuChanges\Builder
 */
final class BuilderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $text ) => strip_tags( (string) $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress is not loaded in unit tests.
		Functions\when( '__' )->returnArg();
		Functions\when( 'post_type_exists' )->alias( static fn( $type ) => in_array( $type, [ 'page', 'post' ], true ) );
		Functions\when( 'get_post_type_object' )->alias(
			static fn( $type ) => (object) [
				'labels' => (object) [ 'singular_name' => ucfirst( (string) $type ) ],
			]
		);
		Functions\when( 'get_taxonomy' )->justReturn( false );
	}

	public function test_one_item_moved_up_is_the_only_move(): void {
		// Places before, in today's order: item 5 moved from last to second.
		$this->assertSame(
			[ 5 ],
			Builder::out_of_order(
				[
					1 => 1,
					5 => 5,
					2 => 2,
					3 => 3,
					4 => 4,
				]
			)
		);
	}

	public function test_renumbering_after_a_removal_is_no_move(): void {
		// Item 2 was removed; the ones below kept their order.
		$this->assertSame(
			[],
			Builder::out_of_order(
				[
					1 => 1,
					3 => 3,
					4 => 4,
					6 => 6,
				]
			)
		);
	}

	public function test_two_items_swapped_is_one_move(): void {
		$this->assertCount(
			1,
			Builder::out_of_order(
				[
					2 => 2,
					1 => 1,
					3 => 3,
				]
			)
		);
	}

	public function test_a_single_item_never_moves(): void {
		$this->assertSame( [], Builder::out_of_order( [ 9 => 4 ] ) );
	}

	/**
	 * A navigation link block.
	 *
	 * @param string               $label The label.
	 * @param string               $url   The URL.
	 * @param array<string, mixed> $extra More attributes.
	 *
	 * @return array<string, mixed>
	 */
	private static function link( string $label, string $url, array $extra = [] ): array {
		return [
			'key'    => isset( $extra['id'] ) ? 'page:' . $extra['id'] : 'url:' . $url,
			'id'     => (int) ( $extra['id'] ?? 0 ),
			'label'  => $label,
			'url'    => $url,
			'type'   => isset( $extra['id'] ) ? 'page' : 'custom',
			'parent' => (string) ( $extra['parent'] ?? '' ),
			'attrs'  => (array) ( $extra['attrs'] ?? [] ),
		];
	}

	public function test_block_menu_items_added_removed_renamed_and_moved(): void {
		$before = [
			self::link( 'Home', 'https://scanfully.com/' ),
			self::link( 'Blog', 'https://scanfully.com/blog/', [ 'id' => 7 ] ),
			self::link( 'Old', 'https://scanfully.com/old/' ),
			self::link( 'Shop', 'https://scanfully.com/shop/' ),
		];
		$after  = [
			self::link( 'Shop', 'https://scanfully.com/shop/' ),
			self::link( 'Home', 'https://scanfully.com/' ),
			self::link( 'News', 'https://scanfully.com/news/', [ 'id' => 7 ] ),
			self::link( 'Contact', 'https://scanfully.com/contact/' ),
		];

		$changes = Builder::block_changes( $before, $after );

		$this->assertSame( [ 'Contact' ], array_column( $changes['added'], 'label' ) );
		$this->assertSame( [ 'Old' ], array_column( $changes['removed'], 'label' ) );
		$this->assertSame( 'Blog', $changes['renamed'][0]['old'] );
		$this->assertSame( 'News', $changes['renamed'][0]['new'] );
		$this->assertSame( 'https://scanfully.com/news/', $changes['relinked'][0]['new'] );
		$this->assertSame( [ 'Shop' ], array_column( $changes['moved'], 'label' ) );
	}

	public function test_a_link_moved_into_a_submenu_says_under_which(): void {
		$before = [
			self::link( 'About', 'https://scanfully.com/about/' ),
			self::link( 'Team', 'https://scanfully.com/team/' ),
		];
		$after  = [
			self::link( 'About', 'https://scanfully.com/about/' ),
			self::link( 'Team', 'https://scanfully.com/team/', [ 'parent' => 'About' ] ),
		];

		$changes = Builder::block_changes( $before, $after );

		$this->assertSame(
			[
				[
					'id'     => 0,
					'label'  => 'Team',
					'type'   => 'Custom Link',
					'parent' => 'About',
				],
			],
			$changes['moved']
		);
		$this->assertArrayNotHasKey( 'added', $changes );
	}

	public function test_a_link_opening_in_a_new_tab_is_a_detail(): void {
		$changes = Builder::block_changes(
			[ self::link( 'Shop', 'https://scanfully.com/shop/' ) ],
			[ self::link( 'Shop', 'https://scanfully.com/shop/', [ 'attrs' => [ 'opensInNewTab' => true ] ] ) ]
		);

		$this->assertSame( [ 'opensInNewTab' ], $changes['details'][0]['changed'] );
	}

	public function test_the_same_menu_is_no_change(): void {
		$items = [ self::link( 'Home', 'https://scanfully.com/' ), self::link( 'Shop', 'https://scanfully.com/shop/' ) ];

		$this->assertSame( [], Builder::block_changes( $items, $items ) );
	}
}
