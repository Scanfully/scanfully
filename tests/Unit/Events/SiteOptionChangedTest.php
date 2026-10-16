<?php
/**
 * Site option changed event unit tests.
 *
 * @package Scanfully\Tests\Unit\Events
 */

namespace Scanfully\Tests\Unit\Events;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Scanfully\Events\SiteOptionChanged;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\SiteOptionChanged
 */
final class SiteOptionChangedTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
	}

	public function test_it_listens_to_the_update_hook_of_its_option(): void {
		Actions\expectAdded( 'update_option_blog_public' )->once();

		new SiteOptionChanged( 'blog_public' );
	}

	public function test_the_payload_has_the_option_and_both_values_as_strings(): void {
		$body = ( new SiteOptionChanged( 'blogname' ) )->get_post_body( [ 'Old name', 'New name', 'blogname' ] );

		$this->assertSame(
			[
				'option' => 'blogname',
				'old'    => 'Old name',
				'new'    => 'New name',
			],
			$body
		);
	}

	public function test_the_site_title_is_sent_as_plain_text(): void {
		$body = ( new SiteOptionChanged( 'blogname' ) )->get_post_body( [ 'Tom &amp; Jerry', 'Barry&#039;s Shop' ] );

		$this->assertSame( 'Tom & Jerry', $body['old'] );
		$this->assertSame( "Barry's Shop", $body['new'] );
	}

	public function test_yes_or_no_options_are_sent_as_1_or_0(): void {
		$body = ( new SiteOptionChanged( 'blog_public' ) )->get_post_body( [ '1', 0 ] );

		$this->assertSame( '1', $body['old'] );
		$this->assertSame( '0', $body['new'] );
	}

	public function test_blog_public_keeps_values_other_than_0_and_1(): void {
		$body = ( new SiteOptionChanged( 'blog_public' ) )->get_post_body( [ '1', -2 ] );

		$this->assertSame( '1', $body['old'] );
		$this->assertSame( '-2', $body['new'] );
	}

	/**
	 * @dataProvider provide_unchanged_values
	 *
	 * @param string $option    The option.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $new_value New value.
	 */
	public function test_a_value_that_only_changed_type_is_not_reported( string $option, $old_value, $new_value ): void {
		$this->assertFalse( ( new SiteOptionChanged( $option ) )->should_fire( [ $old_value, $new_value ] ) );
	}

	/**
	 * @return array<string, array{string, mixed, mixed}>
	 */
	public function provide_unchanged_values(): array {
		return [
			'registration off, saved as an integer'    => [ 'users_can_register', '0', 0 ],
			'registration off, saved as null'          => [ 'users_can_register', '0', null ],
			'search engines allowed, saved as integer' => [ 'blog_public', '1', 1 ],
			'same role'                                => [ 'default_role', 'subscriber', 'subscriber' ],
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
		$this->assertTrue( ( new SiteOptionChanged( $option ) )->should_fire( [ $old_value, $new_value ] ) );
	}

	/**
	 * @return array<string, array{string, mixed, mixed}>
	 */
	public function provide_changes(): array {
		return [
			'site address'               => [ 'siteurl', 'http://scanfully.com', 'https://scanfully.com' ],
			'home address'               => [ 'home', 'https://scanfully.com', 'https://www.scanfully.com' ],
			'admin email'                => [ 'admin_email', 'old@scanfully.com', 'new@scanfully.com' ],
			'registration opened'        => [ 'users_can_register', '0', 1 ],
			'default role'               => [ 'default_role', 'subscriber', 'administrator' ],
			'search engines discouraged' => [ 'blog_public', '1', 0 ],
			'privacy plugin level'       => [ 'blog_public', '1', 2 ],
		];
	}

	public function test_an_option_outside_the_list_is_never_reported(): void {
		$this->assertFalse( ( new SiteOptionChanged( 'active_plugins' ) )->should_fire( [ [], [ 'akismet/akismet.php' ] ] ) );
	}

	public function test_every_listed_option_is_reported_as_site_option_changed(): void {
		$scheduled = [];
		Functions\when( 'get_option' )->justReturn( 'yes' );
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) [
				'ID'           => 1,
				'display_name' => 'Admin',
			]
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			static function ( $timestamp, $hook, $args ) use ( &$scheduled ) {
				$scheduled[] = $args;
				return 1;
			}
		);

		foreach ( SiteOptionChanged::OPTIONS as $option ) {
			( new SiteOptionChanged( $option ) )->listener_callback( '0', '1', $option );
		}

		$this->assertCount( count( SiteOptionChanged::OPTIONS ), $scheduled );
		$this->assertSame( [ 'SiteOptionChanged' ], array_values( array_unique( array_column( $scheduled, 'type' ) ) ) );
		$this->assertSame( SiteOptionChanged::OPTIONS, array_column( array_column( $scheduled, 'data' ), 'option' ) );
	}
}
