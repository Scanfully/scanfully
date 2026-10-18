<?php
/**
 * Menu event integration tests: classic menus saved the way the Menus
 * screen, REST and the theme location settings save them, and block theme
 * navigation menus, run through the real hooks and the background job.
 *
 * Runs in wp-env and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Events\Controller as EventsController;
use Scanfully\Events\MenuChanges\Collector;
use Scanfully\Events\PostChanges\Collector as PostCollector;
use Scanfully\Events\SettingsChanges\Collector as SettingsCollector;
use Scanfully\Events\TermChanges\Collector as TermCollector;

/**
 * Verifies which menu changes are sent, and how.
 */
final class MenuEventsTest extends TestCase {

	/**
	 * Menus created by a test.
	 *
	 * @var array<int, int>
	 */
	private array $menus = [];

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

	/**
	 * The menu locations before the test.
	 *
	 * @var mixed
	 */
	private $locations;

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'wp_create_nav_menu' ) ) {
			$this->markTestSkipped( 'WordPress not available. Run this suite under wp-env.' );
		}
		// A theme switched by an earlier test of this run counts for the
		// whole process; in a site it is a request of its own.
		unset( $GLOBALS['wp_actions']['switch_theme'] );
		require_once ABSPATH . 'wp-admin/includes/nav-menu.php';

		update_option( 'scanfully_connect_is_connected', 'yes' );
		register_nav_menus(
			[
				'scanfully-primary' => 'Primary',
				'scanfully-footer'  => 'Footer',
			]
		);
		$this->locations = get_theme_mod( 'nav_menu_locations' );
		as_unschedule_all_actions( '', [], 'scanfully' );
		$this->reset_collectors();
		$this->sent = [];
		wp_set_current_user( 1 );
		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );
		add_filter( 'scanfully_menu_events_per_minute', '__return_zero' );
		add_filter( 'scanfully_post_saved_events_per_minute', '__return_zero' );
		unset( $_SERVER['REQUEST_METHOD'] );
	}

	protected function tearDown(): void {
		if ( function_exists( 'wp_delete_nav_menu' ) ) {
			foreach ( $this->menus as $id ) {
				wp_delete_nav_menu( $id );
			}
			foreach ( $this->posts as $id ) {
				wp_delete_post( $id, true );
			}
			set_theme_mod( 'nav_menu_locations', $this->locations );
			unregister_nav_menu( 'scanfully-primary' );
			unregister_nav_menu( 'scanfully-footer' );
			remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
			remove_all_filters( 'scanfully_menu_events_per_minute' );
			remove_all_filters( 'scanfully_post_saved_events_per_minute' );
			$_POST = [];
			unset( $_SERVER['REQUEST_METHOD'] );
			$this->reset_collectors();
			as_unschedule_all_actions( '', [], 'scanfully' );
			delete_option( 'scanfully_connect_is_connected' );
			wp_set_current_user( 0 );
		}
		parent::tearDown();
	}

	/**
	 * Forget what every collector noted.
	 *
	 * @return void
	 */
	private function reset_collectors(): void {
		Collector::reset();
		PostCollector::reset();
		TermCollector::reset();
		SettingsCollector::reset();
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
	 * the menu events sent: type and data.
	 *
	 * @return array<int, array{type: string, data: array<string, mixed>}>
	 */
	private function sent_menu_events(): array {
		Collector::flush();
		PostCollector::flush();
		TermCollector::flush();
		SettingsCollector::flush();

		$actions = as_get_scheduled_actions(
			[
				'hook__in' => [ EventsController::ACTION_BUILD_CONTENT_EVENT, EventsController::ACTION_BUILD_POST_EVENT, EventsController::ACTION_SEND_EVENT ],
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
			if ( 0 === strpos( (string) $body['type'], 'Menu' ) || 'PostSaved' === $body['type'] ) {
				$events[] = [
					'type' => (string) $body['type'],
					'data' => (array) $body['data'],
				];
			}
		}
		$this->sent = [];

		return $events;
	}

	/**
	 * A classic menu with items made in an earlier "request".
	 *
	 * @param string             $name   The menu name.
	 * @param array<int, string> $labels The labels of its custom link items.
	 *
	 * @return array{0: int, 1: array<int, int>} The menu ID and the item IDs.
	 */
	private function make_menu( string $name, array $labels ): array {
		$menu_id       = wp_create_nav_menu( $name );
		$this->menus[] = $menu_id;
		$items         = [];
		foreach ( $labels as $position => $label ) {
			$items[] = wp_update_nav_menu_item(
				$menu_id,
				0,
				[
					'menu-item-title'    => $label,
					'menu-item-url'      => 'https://scanfully.com/' . strtolower( $label ) . '/',
					'menu-item-type'     => 'custom',
					'menu-item-status'   => 'publish',
					'menu-item-position' => $position + 1,
				]
			);
		}
		$this->sent_menu_events();

		return [ $menu_id, $items ];
	}

	/**
	 * Save a menu the way the Menus screen does: every item posted, the
	 * ones left out deleted.
	 *
	 * @param int                              $menu_id The menu ID.
	 * @param array<int, array<string, mixed>> $items   Per item ID, in the new order: the fields to change.
	 *
	 * @return void
	 */
	private function save_menu_screen( int $menu_id, array $items ): void {
		$current = [];
		foreach ( wp_get_nav_menu_items( $menu_id, [ 'post_status' => 'draft,publish' ] ) as $item ) {
			$current[ (int) $item->db_id ] = $item;
		}

		$_POST    = [];
		$position = 0;
		foreach ( $items as $item_id => $fields ) {
			$item = $current[ $item_id ];
			++$position;
			$posted = [
				'menu-item-db-id'       => $item_id,
				'menu-item-object-id'   => $item->object_id,
				'menu-item-object'      => $item->object,
				'menu-item-parent-id'   => $item->menu_item_parent,
				'menu-item-position'    => $position,
				'menu-item-type'        => $item->type,
				'menu-item-title'       => $item->post_title,
				'menu-item-url'         => $item->url,
				'menu-item-description' => $item->description,
				'menu-item-attr-title'  => $item->attr_title,
				'menu-item-target'      => $item->target,
				'menu-item-classes'     => implode( ' ', (array) $item->classes ),
				'menu-item-xfn'         => $item->xfn,
			];
			foreach ( array_merge( $posted, $fields ) as $field => $value ) {
				$_POST[ $field ][ $item_id ] = $value;
			}
		}

		wp_nav_menu_update_menu_items( $menu_id, 'Main menu' );
		$_POST = [];
	}

	public function test_creating_a_menu_is_reported(): void {
		$menu_id       = wp_create_nav_menu( 'Main menu' );
		$this->menus[] = $menu_id;

		$this->assertSame(
			[
				[
					'type' => 'MenuCreated',
					'data' => [
						'id'    => $menu_id,
						'kind'  => 'classic',
						'name'  => 'Main menu',
						'items' => 0,
					],
				],
			],
			$this->sent_menu_events()
		);
	}

	public function test_renaming_a_menu_is_reported(): void {
		[ $menu_id ] = $this->make_menu( 'Main menu', [] );

		wp_update_nav_menu_object( $menu_id, [ 'menu-name' => 'Header menu' ] );

		$events = $this->sent_menu_events();
		$this->assertSame( [ 'MenuChanged' ], array_column( $events, 'type' ) );
		$this->assertSame(
			[
				'old' => 'Main menu',
				'new' => 'Header menu',
			],
			$events[0]['data']['changes']['name']
		);
	}

	public function test_the_first_auto_add_pages_setting_on_a_site_is_reported(): void {
		[ $menu_id ] = $this->make_menu( 'Main menu', [] );
		$before      = get_option( 'nav_menu_options' );
		// A site whose Menus screen was never saved has no such option yet:
		// update_option() adds it.
		delete_option( 'nav_menu_options' );

		update_option( 'nav_menu_options', [ 'auto_add' => [ $menu_id ] ], false );
		$events = $this->sent_menu_events();

		if ( false === $before ) {
			delete_option( 'nav_menu_options' );
		} else {
			update_option( 'nav_menu_options', $before, false );
		}
		Collector::reset();

		$this->assertSame( [ 'MenuChanged' ], array_column( $events, 'type' ) );
		$this->assertSame( $menu_id, $events[0]['data']['id'] );
		$this->assertSame(
			[
				'old' => false,
				'new' => true,
			],
			$events[0]['data']['changes']['auto_add']
		);
	}

	public function test_a_menus_screen_save_reports_each_item_change_and_only_the_real_move(): void {
		[ $menu_id, $items ]                  = $this->make_menu( 'Main menu', [ 'Home', 'Blog', 'Shop', 'About', 'Old' ] );
		[ $home, $blog, $shop, $about, $old ] = $items;

		// "Add to Menu" makes a draft item over admin-ajax first.
		$contact = wp_update_nav_menu_item(
			$menu_id,
			0,
			[
				'menu-item-title'  => 'Contact',
				'menu-item-url'    => 'https://scanfully.com/contact/',
				'menu-item-type'   => 'custom',
				'menu-item-status' => 'draft',
			]
		);
		$this->assertSame( [], $this->sent_menu_events(), 'A draft item is not on the menu yet.' );

		// Save Menu: About moved to the top, Blog renamed, Shop under Home,
		// Old removed, Contact saved.
		$this->save_menu_screen(
			$menu_id,
			[
				$about   => [],
				$home    => [],
				$blog    => [ 'menu-item-title' => 'News' ],
				$shop    => [ 'menu-item-parent-id' => $home ],
				$contact => [],
			]
		);

		$events = $this->sent_menu_events();
		$this->assertSame( [ 'MenuChanged' ], array_column( $events, 'type' ) );
		$data    = $events[0]['data'];
		$changes = $data['changes'];
		$this->assertSame( 'Main menu', $data['name'] );
		$this->assertSame( 5, $data['items'] );
		$this->assertSame( [ 'Contact' ], array_column( $changes['added'], 'label' ) );
		$this->assertSame( [ 'Old' ], array_column( $changes['removed'], 'label' ) );
		$this->assertSame(
			[
				'old' => 'Blog',
				'new' => 'News',
			],
			array_intersect_key( $changes['renamed'][0], array_flip( [ 'old', 'new' ] ) )
		);
		$moved = array_column( $changes['moved'], 'parent', 'label' );
		ksort( $moved );
		$this->assertSame(
			[
				'About' => '',
				'Shop'  => 'Home',
			],
			$moved
		);
		$this->assertSame( 'Custom Link', $changes['added'][0]['type'] );
	}

	public function test_saving_a_menu_without_changes_sends_nothing(): void {
		[ $menu_id, $items ] = $this->make_menu( 'Main menu', [ 'Home', 'Blog' ] );

		$this->save_menu_screen( $menu_id, array_fill_keys( $items, [] ) );

		$this->assertSame( [], $this->sent_menu_events() );
	}

	public function test_deleting_a_menu_is_one_row_with_its_items_and_locations(): void {
		[ $menu_id ]                    = $this->make_menu( 'Old menu', [ 'Home', 'Blog', 'Shop' ] );
		$this->menus                    = [];
		$locations                      = (array) get_theme_mod( 'nav_menu_locations' );
		$locations['scanfully-primary'] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		$this->sent_menu_events();

		wp_delete_nav_menu( $menu_id );

		$this->assertSame(
			[
				[
					'type' => 'MenuDeleted',
					'data' => [
						'id'        => $menu_id,
						'kind'      => 'classic',
						'name'      => 'Old menu',
						'items'     => 3,
						'locations' => [
							[
								'slug' => 'scanfully-primary',
								'name' => 'Primary',
							],
						],
					],
				],
			],
			$this->sent_menu_events()
		);
	}

	public function test_showing_another_menu_in_a_location_is_on_both_menus(): void {
		[ $main ]                       = $this->make_menu( 'Main menu', [ 'Home' ] );
		[ $other ]                      = $this->make_menu( 'Other menu', [ 'Shop' ] );
		$locations                      = (array) get_theme_mod( 'nav_menu_locations' );
		$locations['scanfully-primary'] = $other;
		set_theme_mod( 'nav_menu_locations', $locations );
		$this->sent_menu_events();

		$locations['scanfully-primary'] = $main;
		set_theme_mod( 'nav_menu_locations', $locations );

		$events = $this->sent_menu_events();
		$by_id  = array_column( array_column( $events, 'data' ), 'changes', 'id' );
		$this->assertSame( [ 'Primary' ], array_column( $by_id[ $main ]['locations']['added'], 'name' ) );
		$this->assertSame( [ 'Primary' ], array_column( $by_id[ $other ]['locations']['removed'], 'name' ) );
	}

	public function test_an_item_whose_page_was_deleted_is_removed_with_that_reason(): void {
		[ $menu_id ] = $this->make_menu( 'Main menu', [ 'Home' ] );
		$page        = wp_insert_post(
			[
				'post_type'   => 'page',
				'post_title'  => 'Pricing',
				'post_status' => 'publish',
			]
		);
		wp_update_nav_menu_item(
			$menu_id,
			0,
			[
				'menu-item-object-id' => $page,
				'menu-item-object'    => 'page',
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
			]
		);
		$this->sent_menu_events();

		wp_delete_post( $page, true );

		$events = array_values( array_filter( $this->sent_menu_events(), static fn( array $event ): bool => 'MenuChanged' === $event['type'] ) );
		$this->assertCount( 1, $events );
		$this->assertSame( 'object_deleted', $events[0]['data']['changes']['removed'][0]['reason'] );
		$this->assertSame( 'Page', $events[0]['data']['changes']['removed'][0]['type'] );
		$this->assertSame( 'Pricing', $events[0]['data']['changes']['removed'][0]['label'], 'The item showed the page title.' );
		$this->assertSame( $menu_id, $events[0]['data']['id'] );
		$this->assertSame( 'Main menu', $events[0]['data']['name'] );
	}

	public function test_an_item_added_over_rest_is_reported(): void {
		[ $menu_id ] = $this->make_menu( 'Main menu', [ 'Home' ] );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/menu-items' );
		$request->set_body_params(
			[
				'title'  => 'Docs',
				'url'    => 'https://scanfully.com/docs/',
				'menus'  => $menu_id,
				'status' => 'publish',
			]
		);
		$this->assertSame( 201, rest_do_request( $request )->get_status() );

		$events = $this->sent_menu_events();
		$this->assertSame( [ 'MenuChanged' ], array_column( $events, 'type' ) );
		$this->assertSame( [ 'Docs' ], array_column( $events[0]['data']['changes']['added'], 'label' ) );
	}

	public function test_a_theme_switch_remapping_locations_is_not_reported(): void {
		[ $menu_id ] = $this->make_menu( 'Main menu', [ 'Home' ] );
		$remap       = static function () use ( $menu_id ): void {
			set_theme_mod( 'nav_menu_locations', [ 'scanfully-footer' => $menu_id ] );
		};
		// Only this remap runs (WooCommerce's handlers expect a real switch).
		global $wp_filter;
		$handlers = $wp_filter['after_switch_theme'] ?? null;
		remove_all_actions( 'after_switch_theme' );
		add_action( 'after_switch_theme', $remap );

		do_action( 'after_switch_theme', 'twentytwentyfour', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hook.
		remove_all_actions( 'after_switch_theme' );
		if ( null !== $handlers ) {
			$wp_filter['after_switch_theme'] = $handlers; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring what the test took away.
		}

		$this->assertSame( [], $this->sent_menu_events() );
	}

	/**
	 * Navigation link blocks.
	 *
	 * @param array<int, string> $labels The labels.
	 *
	 * @return string
	 */
	private static function links( array $labels ): string {
		$blocks = '';
		foreach ( $labels as $label ) {
			$blocks .= '<!-- wp:navigation-link {"label":"' . $label . '","url":"https://scanfully.com/' . strtolower( $label ) . '/","kind":"custom"} /-->';
		}

		return $blocks;
	}

	public function test_a_block_menu_reads_like_a_classic_one_and_is_no_content_row(): void {
		$id            = wp_insert_post(
			[
				'post_type'    => 'wp_navigation',
				'post_title'   => 'Header navigation',
				'post_status'  => 'publish',
				'post_content' => self::links( [ 'Home', 'Blog', 'Shop' ] ),
			]
		);
		$this->posts[] = $id;
		$this->assertSame(
			[
				[
					'type' => 'MenuCreated',
					'data' => [
						'id'    => $id,
						'kind'  => 'block',
						'name'  => 'Header navigation',
						'items' => 3,
					],
				],
			],
			$this->sent_menu_events()
		);

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => self::links( [ 'Shop', 'Home', 'News', 'Contact' ] ),
			]
		);

		$events = $this->sent_menu_events();
		$this->assertSame( [ 'MenuChanged' ], array_column( $events, 'type' ), 'No PostSaved row.' );
		$changes = $events[0]['data']['changes'];
		$this->assertSame( [ 'News', 'Contact' ], array_column( $changes['added'], 'label' ) );
		$this->assertSame( [ 'Blog' ], array_column( $changes['removed'], 'label' ) );
		$this->assertSame( [ 'Shop' ], array_column( $changes['moved'], 'label' ) );
		$this->assertSame( 4, $events[0]['data']['items'] );
	}

	public function test_the_fallback_menu_the_editor_makes_is_not_reported(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$id            = wp_insert_post(
			[
				'post_type'    => 'wp_navigation',
				'post_title'   => 'Navigation',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:page-list /-->',
			]
		);
		$this->posts[] = $id;

		$this->assertSame( [], $this->sent_menu_events() );
	}

	public function test_deleting_a_block_menu_is_reported(): void {
		$id = wp_insert_post(
			[
				'post_type'    => 'wp_navigation',
				'post_title'   => 'Footer navigation',
				'post_status'  => 'publish',
				'post_content' => self::links( [ 'Privacy', 'Terms' ] ),
			]
		);
		$this->sent_menu_events();

		wp_delete_post( $id, true );

		$this->assertSame(
			[
				[
					'type' => 'MenuDeleted',
					'data' => [
						'id'    => $id,
						'kind'  => 'block',
						'name'  => 'Footer navigation',
						'items' => 2,
					],
				],
			],
			$this->sent_menu_events()
		);
	}

	/**
	 * A custom link item with its own place, as the Customizer or REST number them.
	 *
	 * @param int    $menu_id  The menu.
	 * @param string $label    The label.
	 * @param int    $position Its menu_order.
	 * @param int    $parent   The item it is under.
	 *
	 * @return int
	 */
	private function add_item( int $menu_id, string $label, int $position, int $parent = 0 ): int {
		return (int) wp_update_nav_menu_item(
			$menu_id,
			0,
			[
				'menu-item-title'     => $label,
				'menu-item-url'       => 'https://scanfully.com/' . strtolower( $label ) . '/',
				'menu-item-type'      => 'custom',
				'menu-item-status'    => 'publish',
				'menu-item-position'  => $position,
				'menu-item-parent-id' => $parent,
			]
		);
	}

	public function test_items_numbered_per_parent_are_not_moves(): void {
		// The Customizer numbers the items under each parent from 1.
		$menu_id       = wp_create_nav_menu( 'Customizer menu' );
		$this->menus[] = $menu_id;
		$this->add_item( $menu_id, 'Home', 1 );
		$about   = $this->add_item( $menu_id, 'About', 2 );
		$contact = $this->add_item( $menu_id, 'Contact', 3 );
		$this->add_item( $menu_id, 'Team', 1, $about );
		$this->add_item( $menu_id, 'Jobs', 2, $about );
		$this->sent_menu_events();

		wp_update_post(
			[
				'ID'         => $contact,
				'post_title' => 'Get in touch',
			]
		);

		$events = $this->sent_menu_events();
		$this->assertSame( [ 'MenuChanged' ], array_column( $events, 'type' ) );
		$this->assertSame( [ 'renamed' ], array_keys( $events[0]['data']['changes'] ) );
	}

	public function test_a_menu_whose_items_all_have_the_same_number_has_no_moves(): void {
		// REST gives new items menu_order 1.
		$menu_id       = wp_create_nav_menu( 'REST menu' );
		$this->menus[] = $menu_id;
		$items         = [ $this->add_item( $menu_id, 'Home', 1 ), $this->add_item( $menu_id, 'Blog', 1 ), $this->add_item( $menu_id, 'Shop', 1 ) ];
		$this->sent_menu_events();

		// Save Menu without edits renumbers them 1, 2, 3 in the order shown.
		$this->save_menu_screen( $menu_id, array_fill_keys( $items, [] ) );
		$this->assertSame( [], $this->sent_menu_events() );

		$locations                      = (array) get_theme_mod( 'nav_menu_locations' );
		$locations['scanfully-primary'] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		$events = $this->sent_menu_events();
		$this->assertSame( [ 'locations' ], array_keys( $events[0]['data']['changes'] ) );
	}

	public function test_an_item_set_back_to_draft_is_removed(): void {
		[ $menu_id, $items ] = $this->make_menu( 'Main menu', [ 'Home', 'Blog' ] );

		wp_update_post(
			[
				'ID'          => $items[1],
				'post_status' => 'draft',
			]
		);

		$events = $this->sent_menu_events();
		$this->assertSame( [ 'Blog' ], array_column( $events[0]['data']['changes']['removed'], 'label' ) );
		$this->assertSame( $menu_id, $events[0]['data']['id'] );
	}

	public function test_a_block_menu_link_that_goes_elsewhere_is_relinked(): void {
		$id            = wp_insert_post(
			[
				'post_type'    => 'wp_navigation',
				'post_title'   => 'Header navigation',
				'post_status'  => 'publish',
				'post_content' => self::links( [ 'Home', 'Shop' ] ),
			]
		);
		$this->posts[] = $id;
		$this->sent_menu_events();

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => str_replace( 'https://scanfully.com/shop/', 'https://shop.scanfully.com/', self::links( [ 'Home', 'Shop' ] ) ),
			]
		);

		$changes = $this->sent_menu_events()[0]['data']['changes'];
		$this->assertSame( [ 'relinked' ], array_keys( $changes ) );
		$this->assertSame( 'https://shop.scanfully.com/', $changes['relinked'][0]['new'] );
	}

	public function test_a_menu_name_with_an_apostrophe_reads_as_typed(): void {
		// The Menus screen passes the name slashed and escaped.
		$menu_id       = wp_create_nav_menu( wp_slash( esc_html( "Barry's menu" ) ) );
		$this->menus[] = $menu_id;

		$this->assertSame( "Barry's menu", $this->sent_menu_events()[0]['data']['name'] );
	}
}
