<?php
/**
 * Site Editor activity integration tests.
 *
 * Saves styles, templates, template parts and fonts of a block theme
 * (Twenty Twenty-Five) through the REST API, as the Site Editor does, and
 * checks the events sent. Runs against real WordPress (wp-env) and skips
 * otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Events\Controller as EventsController;
use Scanfully\Events\PostChanges\Collector as PostCollector;
use Scanfully\Events\SiteEditor\Collector;

/**
 * Checks the StylesChanged, Template* and Font* events.
 */
final class SiteEditorEventsTest extends TestCase {

	/**
	 * The block theme the tests use.
	 */
	private const THEME = 'twentytwentyfive';

	/**
	 * The Site Editor's post types, whose posts a test may leave.
	 */
	private const POST_TYPES = [ 'wp_template', 'wp_template_part', 'wp_font_family', 'wp_font_face', 'wp_global_styles' ];

	/**
	 * Posts of those types before the test.
	 *
	 * @var array<int, int>
	 */
	private array $existing = [];

	/**
	 * Event bodies sent to the API.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'rest_do_request' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'WordPress not available. Run this suite under wp-env.' );
		}
		if ( self::THEME !== get_stylesheet() ) {
			$this->markTestSkipped( 'Twenty Twenty-Five is not the active theme.' );
		}

		update_option( 'scanfully_connect_is_connected', 'yes' );
		wp_set_current_user( 1 );
		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );
		add_filter( 'scanfully_site_editor_events_per_minute', '__return_zero' );
		$this->existing = $this->site_editor_posts();
		$this->forget();
	}

	protected function tearDown(): void {
		if ( function_exists( 'wp_delete_post' ) ) {
			remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
			remove_filter( 'scanfully_site_editor_events_per_minute', '__return_zero' );
			foreach ( array_diff( $this->site_editor_posts(), $this->existing ) as $id ) {
				wp_delete_post( $id, true );
			}
			\WP_Theme_JSON_Resolver::clean_cached_data();
			$this->forget();
			delete_option( 'scanfully_connect_is_connected' );
			wp_set_current_user( 0 );
		}
		parent::tearDown();
	}

	/**
	 * The IDs of the posts of the Site Editor's post types.
	 *
	 * @return array<int, int>
	 */
	private function site_editor_posts(): array {
		return array_map(
			'intval',
			get_posts(
				[
					'post_type'   => self::POST_TYPES,
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				]
			)
		);
	}

	/**
	 * `pre_http_request`: keep the event sent to the API and accept it.
	 *
	 * @param mixed  $preempt Whether to preempt the request.
	 * @param array  $args    The request arguments.
	 * @param string $url     The URL.
	 *
	 * @return mixed
	 */
	public function capture_request( $preempt, $args, $url ) {
		if ( false === strpos( (string) $url, '/timeline' ) ) {
			return $preempt;
		}

		$this->sent[] = json_decode( (string) ( $args['body'] ?? '' ), true );

		return [
			'headers'  => [],
			'body'     => '',
			'response' => [
				'code'    => 202,
				'message' => 'Accepted',
			],
			'cookies'  => [],
		];
	}

	/**
	 * Forget what the setup of a test noted and sent.
	 *
	 * @return void
	 */
	private function forget(): void {
		Collector::reset();
		PostCollector::reset();
		self::clear_minute_store();
		as_unschedule_all_actions( '', [], 'scanfully' );
		$this->sent = [];
	}

