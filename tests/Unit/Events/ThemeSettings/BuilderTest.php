<?php
/**
 * Theme settings change builder unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\ThemeSettings
 */

namespace Scanfully\Tests\Unit\Events\ThemeSettings;

use Brain\Monkey\Functions;
use Scanfully\Events\ThemeSettings\Builder;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\ThemeSettings\Builder
 */
final class BuilderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_get_theme' )->alias(
			static fn( $stylesheet ) => new class( $stylesheet ) {
				/**
				 * The stylesheet.
				 *
				 * @var string
				 */
				private string $stylesheet;

				/**
				 * Constructor.
				 *
				 * @param string $stylesheet The stylesheet.
				 */
				public function __construct( string $stylesheet ) {
					$this->stylesheet = $stylesheet;
				}

				/**
				 * Whether the theme exists.
				 *
				 * @return bool
				 */
				public function exists(): bool {
					return 'twentyseventeen' === $this->stylesheet;
				}

				/**
				 * A header of the theme.
				 *
				 * @param string $header The header.
				 *
				 * @return string
				 */
				public function get( string $header ): string {
					return 'Twenty Seventeen &amp; Co';
				}
			}
		);
		Functions\when( 'get_attached_file' )->alias( static fn( $id ) => 40 === $id ? '/var/www/wp-content/uploads/2026/10/logo-new.png' : false );
		Functions\when( 'wp_basename' )->alias( static fn( $path ) => basename( (string) $path ) );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress is not loaded in unit tests.
	}

	public function test_the_theme_name_and_file_names_are_added(): void {
		$data = Builder::resolve(
			[
				'stylesheet' => 'twentyseventeen',
				'settings'   => [
					[
						'id'     => 'header_image',
						'kind'   => 'media',
						'change' => 'changed',
						'old'    => 'remove-header',
						'new'    => 'https://scanfully.com/wp-content/uploads/2026/10/header.jpg?v=2',
					],
					[
						'id'     => 'background_color',
						'kind'   => 'color',
						'change' => 'changed',
						'old'    => 'ffffff',
						'new'    => '000000',
					],
				],
				'logo'       => [
					'old' => '',
					'new' => '40',
				],
			]
		);

		$this->assertSame( 'Twenty Seventeen & Co', $data['name'] );
		$this->assertArrayNotHasKey( 'old_file', $data['settings'][0] );
		$this->assertSame( 'header.jpg', $data['settings'][0]['new_file'] );
		$this->assertArrayNotHasKey( 'new_file', $data['settings'][1] );
		$this->assertSame(
			[
				'old' => [
					'id'   => '',
					'file' => '',
				],
				'new' => [
					'id'   => '40',
					'file' => 'logo-new.png',
				],
			],
			$data['logo']
		);
	}

	public function test_page_choices_get_their_titles(): void {
		Functions\when( 'get_post' )->alias( static fn( $id ) => 16 === $id ? new \WP_Post( (object) [ 'post_title' => 'About &amp; us' ] ) : null );

		$data = Builder::resolve(
			[
				'stylesheet' => 'twentyseventeen',
				'settings'   => [
					[
						'id'     => 'panel_1',
						'kind'   => 'page',
						'change' => 'changed',
						'old'    => '0',
						'new'    => '16',
					],
				],
			]
		);

		$this->assertArrayNotHasKey( 'old_name', $data['settings'][0] );
		$this->assertSame( 'About & us', $data['settings'][0]['new_name'] );
	}

	public function test_a_theme_that_no_longer_exists_keeps_its_stylesheet_as_name(): void {
		$data = Builder::resolve(
			[
				'stylesheet' => 'gone',
				'logo'       => [
					'old' => '40',
					'new' => '',
				],
			]
		);

		$this->assertSame( 'gone', $data['name'] );
	}

	public function test_css_that_was_too_large_or_lost_is_only_reported_as_changed(): void {
		$this->assertSame(
			[
				'changed' => true,
				'reason'  => 'too_large',
			],
			Builder::resolve(
				[
					'stylesheet' => 'twentyseventeen',
					'css'        => [ 'too_large' => true ],
				]
			)['css']
		);
		$this->assertSame( 'unavailable', Builder::resolve( [ 'css' => [] ] )['css']['reason'] );
	}

	public function test_nothing_left_is_nothing_to_send(): void {
		$this->assertSame( [], Builder::resolve( [ 'stylesheet' => 'twentyseventeen' ] ) );
	}
}
