<?php
/**
 * Theme settings change collector unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\ThemeSettings
 */

namespace Scanfully\Tests\Unit\Events\ThemeSettings;

use Brain\Monkey\Functions;
use Scanfully\Events\ThemeSettings\Collector;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * A Customizer setting for the tests: its value comes from a shared store.
 */
final class FakeSetting {

	/**
	 * The setting ID.
	 *
	 * @var string
	 */
	public string $id;

	/**
	 * The setting type (`theme_mod` or `option`).
	 *
	 * @var string
	 */
	public string $type;

	/**
	 * The stored values, by setting ID.
	 *
	 * @var \ArrayObject<string, mixed>
	 */
	private \ArrayObject $store;

	/**
	 * Constructor.
	 *
	 * @param string       $id    The setting ID.
	 * @param \ArrayObject $store The stored values.
	 * @param string       $type  The setting type.
	 */
	public function __construct( string $id, \ArrayObject $store, string $type = 'theme_mod' ) {
		$this->id    = $id;
		$this->store = $store;
		$this->type  = $type;
	}

	/**
	 * The stored value.
	 *
	 * @return mixed
	 */
	public function value() {
		return $this->store[ $this->id ] ?? null;
	}

	/**
	 * The parts of the ID.
	 *
	 * @return array{base: string, keys: array<int, string>}
	 */
	public function id_data(): array {
		return [
			'base' => (string) preg_replace( '/\[.*$/', '', $this->id ),
			'keys' => [],
		];
	}
}

/**
 * A Customizer for the tests.
 */
final class FakeManager {

	/**
	 * The settings, by ID.
	 *
	 * @var array<string, FakeSetting>
	 */
	public array $settings = [];

	/**
	 * The controls.
	 *
	 * @var array<string, object>
	 */
	public array $controls = [];

	/**
	 * The changeset: setting ID to parameters.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $changeset = [];

	/**
	 * The changed settings and their new values.
	 *
	 * @return array<string, mixed>
	 */
	public function unsanitized_post_values( array $args = [] ): array {
		$values = [];
		foreach ( $this->changeset as $raw_id => $params ) {
			$values[ (string) preg_replace( '/^.+?::/', '', (string) $raw_id ) ] = $params['value'] ?? null;
		}

		return $values;
	}

	/**
	 * The changeset data.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function changeset_data(): array {
		return $this->changeset;
	}

	/**
	 * A setting.
	 *
	 * @param string $id The setting ID.
	 *
	 * @return FakeSetting|null
	 */
	public function get_setting( string $id ): ?FakeSetting {
		return $this->settings[ $id ] ?? null;
	}

	/**
	 * The controls.
	 *
	 * @return array<string, object>
	 */
	public function controls(): array {
		return $this->controls;
	}

	/**
	 * A section.
	 *
	 * @param string $id The section ID.
	 *
	 * @return object|null
	 */
	public function get_section( string $id ): ?object {
		return 'colors' === $id ? (object) [ 'title' => 'Colors &amp; fonts' ] : null;
	}

	/**
	 * The theme being saved.
	 *
	 * @return string
	 */
	public function get_stylesheet(): string {
		return 'twentyseventeen';
	}
}

/**
 * @covers \Scanfully\Events\ThemeSettings\Collector
 */
final class CollectorTest extends TestCase {

	/**
	 * The stored values the fake settings read.
	 *
	 * @var \ArrayObject<string, mixed>
	 */
	private \ArrayObject $store;

	/**
	 * The signed-in user.
	 *
	 * @var int
	 */
	private int $user = 1;

	/**
	 * Whether cron runs.
	 *
	 * @var bool
	 */
	private bool $cron = false;

