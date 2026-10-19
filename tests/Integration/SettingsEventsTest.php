<?php
/**
 * Settings event integration tests: what a save of each settings screen,
 * the Customizer and the REST API reports, run through the real WordPress
 * hooks and the background job.
 *
 * Runs in wp-env and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Events\Controller as EventsController;
use Scanfully\Events\SettingsChanges\Collector;

/**
 * Verifies which settings changes are sent, and how.
 */
final class SettingsEventsTest extends TestCase {

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

		update_option( 'scanfully_connect_is_connected', 'yes' );
		as_unschedule_all_actions( '', [], 'scanfully' );
		Collector::reset();
		$this->sent = [];
		wp_set_current_user( 1 );
		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );
		// Never send mail (the admin email request mails a confirmation link).
		add_filter( 'pre_wp_mail', '__return_true' );
		add_filter( 'scanfully_settings_events_per_minute', '__return_zero' );

		// The admin email request is handled by a hook wp-admin adds
		// (admin-filters.php), which a CLI run doesn't load.
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		if ( ! has_action( 'update_option_new_admin_email', 'update_option_new_admin_email' ) ) {
			add_action( 'update_option_new_admin_email', 'update_option_new_admin_email', 10, 2 );
			add_action( 'add_option_new_admin_email', 'update_option_new_admin_email', 10, 2 );
		}
	}

	protected function tearDown(): void {
		if ( function_exists( 'update_option' ) ) {
			foreach ( $this->options as $option => $value ) {
				if ( null === $value ) {
					delete_option( $option );
				} else {
					update_option( $option, $value );
				}
			}
			foreach ( $this->posts as $id ) {
				wp_delete_post( $id, true );
			}
			remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
			remove_filter( 'pre_wp_mail', '__return_true' );
			remove_all_filters( 'scanfully_settings_events_per_minute' );
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
	 * Remember options' values so tearDown restores them.
	 *
	 * @param string ...$options The options.
	 */
	private function restore_later( string ...$options ): void {
		foreach ( $options as $option ) {
			if ( ! array_key_exists( $option, $this->options ) ) {
				$value                    = get_option( $option, null );
				$this->options[ $option ] = $value;
			}
		}
	}

	/**
	 * Set options before the test's "request", without reporting them.
	 *
	 * @param array<string, mixed> $values The options and their values.
	 */
	private function given( array $values ): void {
		$this->restore_later( ...array_keys( $values ) );
		foreach ( $values as $option => $value ) {
			update_option( $option, $value );
		}
		Collector::reset();
	}

	/**
	 * Save fields the way wp-admin/options.php does: update_option() for
	 * every field of the screen, a missing one as null.
	 *
	 * @param array<string, mixed> $fields The fields and their posted values.
	 */
	private function save_screen( array $fields ): void {
		$this->restore_later( ...array_keys( $fields ) );
		foreach ( $fields as $option => $value ) {
			update_option( $option, is_string( $value ) ? trim( $value ) : $value );
		}
	}

	/**
	 * End the "request" and run the jobs, as Action Scheduler would; return
	 * the events sent: type and data.
	 *
	 * @return array<int, array{type: string, data: array<string, mixed>}>
	 */
	private function sent_events(): array {
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

		return array_map(
			static fn( array $body ): array => [
				'type' => (string) $body['type'],
				'data' => (array) $body['data'],
			],
			$this->sent
		);
	}

	/**
	 * The data of the SiteOptionChanged events sent.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function sent_settings(): array {
		$events = $this->sent_events();
		$this->assertSame( array_fill( 0, count( $events ), 'SiteOptionChanged' ), array_column( $events, 'type' ) );

		return array_column( $events, 'data' );
	}

	public function test_a_general_settings_save_reports_each_changed_field(): void {
		$this->given(
			[
				'blogdescription' => 'Just another site',
				'date_format'     => 'F j, Y',
				'start_of_week'   => '1',
			]
		);

		$this->save_screen(
			[
				'blogdescription' => 'Shop & more',
				'date_format'     => 'd/m/Y',
				'time_format'     => get_option( 'time_format' ),
				'start_of_week'   => '1',
			]
		);

		$this->assertSame(
			[
				[
					'option' => 'blogdescription',
					'old'    => 'Just another site',
					'new'    => 'Shop & more',
				],
				[
					'option'      => 'date_format',
					'old'         => 'F j, Y',
					'new'         => 'd/m/Y',
					'old_example' => 'January 31, 2026',
					'new_example' => '31/01/2026',
				],
			],
			$this->sent_settings()
		);
	}

	public function test_a_save_without_changes_sends_nothing(): void {
		$this->given(
			[
				'comment_moderation'      => '1',
				'show_avatars'            => '1',
				'users_can_register'      => '0',
				'close_comments_days_old' => '14',
			]
		);

		// Unchecked boxes are posted as null; numbers come back as strings.
		$this->save_screen(
			[
				'comment_moderation'      => '1',
				'show_avatars'            => '1',
				'users_can_register'      => null,
				'close_comments_days_old' => 14,
			]
		);

		$this->assertSame( [], $this->sent_events() );
	}

	public function test_discussion_changes_are_a_row_each_and_word_lists_only_counts(): void {
		$this->given(
			[
				'default_comment_status' => 'open',
				'comment_moderation'     => '',
				'disallowed_keys'        => "spam\n203.0.113.9",
			]
		);

		$this->save_screen(
			[
				'default_comment_status' => null,
				'comment_moderation'     => '1',
				'disallowed_keys'        => "spam\nbad@scanfully.com\ncasino",
			]
		);

		$sent = $this->sent_settings();
		$this->assertSame( [ 'default_comment_status', 'comment_moderation', 'disallowed_keys' ], array_column( $sent, 'option' ) );
		$this->assertSame(
			[
				'option'  => 'disallowed_keys',
				'added'   => 2,
				'removed' => 1,
			],
			$sent[2]
		);
		$this->assertStringNotContainsString( 'scanfully.com', (string) wp_json_encode( $sent ) );
	}

	public function test_update_services_saved_while_search_engines_are_discouraged_list_only_the_real_change(): void {
		$this->given(
			[
				'ping_sites'  => 'http://rpc.pingomatic.com/',
				// WordPress now reads the update services as empty.
				'blog_public' => '0',
			]
		);
		// A later request reads `blog_public` from the database as '0' (the
		// request that saves it holds the int 0, which the filter ignores).
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertSame( '', get_option( 'ping_sites' ) );

		// Only code, REST or WP-CLI can: the Writing screen hides the field.
		$this->save_screen( [ 'ping_sites' => "http://rpc.pingomatic.com/\nhttps://ping.scanfully.com/" ] );

		$this->assertSame(
			[
				[
					'option'  => 'ping_sites',
					'added'   => [ 'https://ping.scanfully.com/' ],
					'removed' => [],
				],
			],
			$this->sent_settings()
		);
	}

	public function test_the_post_via_email_password_is_never_sent(): void {
		$this->given( [ 'mailserver_pass' => 'old-password' ] );

		$this->save_screen( [ 'mailserver_pass' => 'new-password' ] );

		$sent = $this->sent_settings();
		$this->assertSame(
			[
				[
					'option' => 'mailserver_pass',
					'secret' => true,
				],
			],
			$sent
		);
	}

	public function test_a_static_front_page_comes_with_the_page_titles(): void {
		$home          = wp_insert_post(
			[
				'post_type'   => 'page',
				'post_title'  => 'Home & away',
				'post_status' => 'publish',
			]
		);
		$this->posts[] = $home;
		$this->given(
			[
				'show_on_front' => 'posts',
				'page_on_front' => '0',
			]
		);

		$this->save_screen(
			[
				'show_on_front' => 'page',
				'page_on_front' => (string) $home,
			]
		);

		$this->assertSame(
			[
				[
					'option' => 'show_on_front',
					'old'    => 'posts',
					'new'    => 'page',
				],
				[
					'option'   => 'page_on_front',
					'old'      => '0',
					'new'      => (string) $home,
					'new_name' => 'Home & away',
				],
			],
			$this->sent_settings()
		);
	}

	public function test_choosing_a_utc_offset_instead_of_a_city_is_one_timezone_row(): void {
		$this->given(
			[
				'timezone_string' => 'Europe/Amsterdam',
				'gmt_offset'      => '',
			]
		);

		// What options.php saves for "UTC+2".
		$this->save_screen(
			[
				'gmt_offset'      => '2',
				'timezone_string' => '',
			]
		);

		$this->assertSame(
			[
				[
					'option' => 'timezone',
					'old'    => 'Europe/Amsterdam',
					'new'    => 'UTC+2',
				],
			],
			$this->sent_settings()
		);
	}

	public function test_an_admin_email_change_is_reported_when_asked_for_and_when_confirmed(): void {
		$this->restore_later( 'admin_email', 'new_admin_email', 'adminhash' );
		$admin = (string) get_option( 'admin_email' );

		// Settings, General: WordPress stores the request and mails a link.
		$this->save_screen( [ 'new_admin_email' => 'owner@scanfully.com' ] );
		$this->assertSame(
			[
				[
					'option' => 'new_admin_email',
					'old'    => $admin,
					'new'    => 'owner@scanfully.com',
				],
			],
			$this->sent_settings()
		);
		$hash = get_option( 'adminhash' );
		$this->assertIsArray( $hash );
		$this->assertStringNotContainsString( (string) $hash['hash'], (string) wp_json_encode( $this->sent ) );

		// The link: what options.php does with ?adminhash=.
		$this->sent = [];
		update_option( 'admin_email', 'owner@scanfully.com' );
		delete_option( 'adminhash' );
		delete_option( 'new_admin_email' );
		$this->assertSame(
			[
				[
					'option' => 'admin_email',
					'old'    => $admin,
					'new'    => 'owner@scanfully.com',
				],
			],
			$this->sent_settings()
		);
	}

	public function test_a_withdrawn_admin_email_request_is_reported_as_cancelled(): void {
		$this->restore_later( 'admin_email', 'new_admin_email', 'adminhash' );
		update_option( 'new_admin_email', 'owner@scanfully.com' );
		Collector::reset();

		// The Cancel link: what options.php does with ?dismiss=new_admin_email.
		delete_option( 'adminhash' );
		delete_option( 'new_admin_email' );

		$sent = $this->sent_settings();
		$this->assertCount( 1, $sent );
		$this->assertSame( 'new_admin_email', $sent[0]['option'] );
		$this->assertSame( 'owner@scanfully.com', $sent[0]['new'] );
		$this->assertTrue( $sent[0]['cancelled'] );
	}

	public function test_saving_the_current_admin_email_asks_for_nothing(): void {
		$this->restore_later( 'new_admin_email', 'adminhash' );

		$this->save_screen( [ 'new_admin_email' => (string) get_option( 'admin_email' ) ] );

		$this->assertSame( [], $this->sent_events() );
	}

	public function test_the_category_base_is_reported_from_the_permalinks_screen(): void {
		global $wp_rewrite;
		$this->restore_later( 'category_base' );
		$this->given( [ 'category_base' => '' ] );

		$wp_rewrite->set_category_base( 'topics' );

		$this->assertSame(
			[
				[
					'option' => 'category_base',
					'old'    => '',
					'new'    => 'topics',
				],
			],
			$this->sent_settings()
		);
	}

	public function test_the_rest_settings_endpoint_is_reported_too(): void {
		$this->given(
			[
				'blogdescription' => 'Before',
				'posts_per_page'  => '10',
			]
		);

		$request = new \WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params(
			[
				'description'    => 'After',
				'posts_per_page' => 12,
			]
		);
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$this->assertSame( [ 'blogdescription', 'posts_per_page' ], array_column( $this->sent_settings(), 'option' ) );
	}

	public function test_the_customizer_site_identity_is_reported(): void {
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		$this->given( [ 'blogdescription' => 'Before' ] );

		$manager = new \WP_Customize_Manager();
		$manager->register_controls();
		$result = $manager->save_changeset_post(
			[
				'data'   => [ 'blogdescription' => [ 'value' => 'From the Customizer' ] ],
				'status' => 'publish',
			]
		);
		$this->assertIsArray( $result );

		$this->assertSame(
			[
				[
					'option' => 'blogdescription',
					'old'    => 'Before',
					'new'    => 'From the Customizer',
				],
			],
			$this->sent_settings()
		);
	}

	public function test_database_upgrades_are_not_reported(): void {
		$this->given( [ 'posts_per_rss' => '10' ] );

		wp_installing( true );
		update_option( 'posts_per_rss', '20' );
		wp_installing( false );

		$this->assertSame( [], $this->sent_events() );
	}
}
