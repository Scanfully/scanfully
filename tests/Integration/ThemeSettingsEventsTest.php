<?php
/**
 * Theme settings event integration tests: what a Customizer publish, a
 * scheduled publish, code, the REST API and Additional CSS report, run
 * through the real WordPress hooks and the background job.
 *
 * Runs in wp-env and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Events\Controller as EventsController;
use Scanfully\Events\PostChanges\Collector as PostCollector;
use Scanfully\Events\SettingsChanges\Collector as SettingsCollector;
use Scanfully\Events\ThemeSettings\Collector;

/**
 * Verifies the ThemeSettingsChanged events sent.
 */
final class ThemeSettingsEventsTest extends TestCase {

	/**
	 * The classic theme the tests use.
	 */
	private const THEME = 'twentyseventeen';

	/**
	 * Options to restore after a test, with their value before it (null
	 * when the option didn't exist).
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * Posts created by a test.
	 *
	 * @var array<int, int>
	 */
	private array $posts = [];

	/**
	 * Event bodies sent to the API.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'update_option' ) ) {
			$this->markTestSkipped( 'WordPress not available. Run this suite under wp-env.' );
		}
		if ( ! wp_get_theme( self::THEME )->exists() ) {
			$this->markTestSkipped( 'Twenty Seventeen is not installed.' );
		}
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';

		// The classic theme, set without switch_theme(): a theme switch makes
		// the rest of the request quiet, and nothing resets that.
		$this->restore_later( 'template', 'stylesheet', 'theme_mods_' . self::THEME, Collector::LOGO_OPTION );
		update_option( 'template', self::THEME );
		update_option( 'stylesheet', self::THEME );
		delete_option( 'theme_mods_' . self::THEME );
		delete_option( Collector::LOGO_OPTION );
		// Twenty Seventeen's features. Core fills in their defaults while
		// the page loads, which has passed, so they are given in full; they
		// stay for the rest of the run (core can't remove a custom
		// background it never set up).
		if ( ! current_theme_supports( 'custom-header' ) ) {
			add_theme_support( 'custom-logo' );
			add_theme_support(
				'custom-header',
				[
					'video'              => true,
					'header-text'        => true,
					'uploads'            => true,
					'default-text-color' => '',
					'width'              => 2000,
					'height'             => 1200,
				]
			);
			add_theme_support( 'custom-background', [ 'default-color' => '' ] );
		}

		update_option( 'scanfully_connect_is_connected', 'yes' );
		wp_set_current_user( 1 );
		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );
		add_filter( 'scanfully_theme_settings_events_per_minute', '__return_zero' );
		unset( $GLOBALS['wp_customize'] );
		$this->forget();
	}

	protected function tearDown(): void {
		if ( function_exists( 'update_option' ) ) {
			foreach ( $this->posts as $id ) {
				wp_delete_post( $id, true );
			}
			foreach ( $this->options as $option => $value ) {
				if ( null === $value ) {
					delete_option( $option );
				} else {
					update_option( $option, $value );
				}
			}
			remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
			remove_filter( 'scanfully_theme_settings_events_per_minute', '__return_zero' );
			remove_filter( 'wp_doing_cron', '__return_true' );
			unset( $GLOBALS['wp_customize'], $_SERVER['REQUEST_METHOD'] );
			$this->forget();
			delete_option( 'scanfully_connect_is_connected' );
			wp_set_current_user( 0 );
		}
		parent::tearDown();
	}

	/**
	 * Forget what the collectors noted and the jobs they queued.
	 */
	private function forget(): void {
		Collector::reset();
		SettingsCollector::reset();
		PostCollector::reset();
		as_unschedule_all_actions( '', [], 'scanfully' );
		$this->sent = [];
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
	 * Remember options' values so tearDown restores them.
	 *
	 * @param string ...$options The options.
	 */
	private function restore_later( string ...$options ): void {
		foreach ( $options as $option ) {
			if ( ! array_key_exists( $option, $this->options ) ) {
				$this->options[ $option ] = get_option( $option, null );
			}
		}
	}

	/**
	 * An image attachment with a file name.
	 *
	 * @param string $file The file name.
	 *
	 * @return int
	 */
	private function image( string $file ): int {
		$id            = wp_insert_attachment(
			[
				'post_mime_type' => 'image/png',
				'post_title'     => $file,
				'post_status'    => 'inherit',
			],
			$file
		);
		$this->posts[] = $id;

		return $id;
	}

	/**
	 * A Customizer for the classic theme, with core's settings and
	 * controls, as the publish request has it.
	 *
	 * @param string $uuid The changeset UUID, or '' for a new one.
	 *
	 * @return \WP_Customize_Manager
	 */
	private function customizer( string $uuid = '' ): \WP_Customize_Manager {
		$args = [ 'settings_previewed' => false ];
		if ( '' !== $uuid ) {
			$args['changeset_uuid'] = $uuid;
		}
		$manager = new \WP_Customize_Manager( $args );
		$manager->register_controls();
		$GLOBALS['wp_customize'] = $manager;

		return $manager;
	}

	/**
	 * Publish settings in the Customizer.
	 *
	 * @param array<string, mixed> $values Setting ID to value.
	 *
	 * @return void
	 */
	private function publish( array $values ): void {
		$data = [];
		foreach ( $values as $id => $value ) {
			$data[ $id ] = [ 'value' => $value ];
		}
		$result = $this->customizer()->save_changeset_post(
			[
				'data'   => $data,
				'status' => 'publish',
			]
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * End the "request" and run the jobs, as Action Scheduler would; return
	 * the ThemeSettingsChanged events sent: user and data.
	 *
	 * @return array<int, array{user: array<string, mixed>, data: array<string, mixed>}>
	 */
	private function sent_events(): array {
		Collector::flush();

		$actions = as_get_scheduled_actions(
			[
				'hook__in' => [ EventsController::ACTION_BUILD_CONTENT_EVENT ],
				'group'    => 'scanfully',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			]
		);
		foreach ( $actions as $id => $action ) {
			\ActionScheduler::store()->mark_complete( $id );
			do_action_ref_array( $action->get_hook(), array_values( $action->get_args() ) );
		}

		$events = [];
		foreach ( $this->sent as $body ) {
			if ( 'ThemeSettingsChanged' === ( $body['type'] ?? '' ) ) {
				$events[] = [
					'user' => (array) $body['user'],
					'data' => (array) $body['data'],
				];
			}
		}

		return $events;
	}

	/**
	 * The settings of an event by ID.
	 *
	 * @param array<string, mixed> $data The event data.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function settings( array $data ): array {
		return array_column( (array) ( $data['settings'] ?? [] ), null, 'id' );
	}

	public function test_a_customizer_publish_reports_colours_with_their_labels(): void {
		set_theme_mod( 'background_color', 'ffffff' );
		$this->forget();

		$this->publish(
			[
				'background_color' => '#1e73be',
				'header_textcolor' => 'blank',
				'blogdescription'  => 'Reported as a setting',
			]
		);
		$events = $this->sent_events();

		$this->assertCount( 1, $events );
		$data = $events[0]['data'];
		$this->assertSame( 'customizer', $data['source'] );
		$this->assertSame( 'Twenty Seventeen', $data['name'] );
		$settings = self::settings( $data );
		$this->assertSame( [ 'background_color', 'header_textcolor' ], array_keys( $settings ) );
		$this->assertSame( 'Background Color', $settings['background_color']['label'] );
		$this->assertSame( 'color', $settings['background_color']['kind'] );
		$this->assertSame( 'ffffff', $settings['background_color']['old'] );
		$this->assertSame( '1e73be', $settings['background_color']['new'] );
		$this->assertSame( 'blank', $settings['header_textcolor']['new'] );
		$this->assertSame( 1, (int) $events[0]['user']['id'] );
	}

	public function test_the_header_image_from_the_customizer_is_one_change_with_its_file(): void {
		$image = $this->image( 'header-new.jpg' );
		$url   = 'http://localhost:8889/wp-content/uploads/header-new.jpg';

		$this->publish(
			[
				'header_image'      => $url,
				'header_image_data' => [
					'url'           => $url,
					'thumbnail_url' => $url,
					'attachment_id' => $image,
					'width'         => 2000,
					'height'        => 1200,
				],
			]
		);
		$settings = self::settings( $this->sent_events()[0]['data'] );

		$this->assertSame( [ 'header_image' ], array_keys( $settings ) );
		$this->assertSame( 'media', $settings['header_image']['kind'] );
		$this->assertSame( $url, $settings['header_image']['new'] );
		$this->assertSame( 'header-new.jpg', $settings['header_image']['new_file'] );
	}

	public function test_the_logo_from_the_customizer_names_its_file(): void {
		$logo = $this->image( 'logo-new.png' );

		$this->publish( [ 'custom_logo' => $logo ] );
		$data = $this->sent_events()[0]['data'];

		$this->assertSame(
			[
				'id'   => (string) $logo,
				'file' => 'logo-new.png',
			],
			$data['logo']['new']
		);
		$this->assertArrayNotHasKey( 'settings', $data );
	}

	public function test_additional_css_from_the_customizer_is_sent_as_lines(): void {
		$this->posts[] = wp_update_custom_css_post( ".site-header {\n\tpadding: 20px;\n}" )->ID;
		$this->forget();

		$this->publish( [ 'custom_css[' . self::THEME . ']' => ".site-header {\n\tpadding: 32px;\n}" ] );
		$data = $this->sent_events()[0]['data'];

		$this->assertSame( 1, $data['css']['added'] );
		$this->assertSame( 1, $data['css']['removed'] );
		$this->assertContains( 'padding: 32px;', array_map( 'trim', array_column( $data['css']['lines'], 'text' ) ) );
		$this->assertArrayNotHasKey( 'settings', $data, 'The CSS setting is the css field, not a setting.' );
	}

	public function test_a_scheduled_change_is_reported_when_cron_publishes_it_by_its_author(): void {
		set_theme_mod( 'background_color', 'ffffff' );
		$this->forget();

		$manager = $this->customizer();
		$result  = $manager->save_changeset_post(
			[
				'data'     => [ 'background_color' => [ 'value' => '#000000' ] ],
				'status'   => 'future',
				'date_gmt' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			]
		);
		$this->assertIsArray( $result );
		$this->assertSame( [], $this->sent_events(), 'Nothing changed yet.' );

		// Later, cron publishes it, signed out.
		wp_set_current_user( 0 );
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->customizer( $manager->changeset_uuid() );
		wp_publish_post( $manager->changeset_post_id() );
		$events = $this->sent_events();

		$this->assertCount( 1, $events );
		$this->assertTrue( $events[0]['data']['scheduled'] );
		$this->assertArrayNotHasKey( '_actor', $events[0]['data'] );
		$this->assertSame( 1, (int) $events[0]['user']['id'] );
		$this->assertSame( '000000', self::settings( $events[0]['data'] )['background_color']['new'] );
	}

	public function test_theme_mods_changed_by_code_are_reported(): void {
		set_theme_mod( 'background_color', 'abcdef' );
		set_theme_mod( 'nav_menu_locations', [ 'top' => 3 ] );
		$events = $this->sent_events();

		$this->assertCount( 1, $events );
		$this->assertSame( 'other', $events[0]['data']['source'] );
		$this->assertSame( [ 'background_color' ], array_keys( self::settings( $events[0]['data'] ) ) );
	}

	public function test_the_logo_set_over_rest_is_reported(): void {
		$logo    = $this->image( 'block-logo.png' );
		$request = new \WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params( [ 'site_logo' => $logo ] );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $this->sent_events()[0]['data'];

		$this->assertSame( 'other', $data['source'] );
		$this->assertSame( 'block-logo.png', $data['logo']['new']['file'] );
	}

	public function test_additional_css_saved_by_a_plugin_is_reported(): void {
		$this->posts[] = wp_update_custom_css_post( 'body { color: red; }' )->ID;
		$data          = $this->sent_events()[0]['data'];

		$this->assertSame( 1, $data['css']['added'] );
		$this->assertSame( 0, $data['css']['removed'] );
	}

	public function test_theme_mods_written_on_a_page_view_are_not_reported(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		set_theme_mod( 'background_color', '123456' );

		$this->assertSame( [], $this->sent_events() );
	}
}
