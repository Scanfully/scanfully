<?php
/**
 * Widget event integration tests: widgets saved through the block widget
 * editor's REST API and the way the classic Widgets screen and WP-CLI save
 * them, run through the real hooks and the background job.
 *
 * Runs in wp-env and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Events\Controller as EventsController;
use Scanfully\Events\WidgetChanges\Collector;

/**
 * Verifies which widget changes are sent, and how.
 */
final class WidgetEventsTest extends TestCase {

	/**
	 * Widget options before the test.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * Event bodies sent to the API.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'register_sidebar' ) ) {
			$this->markTestSkipped( 'WordPress not available. Run this suite under wp-env.' );
		}

		// A theme switched by an earlier test of this run counts for the
		// whole process; in a site it is a request of its own.
		unset( $GLOBALS['wp_actions']['switch_theme'] );
		foreach ( [ 'sidebars_widgets', 'widget_block', 'widget_text', 'widget_search' ] as $option ) {
			$this->options[ $option ] = get_option( $option );
		}
		register_sidebar(
			[
				'id'   => 'scanfully-sidebar',
				'name' => 'Test sidebar',
			]
		);
		register_sidebar(
			[
				'id'   => 'scanfully-footer',
				'name' => 'Test footer',
			]
		);

		update_option( 'scanfully_connect_is_connected', 'yes' );
		as_unschedule_all_actions( '', [], 'scanfully' );
		Collector::reset();
		$this->sent = [];
		wp_set_current_user( 1 );
		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );
		add_filter( 'scanfully_widget_events_per_minute', '__return_zero' );
		unset( $_SERVER['REQUEST_METHOD'] );
	}

	protected function tearDown(): void {
		if ( function_exists( 'unregister_sidebar' ) ) {
			foreach ( $this->options as $option => $value ) {
				update_option( $option, $value );
			}
			unregister_sidebar( 'scanfully-sidebar' );
			unregister_sidebar( 'scanfully-footer' );
			remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
			remove_all_filters( 'scanfully_widget_events_per_minute' );
			unset( $_SERVER['REQUEST_METHOD'] );
			Collector::reset();
			as_unschedule_all_actions( '', [], 'scanfully' );
			delete_option( 'scanfully_connect_is_connected' );
			wp_set_current_user( 0 );
		}
		parent::tearDown();
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
	 * End the "request", run the jobs as Action Scheduler would, and return
	 * the WidgetsChanged events sent: their data.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function sent_widget_events(): array {
		Collector::flush();

		$actions = as_get_scheduled_actions(
			[
				'hook__in' => [ EventsController::ACTION_BUILD_CONTENT_EVENT, EventsController::ACTION_SEND_EVENT ],
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
			if ( 'WidgetsChanged' === $body['type'] ) {
				$events[] = (array) $body['data'];
			}
		}
		$this->sent = [];

		return $events;
	}

	/**
	 * Widgets in the test areas, set in an earlier "request".
	 *
	 * @param array<string, array<int, string>> $areas     Area ID to widget IDs.
	 * @param array<string, array<int, mixed>>  $instances Option to instances.
	 *
	 * @return void
	 */
	private function given( array $areas, array $instances ): void {
		foreach ( $instances as $option => $value ) {
			update_option( $option, $value + [ '_multiwidget' => 1 ] );
		}
		$sidebars = wp_get_sidebars_widgets();
		foreach ( $areas as $area => $ids ) {
			$sidebars[ $area ] = $ids;
		}
		wp_set_sidebars_widgets( $sidebars );
		Collector::reset();
	}

	public function test_a_block_widget_added_in_the_block_widget_editor(): void {
		$this->given( [ 'scanfully-sidebar' => [] ], [] );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/widgets' );
		$request->set_body_params(
			[
				'id_base'  => 'block',
				'sidebar'  => 'scanfully-sidebar',
				'instance' => [ 'raw' => [ 'content' => '<!-- wp:paragraph --><p>Opening hours: 9 to 5</p><!-- /wp:paragraph -->' ] ],
			]
		);
		$this->assertSame( 201, rest_do_request( $request )->get_status() );

		$events = $this->sent_widget_events();
		$this->assertCount( 1, $events );
		$widget = $events[0]['widgets'][0];
		$this->assertSame( 'added', $widget['action'] );
		$this->assertSame( 'block', $widget['type'] );
		$this->assertSame( 'Paragraph', $widget['type_label'] );
		$this->assertSame( '', $widget['title'], 'A block widget has no title; its text is not one.' );
		$this->assertSame( 'Opening hours: 9 to 5', $widget['excerpt'] );
		$this->assertSame(
			[
				'id'   => 'scanfully-sidebar',
				'name' => 'Test sidebar',
			],
			$widget['area']
		);
		$this->assertSame( 'Paragraph', $events[0]['name'] );
	}