	/**
	 * The rows reported.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $reported = [];

	protected function setUp(): void {
		parent::setUp();
		Collector::reset();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->store               = new \ArrayObject();

		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'wp_installing' )->justReturn( false );
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'doing_action' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->alias( fn() => $this->cron );
		Functions\when( 'get_current_user_id' )->alias( fn() => $this->user );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $text ) => strip_tags( (string) $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress is not loaded in unit tests.
		Functions\when( 'get_stylesheet' )->justReturn( 'twentyseventeen' );
		Functions\when( 'get_theme_mod' )->alias( fn( $name, $default = false ) => $this->store[ $name ] ?? $default );
		Functions\when( 'get_theme_support' )->justReturn( 'https://scanfully.com/wp-content/themes/twentyseventeen/assets/images/header.jpg' );
		Functions\when( 'wp_load_alloptions' )->justReturn( [ 'site_logo' => '12' ] );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'maybe_unserialize' )->returnArg();
		Functions\when( 'get_transient' )->justReturn( 0 );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'do_action' )->alias(
			function ( $hook, $row = null ) {
				if ( 'scanfully_content_event_ThemeSettingsChanged' === $hook ) {
					$this->reported[] = $row;
				}
			}
		);
	}

	protected function tearDown(): void {
		Collector::reset();
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tearDown();
	}

	/**
	 * A Customizer with these settings changed from the store's values to
	 * the given ones, and a control per setting.
	 *
	 * @param array<string, mixed> $changes Setting ID to new value.
	 * @param int                  $user    Who changed them.
	 *
	 * @return FakeManager
	 */
	private function manager( array $changes, int $user = 1 ): FakeManager {
		$manager = new FakeManager();
		foreach ( $changes as $id => $value ) {
			$type                       = 0 === strpos( $id, 'blogname' ) || false !== strpos( $id, '[' ) ? 'option' : 'theme_mod';
			$manager->settings[ $id ]   = new FakeSetting( $id, $this->store, $type );
			$manager->changeset[ 'twentyseventeen::' . $id ] = [
				'value'   => $value,
				'type'    => $type,
				'user_id' => $user,
			];
			$manager->controls[ $id ]   = (object) [
				'label'    => 'background_color' === $id ? '<b>Background</b> Color' : '',
				'section'  => 'colors',
				'type'     => 'secret_key' === $id ? 'password' : 'text',
				'settings' => [ 'default' => $manager->settings[ $id ] ],
			];
		}

		return $manager;
	}

	/**
	 * Publish a changeset: note the values before, store the new ones, note
	 * the values after, and flush.
	 *
	 * @param array<string, mixed> $changes Setting ID to new value.
	 * @param int                  $user    Who changed them.
	 *
	 * @return array<string, mixed>|null The row reported.
	 */
	private function publish( array $changes, int $user = 1 ): ?array {
		$manager = $this->manager( $changes, $user );
		Collector::note_publish_start( $manager );
		foreach ( $changes as $id => $value ) {
			$this->store[ $id ] = $value;
		}
		Collector::note_publish_done( $manager );
		Collector::flush();

		return $this->reported[0] ?? null;
	}

	public function test_a_customizer_publish_lists_each_setting_with_its_label_and_values(): void {
		$this->store['background_color'] = 'ffffff';
		$this->store['header_textcolor'] = '333333';

		$row = $this->publish(
			[
				'background_color' => '1e73be',
				'header_textcolor' => 'blank',
			]
		);

		$this->assertSame( 'customizer', $row['source'] );
		$this->assertSame( 'twentyseventeen', $row['stylesheet'] );
		$this->assertSame(
			[
				'id'      => 'background_color',
				'label'   => 'Background Color',
				'section' => 'Colors & fonts',
				'kind'    => 'color',
				'change'  => 'changed',
				'old'     => 'ffffff',
				'new'     => '1e73be',
			],
			$row['settings'][0]
		);
		$this->assertSame( 'blank', $row['settings'][1]['new'] );
		$this->assertArrayNotHasKey( '_actor', $row, 'The signed-in user published it.' );
	}

	public function test_the_header_image_is_read_from_its_theme_mod(): void {
		$this->store['header_image'] = 'remove-header';

		$manager = $this->manager(
			[
				'header_image'      => 'https://scanfully.com/wp-content/uploads/header.jpg',
				'header_image_data' => [ 'attachment_id' => 5 ],
			]
		);
		Collector::note_publish_start( $manager );
		$this->store['header_image'] = 'https://scanfully.com/wp-content/uploads/header.jpg';
		Collector::note_publish_done( $manager );
		Collector::flush();

		$this->assertSame(
			[
				[
					'id'      => 'header_image',
					'section' => 'Colors & fonts',
					'kind'    => 'media',
					'change'  => 'changed',
					'old'     => 'remove-header',
					'new'     => 'https://scanfully.com/wp-content/uploads/header.jpg',
				],
			],
			$this->reported[0]['settings']
		);
	}

