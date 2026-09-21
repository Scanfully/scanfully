<?php
/**
 * Event payload unit tests.
 *
 * @package Scanfully\Tests\Unit\Events
 */

namespace Scanfully\Tests\Unit\Events;

use Brain\Monkey\Functions;
use Scanfully\Events\PluginUpdate;
use Scanfully\Events\ThemeUpdate;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\Event
 * @covers \Scanfully\Events\PluginUpdate
 * @covers \Scanfully\Events\ThemeUpdate
 */
final class EventPayloadTest extends TestCase {

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
				'ID'           => 1,
				'display_name' => 'Admin',
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

	/**
	 * @dataProvider provide_update_events
	 *
	 * @param string $class Event class.
	 */
	public function test_a_non_array_payload_is_ignored_instead_of_crashing( string $class ): void {
		$event = new $class();

		$this->assertFalse( $event->should_fire( [ 'x' ] ) );
		$this->assertSame( [], $event->get_post_body( [ 'x' ] ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function provide_update_events(): array {
		return [
			'plugin update' => [ PluginUpdate::class ],
			'theme update'  => [ ThemeUpdate::class ],
		];
	}

	public function test_a_normal_payload_is_scheduled_unchanged(): void {
		$payload = [
			'name'    => 'Akismet',
			'version' => '5.3',
		];

		( new PluginUpdate() )->listener_callback( $payload );

		$this->assertSame( $payload, $this->scheduled[0]['data'] );
	}

	public function test_an_oversized_payload_is_shortened_to_fit_action_scheduler(): void {
		( new PluginUpdate() )->listener_callback(
			[
				'name'    => str_repeat( 'a', 20000 ),
				'version' => '1.0',
			]
		);

		$this->assertCount( 1, $this->scheduled, 'The event must still be scheduled.' );
		$this->assertLessThanOrEqual( 8000, strlen( (string) json_encode( $this->scheduled[0] ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		$this->assertSame( 1000, strlen( $this->scheduled[0]['data']['name'] ) );
		$this->assertSame( '1.0', $this->scheduled[0]['data']['version'] );
	}

	public function test_a_payload_too_large_to_shorten_is_replaced_by_a_marker(): void {
		( new PluginUpdate() )->listener_callback( array_fill( 0, 500, str_repeat( 'b', 50 ) ) );

		$this->assertSame( [ 'truncated' => true ], $this->scheduled[0]['data'] );
	}
}