	public function test_a_text_widget_edit_reports_the_title_and_the_lines(): void {
		$this->given(
			[ 'scanfully-sidebar' => [ 'text-91' ] ],
			[
				'widget_text' => [
					91 => [
						'title' => 'Hours',
						'text'  => "Monday 9 to 5\nTuesday 9 to 5",
					],
				],
			]
		);

		$instances     = get_option( 'widget_text' );
		$instances[91] = [
			'title' => 'Opening hours',
			'text'  => "Monday 9 to 5\nTuesday 10 to 4",
		];
		update_option( 'widget_text', $instances );

		$events = $this->sent_widget_events();
		$this->assertCount( 1, $events );
		$widget = $events[0]['widgets'][0];
		$this->assertSame( 'changed', $widget['action'] );
		$this->assertSame( 'Text', $widget['type_label'] );
		$this->assertSame( 'Opening hours', $widget['title'] );
		$this->assertSame( 'Hours', $widget['title_old'] );
		$this->assertSame( 1, $widget['content']['added'] );
		$this->assertSame( 1, $widget['content']['removed'] );
	}

	public function test_moving_and_removing_widgets_in_one_save_is_one_row(): void {
		$this->given(
			[
				'scanfully-sidebar' => [ 'search-91', 'text-92' ],
				'scanfully-footer'  => [],
			],
			[
				'widget_search' => [ 91 => [ 'title' => 'Find' ] ],
				'widget_text'   => [
					92 => [
						'title' => 'Promo',
						'text'  => 'Sale',
					],
				],
			]
		);

		$sidebars                        = wp_get_sidebars_widgets();
		$sidebars['scanfully-sidebar']   = [];
		$sidebars['scanfully-footer']    = [ 'search-91' ];
		$sidebars['wp_inactive_widgets'] = array_merge( (array) ( $sidebars['wp_inactive_widgets'] ?? [] ), [ 'text-92' ] );
		wp_set_sidebars_widgets( $sidebars );

		$events = $this->sent_widget_events();
		$this->assertCount( 1, $events );
		$actions = array_column( $events[0]['widgets'], 'action', 'id' );
		ksort( $actions );
		$this->assertSame(
			[
				'search-91' => 'moved',
				'text-92'   => 'removed',
			],
			$actions
		);
		$moved = array_column( $events[0]['widgets'], null, 'id' )['search-91'];
		$this->assertSame( 'Test sidebar', $moved['old_area']['name'] );
		$this->assertSame( 'Test footer', $moved['area']['name'] );
	}

	public function test_loading_the_widgets_screen_reports_nothing(): void {
		$this->given(
			[ 'scanfully-sidebar' => [ 'text-93' ] ],
			[ 'widget_text' => [ 93 => [ 'title' => 'Hours' ] ] ]
		);
		$_SERVER['REQUEST_METHOD'] = 'GET';

		// What retrieve_widgets() does with a lost widget on a page load.
		$sidebars                        = wp_get_sidebars_widgets();
		$sidebars['scanfully-sidebar']   = [];
		$sidebars['wp_inactive_widgets'] = array_merge( (array) ( $sidebars['wp_inactive_widgets'] ?? [] ), [ 'text-93' ] );
		wp_set_sidebars_widgets( $sidebars );

		$this->assertSame( [], $this->sent_widget_events() );
	}

	public function test_saving_without_changes_reports_nothing(): void {
		$this->given(
			[ 'scanfully-sidebar' => [ 'text-94' ] ],
			[
				'widget_text' => [
					94 => [
						'title' => 'Hours',
						'text'  => 'Mon',
					],
				],
			]
		);

		update_option( 'widget_text', get_option( 'widget_text' ) );
		wp_set_sidebars_widgets( wp_get_sidebars_widgets() );

		$this->assertSame( [], $this->sent_widget_events() );
	}
}