	public function test_the_control_named_after_the_setting_gives_its_label(): void {
		$this->store['header_textcolor'] = '333333';
		$manager                         = $this->manager( [ 'header_textcolor' => '1e73be' ] );
		// Core's "Display Site Title and Tagline" checkbox edits the same
		// setting, and comes first.
		$manager->controls = [
			'display_header_text' => (object) [
				'id'       => 'display_header_text',
				'label'    => 'Display Site Title and Tagline',
				'section'  => 'title_tagline',
				'type'     => 'checkbox',
				'settings' => [ 'default' => $manager->settings['header_textcolor'] ],
			],
			'header_textcolor'    => (object) [
				'id'       => 'header_textcolor',
				'label'    => 'Header Text Color',
				'section'  => 'colors',
				'type'     => 'color',
				'settings' => [ 'default' => $manager->settings['header_textcolor'] ],
			],
		];

		Collector::note_publish_start( $manager );
		$this->store['header_textcolor'] = '1e73be';
		Collector::note_publish_done( $manager );
		Collector::flush();

		$this->assertSame( 'Header Text Color', $this->reported[0]['settings'][0]['label'] );
		$this->assertSame( 'Colors & fonts', $this->reported[0]['settings'][0]['section'] );
	}

	public function test_a_page_choice_is_marked_for_its_title(): void {
		$this->store['panel_1'] = 0;
		$manager                = $this->manager( [ 'panel_1' => 16 ] );
		$manager->controls['panel_1']->type = 'dropdown-pages';

		Collector::note_publish_start( $manager );
		$this->store['panel_1'] = 16;
		Collector::note_publish_done( $manager );
		Collector::flush();

		$this->assertSame( 'page', $this->reported[0]['settings'][0]['kind'] );
	}

	public function test_an_unset_header_image_was_the_themes_default(): void {
		$manager = $this->manager( [ 'header_image' => 'https://scanfully.com/wp-content/uploads/header.jpg' ] );
		Collector::note_publish_start( $manager );
		$this->store['header_image'] = 'https://scanfully.com/wp-content/uploads/header.jpg';
		Collector::note_publish_done( $manager );
		Collector::flush();

		$this->assertSame( 'https://scanfully.com/wp-content/themes/twentyseventeen/assets/images/header.jpg', $this->reported[0]['settings'][0]['old'] );
	}

	public function test_settings_other_rows_report_are_left_out(): void {
		$this->store['background_color'] = 'ffffff';

		$row = $this->publish(
			[
				'background_color'          => '000000',
				'blogname'                  => 'New title',
				'nav_menu_locations[top]'   => 3,
				'sidebars_widgets[footer]'  => [],
				'widget_text[2]'            => [],
				'custom_css[twentyseventeen]' => 'body{}',
				'header_image_data'         => [ 'attachment_id' => 5 ],
			]
		);

		$this->assertSame( [ 'background_color' ], array_column( $row['settings'], 'id' ) );
	}

	public function test_a_setting_saved_with_the_same_value_is_no_change(): void {
		$this->store['background_color'] = 'ffffff';

		$this->assertNull( $this->publish( [ 'background_color' => 'ffffff' ] ) );
	}

	public function test_the_logo_setting_is_reported_as_the_logo(): void {
		$this->store['custom_logo'] = 12;

		$row = $this->publish( [ 'custom_logo' => 40 ] );

		$this->assertSame(
			[
				'old' => '12',
				'new' => '40',
			],
			$row['logo']
		);
		$this->assertArrayNotHasKey( 'settings', $row );
	}

	public function test_long_values_are_only_marked_large_and_secrets_never_sent(): void {
		$this->store['footer_text'] = str_repeat( 'a', 300 );
		$this->store['secret_key']  = 'old-secret';

		$row = $this->publish(
			[
				'footer_text' => str_repeat( 'b', 300 ),
				'secret_key'  => 'new-secret',
			]
		);

		$this->assertSame(
			[
				'id'      => 'footer_text',
				'section' => 'Colors & fonts',
				'change'  => 'changed',
				'large'   => true,
			],
			$row['settings'][0]
		);
		$this->assertTrue( $row['settings'][1]['secret'] );
		$this->assertStringNotContainsString(
			'secret',
			(string) json_encode(
				array_diff_key(
					$row['settings'][1],
					[
						'secret' => 1,
						'id' => 1,
					]
				)
			)
		); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
	}

