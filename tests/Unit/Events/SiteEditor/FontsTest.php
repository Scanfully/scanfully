<?php
/**
 * Font Library batch unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\SiteEditor
 */

namespace Scanfully\Tests\Unit\Events\SiteEditor;

use Scanfully\Events\SiteEditor\Fonts;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\SiteEditor\Fonts
 */
final class FontsTest extends TestCase {

	/**
	 * A minute entry of one request.
	 *
	 * @param string                            $family `added`, `removed` or ''.
	 * @param array<int, array<string, string>> $faces  The variants added or removed.
	 * @param int                               $user   The user ID.
	 *
	 * @return array<string, mixed>
	 */
	private function entry( string $family, array $faces = [], int $user = 1 ): array {
		return [
			'user'  => [
				'id'   => $user,
				'name' => 'Barry',
			],
			'items' => [
				[
					'id'     => 9,
					'name'   => 'Inter',
					'slug'   => 'inter',
					'family' => $family,
					'faces'  => $faces,
				],
			],
		];
	}

	/**
	 * A variant added or removed.
	 *
	 * @param string $action `added` or `removed`.
	 * @param string $style  The style.
	 * @param string $weight The weight.
	 *
	 * @return array<string, string>
	 */
	private function face( string $action, string $style, string $weight ): array {
		return [
			'action' => $action,
			'style'  => $style,
			'weight' => $weight,
		];
	}

	public function test_an_install_in_several_requests_is_one_row_with_its_variants_in_order(): void {
		$rows = Fonts::rows(
			[
				$this->entry( 'added' ),
				$this->entry( '', [ $this->face( 'added', 'normal', '700' ) ] ),
				$this->entry( '', [ $this->face( 'added', 'italic', '400' ) ] ),
				$this->entry( '', [ $this->face( 'added', 'normal', '400' ) ] ),
			]
		);

		$this->assertCount( 1, $rows );
		[ $type, $user, $data ] = $rows[0];
		$this->assertSame( 'FontInstalled', $type );
		$this->assertSame( 1, $user['id'] );
		$this->assertTrue( $data['family'] );
		$this->assertSame(
			[ 'normal 400', 'italic 400', 'normal 700' ],
			array_map( static fn( array $v ): string => $v['style'] . ' ' . $v['weight'], $data['variants'] )
		);
	}

	public function test_variants_added_to_an_installed_family_are_not_a_new_family(): void {
		$rows = Fonts::rows( [ $this->entry( '', [ $this->face( 'added', 'normal', '900' ) ] ) ] );

		$this->assertSame( 'FontInstalled', $rows[0][0] );
		$this->assertFalse( $rows[0][2]['family'] );
	}

	public function test_a_family_removed_lists_its_variants(): void {
		$rows = Fonts::rows(
			[
				$this->entry(
					'removed',
					[
						$this->face( 'removed', 'normal', '400' ),
						$this->face( 'removed', 'normal', '700' ),
					]
				),
			]
		);

		$this->assertSame( 'FontDeleted', $rows[0][0] );
		$this->assertTrue( $rows[0][2]['family'] );
		$this->assertCount( 2, $rows[0][2]['variants'] );
	}

	public function test_a_variant_removed_alone_is_a_delete_of_that_variant(): void {
		$rows = Fonts::rows( [ $this->entry( '', [ $this->face( 'removed', 'italic', '700' ) ] ) ] );

		$this->assertSame( 'FontDeleted', $rows[0][0] );
		$this->assertFalse( $rows[0][2]['family'] );
	}

	public function test_what_is_undone_within_the_minute_is_left_out(): void {
		$this->assertSame( [], Fonts::rows( [ $this->entry( 'added' ), $this->entry( 'removed' ) ] ) );
		$this->assertSame(
			[],
			Fonts::rows(
				[
					$this->entry( '', [ $this->face( 'added', 'normal', '400' ) ] ),
					$this->entry( '', [ $this->face( 'removed', 'normal', '400' ) ] ),
				]
			)
		);
	}

	public function test_each_person_gets_a_row_of_their_own(): void {
		$rows = Fonts::rows(
			[
				$this->entry( '', [ $this->face( 'added', 'normal', '400' ) ], 1 ),
				$this->entry( '', [ $this->face( 'added', 'normal', '700' ) ], 2 ),
			]
		);

		$this->assertSame( [ 1, 2 ], array_map( static fn( array $row ): int => $row[1]['id'], $rows ) );
	}
}
