<?php
/**
 * Site Editor collector unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\SiteEditor
 */

namespace Scanfully\Tests\Unit\Events\SiteEditor;

use Brain\Monkey\Functions;
use Scanfully\Events\SiteEditor\Collector;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\SiteEditor\Collector
 */
final class CollectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Collector::reset();
		Functions\when( 'wp_installing' )->justReturn( false );
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		Functions\when( 'get_the_terms' )->alias(
			static fn( $post, string $taxonomy ) => 'wp_theme' === $taxonomy ? [ (object) [ 'name' => 'twentytwentyfive' ] ] : [ (object) [ 'name' => 'footer' ] ]
		);
	}

	protected function tearDown(): void {
		Collector::reset();
		parent::tearDown();
	}

	/**
	 * A post.
	 *
	 * @param array<string, mixed> $fields The fields.
	 *
	 * @return \WP_Post
	 */
	private function post( array $fields ): \WP_Post {
		return new \WP_Post(
			(object) array_merge(
				[
					'ID'           => 5,
					'post_title'   => '',
					'post_excerpt' => '',
					'post_content' => '',
					'post_status'  => 'publish',
					'post_name'    => '',
					'post_parent'  => 0,
				],
				$fields
			)
		);
	}

	/**
	 * A global styles post.
	 *
	 * @param string $content The config.
	 * @param string $title   The title.
	 *
	 * @return \WP_Post
	 */
	private function styles( string $content, string $title = 'Custom Styles' ): \WP_Post {
		return $this->post(
			[
				'post_type'    => 'wp_global_styles',
				'post_name'    => 'wp-global-styles-twentytwentyfive',
				'post_title'   => $title,
				'post_content' => $content,
			]
		);
	}

	public function test_the_empty_styles_core_makes_are_not_a_change(): void {
		Collector::note_saved( 5, $this->styles( '{"version": 3, "isGlobalStylesUserThemeJSON": true }' ), false, null );

		$this->assertSame( [], Collector::styles_rows() );
	}

	public function test_a_styles_save_keeps_the_config_before_and_after_and_the_theme(): void {
		$before = $this->styles( '{"version":3,"isGlobalStylesUserThemeJSON":true}' );
		$after  = $this->styles( '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"color":{"background":"#000"}}}' );

		Collector::note_saved( 5, $after, true, $before );
		$rows = Collector::styles_rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'twentytwentyfive', $rows[0]['stylesheet'] );
		$this->assertSame( $after->post_content, $rows[0]['content']['stash']['new'] );
	}

	public function test_a_variation_with_the_same_config_is_still_reported_by_its_title(): void {
		$content = '{"version":3,"isGlobalStylesUserThemeJSON":true}';
		Collector::note_saved( 5, $this->styles( $content, 'Noon' ), true, $this->styles( $content ) );

		$this->assertSame(
			[
				'old' => 'Custom Styles',
				'new' => 'Noon',
			],
			Collector::styles_rows()[0]['title']
		);
	}

	/**
	 * A template.
	 *
	 * @param string $content The content.
	 * @param string $title   The title.
	 * @param string $status  The status.
	 *
	 * @return \WP_Post
	 */
	private function template( string $content, string $title = 'Pages', string $status = 'publish' ): \WP_Post {
		return $this->post(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'page',
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => $status,
			]
		);
	}

	public function test_a_first_save_is_provisional_for_the_job(): void {
		Collector::note_saved( 5, $this->template( '<!-- wp:post-content /-->' ), false, null );
		$rows = Collector::template_rows();

		$this->assertSame( 'TemplateChanged', $rows[0][0] );
		$this->assertTrue( $rows[0][1]['inserted'] );
		$this->assertSame( '', $rows[0][1]['content']['stash']['old'] );
	}

	public function test_an_edit_reports_the_title_and_content_and_an_unchanged_save_nothing(): void {
		Collector::note_saved( 5, $this->template( 'b', 'Pages (shop)' ), true, $this->template( 'a' ) );
		$rows = Collector::template_rows();

		$this->assertSame(
			[
				'old' => 'Pages',
				'new' => 'Pages (shop)',
			],
			$rows[0][1]['changes']['title']
		);
		$this->assertSame(
			[
				'old' => 'a',
				'new' => 'b',
			],
			$rows[0][1]['content']['stash']
		);

		Collector::reset();
		Collector::note_saved( 5, $this->template( 'a' ), true, $this->template( 'a' ) );

		$this->assertSame( [], Collector::template_rows() );
	}

	public function test_a_template_part_moved_to_another_area_is_a_change(): void {
		$part = $this->post(
			[
				'post_type'    => 'wp_template_part',
				'post_name'    => 'footer',
				'post_content' => 'a',
			]
		);
		Collector::note_terms( 5, [], [ 8 ], 'wp_template_part_area', false, [ 7 ] );
		Collector::note_saved( 5, $part, true, $part );

		$this->assertSame(
			[
				'old' => [ 7 ],
				'new' => [ 8 ],
			],
			Collector::template_rows()[0][1]['changes']['area']
		);
	}

	public function test_a_delete_or_trash_is_a_provisional_delete_with_the_content_before(): void {
		Collector::note_deleting( 5, $this->template( 'gone' ) );
		$rows = Collector::template_rows();

		$this->assertSame( 'TemplateDeleted', $rows[0][0] );
		$this->assertTrue( $rows[0][1]['removed'] );
		$this->assertSame( 'twentytwentyfive', $rows[0][1]['stylesheet'] );
		$this->assertSame( 'gone', $rows[0][1]['content']['stash']['old'] );

		Collector::reset();
		Collector::note_saved( 5, $this->template( 'gone', 'Pages', 'trash' ), true, $this->template( 'gone' ) );

		$this->assertSame( 'TemplateDeleted', Collector::template_rows()[0][0] );
	}

	public function test_font_families_and_variants_are_noted_per_family(): void {
		Functions\when( 'get_post' )->justReturn(
			$this->post(
				[
					'ID'         => 9,
					'post_type'  => 'wp_font_family',
					'post_title' => 'Inter',
					'post_name'  => 'inter',
				]
			)
		);

		Collector::note_saved(
			12,
			$this->post(
				[
					'ID'           => 12,
					'post_type'    => 'wp_font_face',
					'post_parent'  => 9,
					'post_content' => '{"fontFamily":"Inter","fontStyle":"italic","fontWeight":"700","src":"https://scanfully.com/inter.woff2"}',
				]
			),
			false,
			null
		);

		$this->assertSame(
			[
				[
					'id'     => 9,
					'name'   => 'Inter',
					'slug'   => 'inter',
					'family' => '',
					'faces'  => [
						[
							'action' => 'added',
							'style'  => 'italic',
							'weight' => '700',
						],
					],
				],
			],
			Collector::font_items()
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_nothing_is_noted_during_an_import(): void {
		define( 'WP_IMPORTING', true );

		Collector::note_saved( 5, $this->template( 'a' ), false, null );

		$this->assertSame( [], Collector::template_rows() );
	}
}
