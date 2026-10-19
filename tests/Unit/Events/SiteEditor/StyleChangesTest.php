<?php
/**
 * Global styles comparison unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\SiteEditor
 */

namespace Scanfully\Tests\Unit\Events\SiteEditor;

use Brain\Monkey\Functions;
use Scanfully\Events\SiteEditor\Builder;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\SiteEditor\Builder::style_changes
 */
final class StyleChangesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
	}

	/**
	 * A global styles config.
	 *
	 * @param array<string, mixed> $styles   The styles.
	 * @param array<string, mixed> $settings The settings.
	 *
	 * @return array<string, mixed>
	 */
	private function config( array $styles, array $settings = [] ): array {
		return [
			'version'                     => 3,
			'isGlobalStylesUserThemeJSON' => true,
			'styles'                      => $styles,
			'settings'                    => $settings,
		];
	}

	public function test_each_style_setting_is_one_change_with_its_values(): void {
		$changes = Builder::style_changes(
			$this->config(
				[
					'color'  => [
						'background' => '#ffffff',
						'text'       => '#111111',
					],
					'blocks' => [ 'core/button' => [ 'border' => [ 'radius' => '4px' ] ] ],
				]
			),
			$this->config(
				[
					'color'      => [ 'background' => '#000000' ],
					'typography' => [ 'fontFamily' => 'var:preset|font-family|inter' ],
					'blocks'     => [ 'core/button' => [ 'border' => [ 'radius' => '4px' ] ] ],
				]
			)
		);

		$this->assertSame(
			[
				[
					'path'   => 'styles.color.background',
					'change' => 'changed',
					'old'    => '#ffffff',
					'new'    => '#000000',
				],
				[
					'path'   => 'styles.color.text',
					'change' => 'removed',
					'old'    => '#111111',
				],
				[
					'path'   => 'styles.typography.fontFamily',
					'change' => 'added',
					'new'    => 'var:preset|font-family|inter',
				],
			],
			$changes['styles']
		);
		$this->assertArrayNotHasKey( 'css', $changes );
	}

	public function test_a_list_such_as_a_palette_is_one_value_and_long_values_are_only_large(): void {
		$palette = [];
		for ( $i = 0; $i < 10; $i++ ) {
			$palette[] = [
				'slug'  => 'colour-' . $i,
				'color' => '#00000' . $i,
				'name'  => 'Colour ' . $i,
			];
		}

		$changes = Builder::style_changes( $this->config( [] ), $this->config( [], [ 'color' => [ 'palette' => [ 'custom' => $palette ] ] ] ) );

		$this->assertSame(
			[
				[
					'path'   => 'settings.color.palette.custom',
					'change' => 'added',
					'large'  => true,
				],
			],
			$changes['styles']
		);
	}

	public function test_a_variation_is_named_and_a_reset_is_one_change(): void {
		$old = $this->config( [ 'color' => [ 'background' => '#ffffff' ] ] );
		$new = $this->config( [ 'color' => [ 'background' => '#222222' ] ] ) + [ 'title' => 'Evening' ];

		$this->assertSame(
			[
				'old' => '',
				'new' => 'Evening',
			],
			Builder::style_changes( $old, $new )['variation']
		);
		$this->assertSame( [ 'reset' => true ], Builder::style_changes( $old, $this->config( [] ) ) );
	}

	public function test_a_variation_is_found_by_its_settings_and_the_whole_one_wins(): void {
		$evening   = [
			'title'    => 'Evening',
			'styles'   => [
				'color'      => [ 'background' => '#1b1b1b' ],
				'typography' => [ 'fontFamily' => 'var:preset|font-family|inter' ],
			],
			'settings' => [ 'layout' => [ 'contentSize' => '700px' ] ],
		];
		$dark      = [
			'title'  => 'Dark colours',
			'styles' => [ 'color' => [ 'background' => '#1b1b1b' ] ],
		];
		$new       = $this->config( $evening['styles'], $evening['settings'] );
		$old       = $this->config( [ 'color' => [ 'background' => '#ffffff' ] ] );
		$variation = Builder::matching_variation( $old, $new, [ $dark, $evening ] );

		$this->assertSame( 'Evening', $variation );
		$this->assertSame( 'Dark colours', Builder::matching_variation( $old, $this->config( [ 'color' => [ 'background' => '#1b1b1b' ] ] ), [ $dark, $evening ] ) );
		$this->assertSame( '', Builder::matching_variation( $old, $this->config( [ 'color' => [ 'background' => '#222222' ] ] ), [ $dark, $evening ] ), 'No variation has these styles.' );
		$this->assertSame( '', Builder::matching_variation( $new, $new, [ $dark, $evening ] ), 'The variation was already there.' );
		$this->assertSame( 'Evening', Builder::variation_in( $new, [ $dark, $evening ] ) );
		$this->assertSame( '', Builder::variation_in( $old, [ $dark, $evening ] ) );
	}

	public function test_the_list_is_capped_and_says_how_many_more(): void {
		$colors = [];
		for ( $i = 0; $i < Builder::MAX_PATHS + 5; $i++ ) {
			$colors[ 'c' . $i ] = '#' . $i;
		}

		$changes = Builder::style_changes( $this->config( [] ), $this->config( [ 'color' => $colors ] ) );

		$this->assertCount( Builder::MAX_PATHS, $changes['styles'] );
		$this->assertSame( 5, $changes['more'] );
	}

	public function test_nothing_changed_is_no_change(): void {
		$config = $this->config( [ 'color' => [ 'background' => '#ffffff' ] ] );

		$this->assertSame( [], Builder::style_changes( $config, $config ) );
	}
}
