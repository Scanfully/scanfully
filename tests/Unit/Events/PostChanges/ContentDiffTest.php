<?php
/**
 * Content diff unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\PostChanges
 */

namespace Scanfully\Tests\Unit\Events\PostChanges;

use Brain\Monkey\Functions;
use Scanfully\Events\PostChanges\ContentDiff;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\PostChanges\ContentDiff
 */
final class ContentDiffTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $text ) => strip_tags( (string) $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress is not loaded in unit tests.
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress is not loaded in unit tests.
	}

	/**
	 * A line diff that only finds lines removed or added at the same place,
	 * with runs of the same operation merged, as Text_Diff returns them.
	 *
	 * @param string[] $before The lines before.
	 * @param string[] $after  The lines after.
	 *
	 * @return array<int, array{0: string, 1: string[]}>
	 */
	public static function naive_diff( array $before, array $after ): array {
		$operations = [];
		$add        = static function ( string $op, string $line ) use ( &$operations ): void {
			$last = count( $operations ) - 1;
			if ( $last >= 0 && $operations[ $last ][0] === $op ) {
				$operations[ $last ][1][] = $line;
				return;
			}
			$operations[] = [ $op, [ $line ] ];
		};

		$count = max( count( $before ), count( $after ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$a = $before[ $i ] ?? null;
			$b = $after[ $i ] ?? null;
			if ( $a === $b ) {
				$add( '=', (string) $a );
				continue;
			}
			if ( null !== $a ) {
				$add( '-', $a );
			}
			if ( null !== $b ) {
				$add( '+', $b );
			}
		}

		return $operations;
	}

	public function test_block_markup_becomes_one_line_per_block(): void {
		$content = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Our team</h2>\n<!-- /wp:heading -->\n\n"
			. "<!-- wp:paragraph -->\n<p>We are <strong>five</strong> people &amp; a dog.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:list -->\n<ul><li>Alice</li><li>Bob</li></ul>\n<!-- /wp:list -->";

		$this->assertSame( [ 'Our team', 'We are five people & a dog.', 'Alice', 'Bob' ], ContentDiff::text_lines( $content ) );
	}

	public function test_images_are_named_by_their_file(): void {
		$this->assertSame(
			[ 'Before', '[image: team-photo.jpg]', 'After' ],
			ContentDiff::text_lines( '<p>Before</p><img src="https://scanfully.com/wp-content/uploads/team-photo.jpg?v=2" alt="x"><p>After</p>' )
		);
	}

	public function test_scripts_and_styles_are_left_out(): void {
		$this->assertSame( [ 'Text' ], ContentDiff::text_lines( '<style>p{color:red}</style><p>Text</p><script>alert(1)</script>' ) );
	}

	public function test_a_markup_only_change_has_no_lines(): void {
		$this->assertSame(
			[
				'changed' => true,
				'reason'  => 'markup',
			],
			ContentDiff::compare( '<p>Same</p>', '<p class="lead">Same</p>', [ self::class, 'naive_diff' ] )
		);
	}

	public function test_very_large_content_is_not_compared(): void {
		$this->assertSame( 'too_large', ContentDiff::compare( str_repeat( 'a', ContentDiff::MAX_CONTENT + 1 ), 'b', [ self::class, 'naive_diff' ] )['reason'] ?? null );
	}

	public function test_a_change_keeps_one_line_of_context_and_counts_the_rest(): void {
		$old = '<p>One</p><p>Two</p><p>Three</p><p>Four</p><p>Five</p><p>Six</p>';
		$new = '<p>One</p><p>Two</p><p>Three</p><p>4</p><p>Five</p><p>Six</p>';

		$this->assertSame(
			[
				'added'     => 1,
				'removed'   => 1,
				'lines'     => [
					[
						'op'    => 'skip',
						'count' => 2,
					],
					[
						'op'   => '=',
						'text' => 'Three',
					],
					[
						'op'   => '-',
						'text' => 'Four',
					],
					[
						'op'   => '+',
						'text' => '4',
					],
					[
						'op'   => '=',
						'text' => 'Five',
					],
					[
						'op'    => 'skip',
						'count' => 1,
					],
				],
				'truncated' => false,
			],
			ContentDiff::compare( $old, $new, [ self::class, 'naive_diff' ] )
		);
	}

	public function test_short_unchanged_runs_between_changes_are_kept_whole(): void {
		$summary = ContentDiff::summarize(
			[
				[ '-', [ 'a' ] ],
				[ '=', [ 'b', 'c' ] ],
				[ '+', [ 'd' ] ],
			]
		);

		$this->assertSame( [ '-', '=', '=', '+' ], array_column( $summary['lines'], 'op' ) );
	}

	public function test_long_lines_are_cut(): void {
		$summary = ContentDiff::summarize( [ [ '+', [ str_repeat( 'é', ContentDiff::MAX_LINE + 10 ) ] ] ] );

		$this->assertSame( ContentDiff::MAX_LINE + 1, mb_strlen( $summary['lines'][0]['text'] ) );
	}

	public function test_a_long_diff_is_truncated_but_still_counted(): void {
		$added   = array_fill( 0, 100, str_repeat( 'x', 200 ) );
		$summary = ContentDiff::summarize( [ [ '+', $added ] ] );

		$this->assertTrue( $summary['truncated'] );
		$this->assertSame( 100, $summary['added'] );
		$this->assertLessThanOrEqual( ContentDiff::MAX_DIFF, array_sum( array_map( 'strlen', array_column( $summary['lines'], 'text' ) ) ) );
	}
}
