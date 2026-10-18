<?php
/**
 * Reported settings unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\SettingsChanges
 */

namespace Scanfully\Tests\Unit\Events\SettingsChanges;

use Brain\Monkey\Functions;
use Scanfully\Events\SettingsChanges\Settings;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\SettingsChanges\Settings
 */
final class SettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
	}

	public function test_a_change_has_the_option_and_both_values_as_strings(): void {
		$this->assertSame(
			[
				'option' => 'posts_per_page',
				'old'    => '10',
				'new'    => '12',
			],
			Settings::change( 'posts_per_page', '10', 12 )
		);
	}

	public function test_the_site_title_and_tagline_are_sent_as_plain_text(): void {
		$change = Settings::change( 'blogname', 'Tom &amp; Jerry', 'Barry&#039;s Shop' );
		$this->assertSame( 'Tom & Jerry', $change['old'] );
		$this->assertSame( "Barry's Shop", $change['new'] );

		$this->assertSame( 'Shop & more', Settings::change( 'blogdescription', 'Just another site', 'Shop &amp; more' )['new'] );
	}

	public function test_search_engine_visibility_keeps_values_other_than_0_and_1(): void {
		$this->assertSame( '-2', Settings::change( 'blog_public', '1', -2 )['new'] );
		$this->assertSame( '0', Settings::change( 'blog_public', '1', 0 )['new'] );
	}

	/**
	 * @dataProvider provide_unchanged_values
	 *
	 * @param string $option    The option.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $new_value New value.
	 */
	public function test_a_value_that_only_changed_type_is_no_change( string $option, $old_value, $new_value ): void {
		$this->assertSame( [], Settings::change( $option, $old_value, $new_value ) );
	}

	/**
	 * @return array<string, array{string, mixed, mixed}>
	 */
	public function provide_unchanged_values(): array {
		return [
			'registration off, saved as an integer'    => [ 'users_can_register', '0', 0 ],
			'registration off, saved as null'          => [ 'users_can_register', '0', null ],
			'avatars off, saved as an empty string'    => [ 'show_avatars', '0', '' ],
			'search engines allowed, saved as integer' => [ 'blog_public', '1', 1 ],
			'same role'                                => [ 'default_role', 'subscriber', 'subscriber' ],
			'posts per page as an integer'             => [ 'posts_per_page', '10', 10 ],
			'no front page, saved as an empty string'  => [ 'page_on_front', '0', '' ],
			'same word list, other line endings'       => [ 'disallowed_keys', "spam\nviagra", "spam\r\nviagra\n" ],
		];
	}

	/**
	 * @dataProvider provide_changes
	 *
	 * @param string $option    The option.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $new_value New value.
	 */
	public function test_a_real_change_is_reported( string $option, $old_value, $new_value ): void {
		$this->assertNotSame( [], Settings::change( $option, $old_value, $new_value ) );
	}

	/**
	 * @return array<string, array{string, mixed, mixed}>
	 */
	public function provide_changes(): array {
		return [
			'site address'               => [ 'siteurl', 'http://scanfully.com', 'https://scanfully.com' ],
			'admin email'                => [ 'admin_email', 'old@scanfully.com', 'new@scanfully.com' ],
			'registration opened'        => [ 'users_can_register', '0', 1 ],
			'search engines discouraged' => [ 'blog_public', '1', 0 ],
			'tagline'                    => [ 'blogdescription', 'Old', 'New' ],
			'language'                   => [ 'WPLANG', '', 'nl_NL' ],
			'static front page'          => [ 'page_on_front', '0', '12' ],
			'comments need approval'     => [ 'comment_moderation', '', '1' ],
			'thumbnail width'            => [ 'thumbnail_size_w', '150', '200' ],
			'category base'              => [ 'category_base', '', 'topics' ],
			'privacy policy page'        => [ 'wp_page_for_privacy_policy', '3', '7' ],
		];
	}

	public function test_an_option_outside_the_list_is_not_reported(): void {
		$this->assertFalse( Settings::is_reported( 'active_plugins' ) );
		$this->assertFalse( Settings::is_reported( 'permalink_structure' ), 'PermalinksChanged reports the structure.' );
		$this->assertTrue( Settings::is_reported( 'blogdescription' ) );
	}

	public function test_secrets_only_say_they_changed(): void {
		$this->assertSame(
			[
				'option' => 'mailserver_pass',
				'secret' => true,
			],
			Settings::change( 'mailserver_pass', 'old-password', 'new-password' )
		);
		$this->assertSame(
			[
				'option' => 'mailserver_login',
				'secret' => true,
			],
			Settings::change( 'mailserver_login', 'login@scanfully.com', 'other@scanfully.com' )
		);
	}

	public function test_word_lists_only_say_how_many_lines_were_added_and_removed(): void {
		$change = Settings::change( 'disallowed_keys', "spam\n203.0.113.9\nbad@scanfully.com", "spam\nbad@scanfully.com\nviagra\ncasino" );

		$this->assertSame(
			[
				'option'  => 'disallowed_keys',
				'added'   => 2,
				'removed' => 1,
			],
			$change
		);
		$this->assertStringNotContainsString( 'scanfully.com', (string) json_encode( $change ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
	}

	public function test_a_line_listed_twice_and_kept_once_was_removed_once(): void {
		$this->assertSame( [ [], [ 'spam' ] ], Settings::line_changes( "spam\nspam", 'spam' ) );
	}

	public function test_update_services_list_the_urls_added_and_removed(): void {
		$this->assertSame(
			[
				'option'  => 'ping_sites',
				'added'   => [ 'https://ping.scanfully.com/' ],
				'removed' => [ 'http://rpc.pingomatic.com/' ],
			],
			Settings::change( 'ping_sites', 'http://rpc.pingomatic.com/', "https://ping.scanfully.com/\n" )
		);
	}

	public function test_long_values_are_cut(): void {
		$change = Settings::change( 'blogdescription', 'Short', str_repeat( 'a', 1500 ) );

		$this->assertSame( Settings::MAX_VALUE, strlen( $change['new'] ) );
	}

	/**
	 * @dataProvider provide_timezones
	 *
	 * @param mixed  $zone     The timezone_string option.
	 * @param mixed  $offset   The gmt_offset option.
	 * @param string $expected The timezone as shown.
	 */
	public function test_the_timezone_is_the_zone_or_the_offset( $zone, $offset, string $expected ): void {
		$this->assertSame( $expected, Settings::timezone( $zone, $offset ) );
	}

	/**
	 * @return array<string, array{mixed, mixed, string}>
	 */
	public function provide_timezones(): array {
		return [
			'a city'               => [ 'Europe/Amsterdam', '2', 'Europe/Amsterdam' ],
			'UTC+2'                => [ '', '2', 'UTC+2' ],
			'UTC-3:30'             => [ '', '-3.5', 'UTC-3:30' ],
			'UTC+5:45'             => [ '', 5.75, 'UTC+5:45' ],
			'UTC'                  => [ '', '0', 'UTC+0' ],
			'nothing saved at all' => [ false, false, 'UTC+0' ],
		];
	}

	public function test_the_job_adds_page_titles(): void {
		Functions\when( 'get_post' )->alias(
			static fn( $id ) => new \WP_Post(
				(object) [
					'ID'         => $id,
					'post_title' => 12 === $id ? 'Home &amp; away' : 'Blog',
				]
			)
		);

		$data = Settings::resolve(
			[
				'option' => 'page_on_front',
				'old'    => '0',
				'new'    => '12',
			]
		);

		$this->assertSame( 'Home & away', $data['new_name'] );
		$this->assertArrayNotHasKey( 'old_name', $data, 'No page before.' );
	}

	public function test_the_job_adds_an_example_of_a_date_format(): void {
		Functions\when( 'wp_date' )->alias( static fn( $format, $timestamp ) => gmdate( $format, $timestamp ) );

		$data = Settings::resolve(
			[
				'option' => 'date_format',
				'old'    => 'F j, Y',
				'new'    => 'd/m/Y',
			]
		);

		$this->assertSame( 'January 31, 2026', $data['old_example'] );
		$this->assertSame( '31/01/2026', $data['new_example'] );
	}

	public function test_the_job_leaves_other_settings_alone(): void {
		$data = [
			'option' => 'posts_per_page',
			'old'    => '10',
			'new'    => '12',
		];

		$this->assertSame( $data, Settings::resolve( $data ) );
	}
}