	/**
	 * Remove what earlier runs left in the minute store.
	 *
	 * @return void
	 */
	private static function clear_minute_store(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test cleanup.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'scanfully_minute_' ) . '%', $wpdb->esc_like( '_transient_scanfully_minute_' ) . '%' ) );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * A REST request, as the Site Editor makes it; fails the test on an
	 * error.
	 *
	 * @param string               $method The method.
	 * @param string               $route  The route.
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return array<string, mixed>
	 */
	private function rest( string $method, string $route, array $params = [] ): array {
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		$this->assertFalse( $response->is_error(), $method . ' ' . $route . ': ' . wp_json_encode( $response->get_data() ) );

		return (array) $response->get_data();
	}

	/**
	 * End the request (the collector's `shutdown`) and run the jobs it
	 * scheduled, as Action Scheduler would; return the events sent.
	 *
	 * @return array<int, array{type: string, data: array<string, mixed>}>
	 */
	private function end_request(): array {
		Collector::flush();
		PostCollector::flush();

		$hooks = [ EventsController::ACTION_BUILD_CONTENT_EVENT, EventsController::ACTION_BUILD_POST_EVENT, EventsController::ACTION_SEND_FONTS ];
		for ( $round = 0; $round < 3; $round++ ) {
			$actions = as_get_scheduled_actions(
				[
					'hook__in' => $hooks,
					'group'    => 'scanfully',
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
					'orderby'  => 'none',
				]
			);
			if ( [] === $actions ) {
				break;
			}
			foreach ( $actions as $id => $action ) {
				\ActionScheduler::store()->mark_complete( $id );
				do_action_ref_array( $action->get_hook(), array_values( $action->get_args() ) );
			}
		}

		$sent       = $this->sent;
		$this->sent = [];

		return array_values(
			array_map(
				static fn( array $body ): array => [
					'type' => (string) $body['type'],
					'data' => (array) $body['data'],
				],
				array_filter( $sent, static fn( array $body ): bool => 'PostSaved' !== ( $body['type'] ?? '' ) )
			)
		);
	}

	/**
	 * The global styles post of the theme, made the way the Site Editor
	 * makes it, with what that sent forgotten.
	 *
	 * @return int
	 */
	private function styles_post(): int {
		// Core keeps the post's ID for the request; an earlier test removed it.
		\WP_Theme_JSON_Resolver::clean_cached_data();
		$id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$this->end_request();

		return $id;
	}

	/**
	 * The lines of a diff with a given op.
	 *
	 * @param array<string, mixed> $diff The diff.
	 * @param string               $op   `+`, `-` or `=`.
	 *
	 * @return array<int, string>
	 */
	private static function lines( array $diff, string $op ): array {
		$lines = [];
		foreach ( (array) ( $diff['lines'] ?? [] ) as $line ) {
			if ( $op === ( $line['op'] ?? '' ) ) {
				$lines[] = trim( (string) $line['text'] );
			}
		}

		return $lines;
	}

	public function test_the_styles_post_core_makes_when_the_site_editor_opens_is_not_reported(): void {
		foreach ( get_posts(
			[
				'post_type'   => 'wp_global_styles',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			]
		) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		\WP_Theme_JSON_Resolver::clean_cached_data();

		$this->assertGreaterThan( 0, \WP_Theme_JSON_Resolver::get_user_global_styles_post_id() );
		$this->assertSame( [], $this->end_request() );
	}

	public function test_a_styles_change_lists_each_setting_and_the_css(): void {
		$id = $this->styles_post();

		$this->rest(
			'POST',
			'/wp/v2/global-styles/' . $id,
			[
				'styles'   => [
					'color'    => [ 'background' => '#ffeedd' ],
					'elements' => [ 'link' => [ 'color' => [ 'text' => '#1e73be' ] ] ],
					'css'      => 'body { margin: 0; }',
				],
				'settings' => new \stdClass(),
			]
		);
		$events = $this->end_request();

		$this->assertCount( 1, $events );
		$this->assertSame( 'StylesChanged', $events[0]['type'] );
		$this->assertSame( 'Twenty Twenty-Five', $events[0]['data']['name'] );
		$styles = array_column( (array) $events[0]['data']['changes']['styles'], null, 'path' );
		$this->assertSame(
			[
				'path'   => 'styles.color.background',
				'change' => 'added',
				'new'    => '#ffeedd',
			],
			$styles['styles.color.background']
		);
		$this->assertSame( '#1e73be', $styles['styles.elements.link.color.text']['new'] );
		$this->assertSame( [ 'body { margin: 0; }' ], self::lines( $events[0]['data']['changes']['css'], '+' ) );

		$this->rest(
			'POST',
			'/wp/v2/global-styles/' . $id,
			[
				'styles'   => [ 'color' => [ 'background' => '#000000' ] ],
				'settings' => new \stdClass(),
			]
		);
		$changes = $this->end_request()[0]['data']['changes'];
		$styles  = array_column( (array) $changes['styles'], null, 'path' );

		$this->assertSame(
			[
				'path'   => 'styles.color.background',
				'change' => 'changed',
				'old'    => '#ffeedd',
				'new'    => '#000000',
			],
			$styles['styles.color.background']
		);
		$this->assertSame( 'removed', $styles['styles.elements.link.color.text']['change'] );
		$this->assertSame( [ 'body { margin: 0; }' ], self::lines( $changes['css'], '-' ) );
	}

	public function test_a_style_variation_is_named_and_a_reset_is_one_change(): void {
		$id         = $this->styles_post();
		$variations = $this->rest( 'GET', '/wp/v2/global-styles/themes/' . self::THEME . '/variations' );
		$variation  = (array) ( $variations[1] ?? $variations[0] );

		$this->rest(
			'POST',
			'/wp/v2/global-styles/' . $id,
			[
				'styles'   => $variation['styles'] ?? new \stdClass(),
				'settings' => $variation['settings'] ?? new \stdClass(),
				'title'    => $variation['title'],
			]
		);
		$events = $this->end_request();

		$this->assertSame( $variation['title'], $events[0]['data']['changes']['variation']['new'] );

		// The Site Editor itself saves only the variation's styles and
		// settings, without its title.
		$other = (array) ( $variations[2] ?? $variations[0] );
		$this->rest(
			'POST',
			'/wp/v2/global-styles/' . $id,
			[
				'styles'   => $other['styles'] ?? new \stdClass(),
				'settings' => $other['settings'] ?? new \stdClass(),
			]
		);
		$events = $this->end_request();

		$this->assertSame( $other['title'], $events[0]['data']['changes']['variation']['new'] );

		$this->rest(
			'POST',
			'/wp/v2/global-styles/' . $id,
			[
				'styles'   => new \stdClass(),
				'settings' => new \stdClass(),
			]
		);

		$this->assertSame( [ 'reset' => true ], $this->end_request()[0]['data']['changes'] );
	}

	public function test_a_first_customization_is_compared_with_the_theme_file_and_a_reset_back(): void {
		$template = $this->rest(
			'GET',
			'/wp/v2/templates/' . self::THEME . '//page',
			[ 'context' => 'edit' ]
		);
		$content  = (string) $template['content']['raw'];

		$this->rest( 'POST', '/wp/v2/templates/' . self::THEME . '//page', [ 'content' => '<!-- wp:paragraph --><p>Opening hours</p><!-- /wp:paragraph -->' . $content ] );
		$events = $this->end_request();

		$this->assertSame( 'TemplateChanged', $events[0]['type'] );
		$data = $events[0]['data'];
		$this->assertTrue( $data['first_customization'] );
		$this->assertSame( 'template', $data['kind'] );
		$this->assertSame( 'page', $data['slug'] );
		$this->assertSame( 'theme', $data['source'] );
		$this->assertSame( [ 'paragraph: Opening hours' ], self::lines( $data['changes']['blocks'], '+' ) );
		$this->assertSame( [], self::lines( $data['changes']['blocks'], '-' ), 'The theme file and the editor agree on the rest, patterns included.' );

		$this->rest( 'POST', '/wp/v2/templates/' . self::THEME . '//page', [ 'source' => 'theme' ] );
		$events = $this->end_request();

		$this->assertSame( 'TemplateReset', $events[0]['type'] );
		$this->assertSame( [ 'paragraph: Opening hours' ], self::lines( $events[0]['data']['changes']['blocks'], '-' ) );
	}

	public function test_a_later_edit_reports_the_title_and_blocks_and_an_unchanged_save_nothing(): void {
		$template = $this->rest( 'GET', '/wp/v2/templates/' . self::THEME . '//page', [ 'context' => 'edit' ] );
		$content  = (string) $template['content']['raw'];
		$this->rest( 'POST', '/wp/v2/templates/' . self::THEME . '//page', [ 'content' => $content ] );
		$this->end_request();

		$this->rest(
			'POST',
			'/wp/v2/templates/' . self::THEME . '//page',
			[
				'title'   => 'Pages (shop)',
				'content' => $content . '<!-- wp:heading --><h2>Visit us</h2><!-- /wp:heading -->',
			]
		);
		$events = $this->end_request();

		$this->assertSame( 'TemplateChanged', $events[0]['type'] );
		$this->assertArrayNotHasKey( 'first_customization', $events[0]['data'] );
		$this->assertSame( 'Pages (shop)', $events[0]['data']['name'] );
		$this->assertSame( 'Pages (shop)', $events[0]['data']['changes']['title']['new'] );
		$this->assertSame( [ 'heading: Visit us' ], self::lines( $events[0]['data']['changes']['blocks'], '+' ) );

		$current = $this->rest( 'GET', '/wp/v2/templates/' . self::THEME . '//page', [ 'context' => 'edit' ] );
		$this->rest( 'POST', '/wp/v2/templates/' . self::THEME . '//page', [ 'content' => (string) $current['content']['raw'] ] );

		$this->assertSame( [], $this->end_request() );
	}

	public function test_a_custom_template_is_created_and_deleted_or_trashed(): void {
		$created = $this->rest(
			'POST',
			'/wp/v2/templates',
			[
				'slug'    => 'page-opening-hours',
				'title'   => 'Page: Opening hours',
				'content' => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:post-content /-->',
			]
		);
		$events  = $this->end_request();

		$this->assertSame( 'TemplateCreated', $events[0]['type'] );
		$this->assertSame( 'Page: Opening hours', $events[0]['data']['name'] );
		$this->assertSame( 'custom', $events[0]['data']['source'] );
		$this->assertSame( 2, $events[0]['data']['blocks'] );

		$this->rest( 'DELETE', '/wp/v2/templates/' . $created['id'], [ 'force' => true ] );
		$events = $this->end_request();

		$this->assertSame( 'TemplateDeleted', $events[0]['type'] );
		$this->assertSame( 'page-opening-hours', $events[0]['data']['slug'] );

		$trashed = $this->rest(
			'POST',
			'/wp/v2/templates',
			[
				'slug'  => 'page-contact',
				'title' => 'Page: Contact',
			]
		);
		$this->end_request();
		$this->rest( 'DELETE', '/wp/v2/templates/' . $trashed['id'] );

		$this->assertSame( [ 'TemplateDeleted' ], array_column( $this->end_request(), 'type' ) );
	}

	public function test_a_template_part_has_its_area(): void {
		$part = $this->rest( 'GET', '/wp/v2/template-parts/' . self::THEME . '//footer', [ 'context' => 'edit' ] );

		$this->rest( 'POST', '/wp/v2/template-parts/' . self::THEME . '//footer', [ 'content' => (string) $part['content']['raw'] . '<!-- wp:paragraph --><p>Closed on Sundays</p><!-- /wp:paragraph -->' ] );
		$events = $this->end_request();

		$this->assertSame( 'TemplateChanged', $events[0]['type'] );
		$this->assertSame( 'template_part', $events[0]['data']['kind'] );
		$this->assertSame( 'footer', $events[0]['data']['area'] );
		$this->assertSame( [ 'paragraph: Closed on Sundays' ], self::lines( $events[0]['data']['changes']['blocks'], '+' ) );
	}

	public function test_a_font_installed_in_several_requests_is_one_row_and_removals_are_reported(): void {
		$family = $this->rest(
			'POST',
			'/wp/v2/font-families',
			[
				'font_family_settings' => wp_json_encode(
					[
						'name'       => 'Scanfully Sans',
						'slug'       => 'scanfully-sans',
						'fontFamily' => 'Scanfully Sans, sans-serif',
					]
				),
			]
		);
		Collector::flush();
		$faces = [];
		foreach ( [ [ 'normal', '400' ], [ 'italic', '400' ], [ 'normal', '700' ] ] as [ $style, $weight ] ) {
			$faces[] = $this->rest(
				'POST',
				'/wp/v2/font-families/' . $family['id'] . '/font-faces',
				[
					'font_face_settings' => wp_json_encode(
						[
							'fontFamily' => 'Scanfully Sans',
							'fontStyle'  => $style,
							'fontWeight' => $weight,
							'src'        => 'https://scanfully.com/fonts/scanfully-sans-' . $weight . $style . '.woff2',
						]
					),
				]
			);
			Collector::flush();
		}
		$events = $this->end_request();

		$this->assertCount( 1, $events );
		$this->assertSame( 'FontInstalled', $events[0]['type'] );
		$this->assertSame(
			[
				'name'     => 'Scanfully Sans',
				'slug'     => 'scanfully-sans',
				'family'   => true,
				'variants' => [
					[
						'style'  => 'normal',
						'weight' => '400',
					],
					[
						'style'  => 'italic',
						'weight' => '400',
					],
					[
						'style'  => 'normal',
						'weight' => '700',
					],
				],
			],
			$events[0]['data']
		);

		$this->rest( 'DELETE', '/wp/v2/font-families/' . $family['id'] . '/font-faces/' . $faces[2]['id'], [ 'force' => true ] );
		$events = $this->end_request();

		$this->assertSame( 'FontDeleted', $events[0]['type'] );
		$this->assertFalse( $events[0]['data']['family'] );
		$this->assertSame( '700', $events[0]['data']['variants'][0]['weight'] );

		$this->rest( 'DELETE', '/wp/v2/font-families/' . $family['id'], [ 'force' => true ] );
		$events = $this->end_request();

		$this->assertSame( 'FontDeleted', $events[0]['type'] );
		$this->assertTrue( $events[0]['data']['family'] );
		$this->assertCount( 2, $events[0]['data']['variants'] );
	}
}