	public function test_a_scheduled_publish_names_the_person_who_made_it(): void {
		$this->store['background_color'] = 'ffffff';
		$this->user                      = 0;
		$this->cron                      = true;

		$row = $this->publish( [ 'background_color' => '000000' ], 7 );

		$this->assertSame( 7, $row['_actor'] );
		$this->assertTrue( $row['scheduled'] );
	}

	public function test_theme_mods_changed_outside_the_customizer(): void {
		Collector::note_updated(
			'theme_mods_twentyseventeen',
			[
				'background_color'   => 'ffffff',
				'nav_menu_locations' => [ 'top' => 2 ],
				'custom_logo'        => 12,
				'old_setting'        => 'x',
			],
			[
				'background_color'   => '000000',
				'nav_menu_locations' => [ 'top' => 3 ],
				'custom_logo'        => 40,
				'header_image'       => 'https://scanfully.com/wp-content/uploads/header.jpg',
			]
		);
		Collector::flush();
		$row = $this->reported[0];

		$this->assertSame( 'other', $row['source'] );
		$this->assertSame( [ 'background_color', 'old_setting', 'header_image' ], array_column( $row['settings'], 'id' ) );
		$this->assertSame( 'removed', $row['settings'][1]['change'] );
		$this->assertSame( 'media', $row['settings'][2]['kind'] );
	}

	public function test_theme_mods_of_another_theme_are_left_out(): void {
		Collector::note_updated( 'theme_mods_twentytwentyone', [ 'background_color' => 'ffffff' ], [ 'background_color' => '000000' ] );
		Collector::flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_page_views_change_nothing(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		Collector::note_updated( 'theme_mods_twentyseventeen', [], [ 'background_color' => '000000' ] );
		Collector::note_updated( 'site_logo', 12, 40 );
		Collector::flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_a_customizer_publish_ignores_the_theme_mods_it_writes_itself(): void {
		$this->store['background_color'] = 'ffffff';
		$manager                         = $this->manager( [ 'background_color' => '000000' ] );

		Collector::note_publish_start( $manager );
		$this->store['background_color'] = '000000';
		Collector::note_updated(
			'theme_mods_twentyseventeen',
			[ 'background_color' => 'ffffff' ],
			[
				'background_color' => '000000',
				'theme_css_cache' => 'abc',
			]
		);
		Collector::note_publish_done( $manager );
		Collector::flush();

		$this->assertSame( [ 'background_color' ], array_column( $this->reported[0]['settings'], 'id' ) );
	}

	public function test_the_logo_option_set_and_removed(): void {
		Collector::note_added( 'site_logo', '40' );
		Collector::flush();
		$this->assertSame(
			[
				'old' => '',
				'new' => '40',
			],
			$this->reported[0]['logo']
		);

		Collector::note_deleting( 'site_logo' );
		Collector::flush();
		$this->assertSame(
			[
				'old' => '12',
				'new' => '',
			],
			$this->reported[1]['logo']
		);
	}

	public function test_additional_css_is_kept_for_the_job(): void {
		$before = (object) [
			'post_type'    => 'custom_css',
			'post_title'   => 'twentyseventeen',
			'post_content' => 'body { color: red; }',
		];
		$after  = (object) [
			'post_type'    => 'custom_css',
			'post_title'   => 'twentyseventeen',
			'post_content' => 'body { color: blue; }',
		];

		Collector::note_post_saved( 9, $after, true, $before );
		Collector::flush();

		$this->assertSame(
			[
				'stash' => [
					'old' => 'body { color: red; }',
					'new' => 'body { color: blue; }',
				],
			],
			$this->reported[0]['css']
		);
	}

	public function test_very_large_css_is_only_reported_as_changed(): void {
		$after = (object) [
			'post_type'    => 'custom_css',
			'post_title'   => 'twentyseventeen',
			'post_content' => str_repeat( 'a', Collector::MAX_CSS + 1 ),
		];

		Collector::note_post_saved( 9, $after, false, null );
		Collector::flush();

		$this->assertSame( [ 'too_large' => true ], $this->reported[0]['css'] );
	}

	public function test_other_posts_are_not_css(): void {
		Collector::note_post_saved(
			9,
			(object) [
				'post_type'    => 'page',
				'post_content' => 'x',
			],
			true,
			null
		);
		Collector::flush();

		$this->assertSame( [], $this->reported );
	}
}
