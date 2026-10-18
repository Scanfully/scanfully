<?php
/**
 * Settings change collector unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\SettingsChanges
 */

namespace Scanfully\Tests\Unit\Events\SettingsChanges;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Scanfully\Events\ContentEvent;
use Scanfully\Events\SettingsChanges\Collector;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\SettingsChanges\Collector
 */
final class CollectorTest extends TestCase {

	/**
	 * The stored options get_option() returns.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * The rows reported.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $reported = [];

	/**
	 * Whether WordPress is installing or upgrading.
	 *
	 * @var bool
	 */
	private bool $installing = false;

	/**
	 * The signed-in user.
	 *
	 * @var int
	 */
	private int $user = 1;

	/**
	 * The current site (multisite).
	 *
	 * @var int
	 */
	private int $blog = 1;

	/**
	 * The site each row was reported on.
	 *
	 * @var array<int, int>
	 */
	private array $reported_on = [];

	protected function setUp(): void {
		parent::setUp();
		Collector::reset();
		$this->options  = [
			'admin_email'                    => 'admin@scanfully.com',
			'timezone_string'                => 'Europe/Amsterdam',
			'gmt_offset'                     => '0',
			'scanfully_connect_is_connected' => 'yes',
		];
		$this->reported = [];

		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'get_option' )->alias( fn( $name, $fallback = false ) => $this->options[ $name ] ?? $fallback );
		Functions\when( 'wp_installing' )->alias( fn() => $this->installing );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->alias( fn() => $this->blog );
		Functions\when( 'get_current_user_id' )->alias( fn() => $this->user );

