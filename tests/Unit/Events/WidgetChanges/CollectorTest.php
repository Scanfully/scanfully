<?php
/**
 * Widget change collector unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\WidgetChanges
 */

namespace Scanfully\Tests\Unit\Events\WidgetChanges;

use Brain\Monkey\Functions;
use Scanfully\Events\WidgetChanges\Collector;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\WidgetChanges\Collector
 */
final class CollectorTest extends TestCase {

	/**
	 * The stored `sidebars_widgets` option.
	 *
	 * @var array<string, mixed>
	 */
	private array $areas = [];

	protected function setUp(): void {
		parent::setUp();
		Collector::reset();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->areas               = [
			'wp_inactive_widgets' => [],
			'sidebar-1'           => [ 'search-2', 'text-3' ],
			'footer'              => [ 'block-4' ],
			'array_version'       => 3,
		];

		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'get_option' )->alias( fn( $name ) => 'sidebars_widgets' === $name ? $this->areas : false );
		Functions\when( 'wp_installing' )->justReturn( false );
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'doing_action' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		Collector::reset();
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tearDown();
	}

	/**
	 * The areas option with changes.
	 *
	 * @param array<string, mixed> $changes Areas and their widgets.
	 *
	 * @return array<string, mixed>
	 */
	private function areas_with( array $changes ): array {
		return array_merge( $this->areas, $changes );
	}

	public function test_a_widget_added_to_an_area(): void {
		Collector::note_updated(
			'widget_text',
			[ 3 => [ 'title' => 'Hours' ] ],
			[
				3 => [ 'title' => 'Hours' ],
				5 => [
					'title' => 'News',
					'text'  => 'Hi',
				],
			]
		);
		Collector::note_updated( 'sidebars_widgets', $this->areas, $this->areas_with( [ 'sidebar-1' => [ 'search-2', 'text-3', 'text-5' ] ] ) );

		$row = Collector::row();

		$this->assertSame(
			[
				[
					'id'     => 'text-5',
					'type'   => 'text',
					'action' => 'added',
					'title'  => 'News',
					'area'   => 'sidebar-1',
				],
			],
			$row['widgets']
		);
	}

	public function test_a_changed_title_and_text_keep_the_old_title_and_the_text_for_the_diff(): void {
		Collector::note_updated(
			'widget_text',
			[
				3 => [
					'title' => 'Hours',
					'text'  => "Mon\nTue",
				],
			],
			[
				3 => [
					'title' => 'Opening hours',
					'text'  => "Mon\nWed",
				],
			]
		);

		$widget = Collector::row()['widgets'][0];

		$this->assertSame( 'changed', $widget['action'] );
		$this->assertSame( 'Hours', $widget['title_old'] );
		$this->assertSame( 'Opening hours', $widget['title'] );
		$this->assertSame( [ 'title', 'text' ], $widget['changed'] );
		$this->assertSame( "Mon\nWed", $widget['_text']['new'] );
	}

	public function test_short_settings_keep_their_values_and_long_ones_only_say_changed(): void {
		$changes = Collector::setting_changes(
			[
				'number' => 5,
				'filter' => str_repeat( 'a', 300 ),
			],
			[
				'number' => '10',
				'filter' => str_repeat( 'b', 300 ),
			]
		);

		$this->assertSame( [ 'number', 'filter' ], $changes['changed'] );
		$this->assertSame(
			[
				'number' => [
					'old' => '5',
					'new' => '10',
				],
			],
			$changes['values']
		);
	}

	public function test_a_setting_that_only_changed_type_is_no_change(): void {
		Collector::note_updated( 'widget_recent-posts', [ 2 => [ 'number' => 5 ] ], [ 2 => [ 'number' => '5' ] ] );
		$this->areas['sidebar-1'][] = 'recent-posts-2';

		$this->assertSame( [], Collector::row() );
	}

	public function test_a_widget_moved_to_another_area(): void {
		Collector::note_updated(
			'sidebars_widgets',
			$this->areas,
			$this->areas_with(
				[
					'sidebar-1' => [ 'search-2' ],
					'footer'    => [ 'block-4', 'text-3' ],
				]
			)
		);

		$widget = Collector::row()['widgets'][0];

		$this->assertSame( 'moved', $widget['action'] );
		$this->assertSame( 'footer', $widget['area'] );
		$this->assertSame( 'sidebar-1', $widget['old_area'] );
	}

	public function test_a_widget_taken_to_the_inactive_widgets_is_removed_and_deleting_it_is_deleted(): void {
		Collector::note_updated(
			'sidebars_widgets',
			$this->areas,
			$this->areas_with(
				[
					'sidebar-1'           => [ 'search-2' ],
					'wp_inactive_widgets' => [ 'text-3' ],
				]
			)
		);
		$this->assertSame( 'removed', Collector::row()['widgets'][0]['action'] );

		Collector::reset();
		Collector::note_updated( 'widget_text', [ 3 => [ 'title' => 'Hours' ] ], [] );
		Collector::note_updated( 'sidebars_widgets', $this->areas, $this->areas_with( [ 'sidebar-1' => [ 'search-2' ] ] ) );
		$widget = Collector::row()['widgets'][0];
		$this->assertSame( 'deleted', $widget['action'] );
		$this->assertSame( 'Hours', $widget['title'] );
		$this->assertSame( 'sidebar-1', $widget['old_area'] );
	}

	public function test_a_new_order_within_an_area_is_noted_once(): void {
		Collector::note_updated( 'sidebars_widgets', $this->areas, $this->areas_with( [ 'sidebar-1' => [ 'text-3', 'search-2' ] ] ) );

		$row = Collector::row();

		$this->assertSame( [], $row['widgets'] );
		$this->assertSame( [ 'sidebar-1' ], $row['order_changed'] );
	}

	public function test_a_block_widget_carries_its_content_for_its_label(): void {
		Collector::note_updated( 'widget_block', [ 4 => [ 'content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ] ], [ 4 => [ 'content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' ] ] );

		$widget = Collector::row()['widgets'][0];

		$this->assertSame( 'changed', $widget['action'] );
		$this->assertSame( [ 'content' ], $widget['changed'] );
		$this->assertStringContainsString( 'Hello', $widget['_content'] );
	}

	public function test_read_requests_are_left_out(): void {
		// The Widgets screen tidies widgets up when it loads.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		Collector::note_updated(
			'sidebars_widgets',
			$this->areas,
			$this->areas_with(
				[
					'sidebar-1'           => [ 'search-2' ],
					'wp_inactive_widgets' => [ 'text-3' ],
				]
			)
		);

		$this->assertSame( [], Collector::row() );
	}

	public function test_other_options_are_ignored(): void {
		Collector::note_updated( 'blogname', 'Old', 'New' );

		$this->assertSame( [], Collector::row() );
	}
}
