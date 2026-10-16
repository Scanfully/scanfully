<?php
/**
 * Permalinks changed event unit tests.
 *
 * @package Scanfully\Tests\Unit\Events
 */

namespace Scanfully\Tests\Unit\Events;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Scanfully\Events\PermalinksChanged;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\PermalinksChanged
 */
final class PermalinksChangedTest extends TestCase {

	/**
	 * Arguments of scheduled jobs.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $scheduled = [];

	protected function setUp(): void {
		parent::setUp();
		$this->scheduled = [];
		Functions\when( 'get_option' )->justReturn( 'yes' );
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) [
				'ID'           => 0,
				'display_name' => '',
			]
		);
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args ) {
				$this->scheduled[] = $args;
				return 1;
			}
		);
	}

	public function test_a_new_structure_is_reported_with_the_old_and_new_value(): void {
		( new PermalinksChanged() )->listener_callback( '/%year%/%postname%/', '/%postname%/', 'permalink_structure' );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( 'PermalinksChanged', $this->scheduled[0]['type'] );
		$this->assertSame(
			[
				'old' => '/%year%/%postname%/',
				'new' => '/%postname%/',
			],
			$this->scheduled[0]['data']
		);
	}

	public function test_plain_permalinks_are_an_empty_string(): void {
		$body = ( new PermalinksChanged() )->get_post_body( [ '/%postname%/', null ] );

		$this->assertSame( '', $body['new'] );
	}

	public function test_an_unchanged_structure_is_not_reported(): void {
		$this->assertFalse( ( new PermalinksChanged() )->should_fire( [ '/%postname%/', '/%postname%/' ] ) );
		$this->assertFalse( ( new PermalinksChanged() )->should_fire( [ false, '' ] ), 'A missing option and an empty one are both plain permalinks.' );
	}

	public function test_adding_the_option_is_reported_as_a_change_from_plain_permalinks(): void {
		$add_listener = null;
		Actions\expectAdded( 'add_option_permalink_structure' )->whenHappen(
			static function ( $callback ) use ( &$add_listener ) {
				$add_listener = $callback;
			}
		);

		new PermalinksChanged();
		$this->assertIsCallable( $add_listener );
		$add_listener( 'permalink_structure', '/%postname%/' );

		$this->assertSame(
			[
				'old' => '',
				'new' => '/%postname%/',
			],
			$this->scheduled[0]['data']
		);
	}

	public function test_nothing_is_queued_while_the_site_is_not_connected(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\expect( 'as_schedule_single_action' )->never();

		( new PermalinksChanged() )->listener_callback( '', '/%postname%/' );
	}
}