		Actions\expectDone( ContentEvent::hook( 'SiteOptionChanged' ) )->zeroOrMoreTimes()->whenHappen(
			function ( $data ) {
				$this->reported[]    = $data;
				$this->reported_on[] = $this->blog;
			}
		);
	}

	protected function tearDown(): void {
		Collector::reset();
		parent::tearDown();
	}

	public function test_a_save_of_several_settings_is_a_row_each_in_screen_order(): void {
		Collector::note_updated( 'show_avatars', '1', '' );
		Collector::note_updated( 'default_comment_status', 'open', 'closed' );
		Collector::note_updated( 'comment_moderation', '', '1' );
		Collector::flush();

		$this->assertSame( [ 'default_comment_status', 'comment_moderation', 'show_avatars' ], array_column( $this->reported, 'option' ) );
	}

	public function test_an_option_that_is_no_setting_is_ignored(): void {
		Collector::note_updated( 'active_plugins', [], [ 'akismet/akismet.php' ] );
		Collector::note_updated( '_transient_doing_cron', '1', '2' );
		Collector::flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_a_setting_changed_twice_keeps_the_first_old_and_the_last_new_value(): void {
		Collector::note_updated( 'posts_per_page', '10', '20' );
		Collector::note_updated( 'posts_per_page', '20', '12' );
		Collector::flush();

		$this->assertSame(
			[
				[
					'option' => 'posts_per_page',
					'old'    => '10',
					'new'    => '12',
				],
			],
			$this->reported
		);
	}

	public function test_a_setting_changed_and_changed_back_is_nothing(): void {
		Collector::note_updated( 'posts_per_page', '10', '20' );
		Collector::note_updated( 'posts_per_page', '20', '10' );
		Collector::flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_a_setting_saved_for_the_first_time_changed_from_nothing(): void {
		Collector::note_added( 'WPLANG', 'nl_NL' );
		Collector::flush();

		$this->assertSame(
			[
				[
					'option' => 'WPLANG',
					'old'    => '',
					'new'    => 'nl_NL',
				],
			],
			$this->reported
		);
	}

	public function test_installs_and_database_upgrades_are_left_out(): void {
		$this->installing = true;
		Collector::note_updated( 'blogdescription', 'Old', 'New' );
		Collector::flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_the_two_timezone_options_are_one_row(): void {
		// Choosing UTC+2 instead of a city: the offset is set and the zone emptied.
		Collector::note_updated( 'timezone_string', 'Europe/Amsterdam', '' );
		Collector::note_updated( 'gmt_offset', '0', '2' );
		Collector::flush();

		$this->assertSame(
			[
				[
					'option' => 'timezone',
					'old'    => 'Europe/Amsterdam',
					'new'    => 'UTC+2',
				],
			],
			$this->reported
		);
	}

	public function test_an_offset_saved_next_to_a_city_zone_is_no_change(): void {
		// Saving a city zone sends an empty offset; the shown zone stays the same.
		Collector::note_updated( 'gmt_offset', '2', '' );
		Collector::flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_an_admin_email_change_request_is_reported_without_the_hash(): void {
		Collector::note_added(
			'adminhash',
			[
				'hash'     => 'secret-hash',
				'newemail' => 'new@scanfully.com',
			]
		);
		Collector::flush();

		$this->assertSame(
			[
				[
					'option' => 'new_admin_email',
					'old'    => 'admin@scanfully.com',
					'new'    => 'new@scanfully.com',
				],
			],
			$this->reported
		);
		$this->assertStringNotContainsString( 'secret-hash', (string) json_encode( $this->reported ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
	}

	public function test_a_withdrawn_request_is_reported_as_cancelled(): void {
		$this->options['adminhash'] = [
			'hash'     => 'secret-hash',
			'newemail' => 'new@scanfully.com',
		];
		Collector::note_deleting( 'adminhash' );
		Collector::flush();

		$this->assertSame(
			[
				[
					'option'    => 'new_admin_email',
					'old'       => 'admin@scanfully.com',
					'new'       => 'new@scanfully.com',
					'cancelled' => true,
				],
			],
			$this->reported
		);
	}

	public function test_a_confirmed_request_is_only_the_admin_email_changing(): void {
		$this->options['adminhash'] = [
			'hash'     => 'secret-hash',
			'newemail' => 'new@scanfully.com',
		];
		Collector::note_updated( 'admin_email', 'admin@scanfully.com', 'new@scanfully.com' );
		Collector::note_deleting( 'adminhash' );
		Collector::flush();

		$this->assertSame(
			[
				[
					'option' => 'admin_email',
					'old'    => 'admin@scanfully.com',
					'new'    => 'new@scanfully.com',
				],
			],
			$this->reported
		);
	}

	public function test_rows_over_the_per_minute_cap_are_dropped_but_key_settings_never(): void {
		Functions\when( 'get_transient' )->justReturn( 29 );
		Collector::note_updated( 'posts_per_page', '10', '12' );
		Collector::note_updated( 'posts_per_rss', '10', '12' );
		Collector::note_updated( 'default_role', 'subscriber', 'administrator' );
		Collector::note_updated( 'users_can_register', '0', '1' );
		Collector::flush();

		$this->assertSame( [ 'users_can_register', 'default_role', 'posts_per_page' ], array_column( $this->reported, 'option' ) );
	}

	public function test_nothing_is_reported_for_a_site_that_is_not_connected(): void {
		$this->options['scanfully_connect_is_connected'] = 'no';
		Collector::note_updated( 'posts_per_page', '10', '12' );
		Collector::flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_a_deleted_setting_changed_to_empty(): void {
		// Deleting the site icon's file deletes the option.
		$this->options['site_icon'] = '42';
		Collector::note_deleting( 'site_icon' );
		Collector::flush();

		$this->assertSame(
			[
				[
					'option' => 'site_icon',
					'old'    => '42',
					'new'    => '0',
				],
			],
			$this->reported
		);
	}

	public function test_a_change_published_by_cron_names_who_made_it(): void {
		// A scheduled Customizer change: WordPress acts as its author, then
		// signs out again before the request ends.
		$this->user = 7;
		Collector::note_updated( 'blogdescription', 'Old', 'New' );
		$this->user = 0;
		Collector::flush();

		$this->assertSame( 7, $this->reported[0]['_actor'] );
	}

	public function test_a_change_by_the_signed_in_user_names_no_one_else(): void {
		Collector::note_updated( 'blogdescription', 'Old', 'New' );
		Collector::flush();

		$this->assertArrayNotHasKey( '_actor', $this->reported[0] );
	}

	public function test_open_or_closed_settings_saved_empty_are_no_change(): void {
		// An unticked box is saved as ''; REST saves 'closed'.
		Collector::note_updated( 'default_comment_status', 'closed', '' );
		Collector::flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_another_sites_setting_is_reported_on_that_site(): void {
		$switches = [];
		Functions\when( 'switch_to_blog' )->alias(
			function ( $blog ) use ( &$switches ) {
				$switches[] = $blog;
				$this->blog = (int) $blog;
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function () {
				$this->blog = 1;
				return true;
			}
		);
		// Network Admin, Sites, Edit: WordPress switches to site 5 and back.
		$this->blog = 5;
		Collector::note_updated( 'blog_public', '1', '0' );
		$this->blog = 1;
		Collector::note_updated( 'blogdescription', 'Old', 'New' );
		Collector::flush();

		$this->assertSame( [ 5 ], $switches );
		$this->assertSame( [ 5, 1 ], $this->reported_on );
		$this->assertSame( [ 'blog_public', 'blogdescription' ], array_column( $this->reported, 'option' ) );
		$this->assertSame( 1, $this->blog, 'Back on the site of the request.' );
	}

	public function test_a_flush_with_nothing_noted_reads_nothing(): void {
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'get_option' )->never();

		Collector::flush();

		$this->assertSame( [], $this->reported );
	}

	public function test_a_flush_forgets_what_it_reported(): void {
		Collector::note_updated( 'posts_per_page', '10', '12' );
		Collector::flush();
		Collector::flush();

		$this->assertCount( 1, $this->reported );
	}
}
