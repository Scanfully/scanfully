<?php
/**
 * Email check interval unit tests.
 *
 * @package Scanfully\Tests\Unit\EmailHealth
 */

namespace Scanfully\Tests\Unit\EmailHealth;

use Brain\Monkey\Functions;
use ReflectionMethod;
use Scanfully\EmailHealth\Controller;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\EmailHealth\Controller
 */
final class IntervalTest extends TestCase {

	/**
	 * Stored options, keyed by name without prefix.
	 *
	 * @var array<string, string>
	 */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		$this->options = [];
		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				$key = self::key( $name );
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ self::key( $name ) ] = (string) $value;
				return true;
			}
		);
	}

	/**
	 * The option name without the plugin prefix.
	 *
	 * @param string $name Full option name.
	 *
	 * @return string
	 */
	private static function key( string $name ): string {
		return substr( $name, 0, 18 ) === 'scanfully_connect_' ? substr( $name, 18 ) : $name;
	}

	/**
	 * @dataProvider provide_stored_intervals
	 *
	 * @param string $stored   Stored interval.
	 * @param int    $expected Interval in use.
	 */
	public function test_the_interval_in_use_is_clamped( string $stored, int $expected ): void {
		$this->options['email_deliverability_interval_seconds'] = $stored;

		$this->assertSame( $expected, Controller::current_interval_seconds() );
	}

	/**
	 * @return array<string, array{string, int}>
	 */
	public function provide_stored_intervals(): array {
		return [
			'one second'          => [ '1', 15 * MINUTE_IN_SECONDS ],
			'just under minimum'  => [ '899', 15 * MINUTE_IN_SECONDS ],
			'api default 6 hours' => [ '21600', 21600 ],
			'a year'              => [ (string) YEAR_IN_SECONDS, 7 * DAY_IN_SECONDS ],
			'nothing stored'      => [ '', 21600 ],
		];
	}

	public function test_an_interval_from_the_api_is_clamped_before_it_is_stored(): void {
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
				[
					'secret'           => 'secret',
					'inbound_address'  => 'ping+' . str_repeat( 'a', 26 ) . '.{nonce}@inbox.scanfully.com',
					'interval_seconds' => 1,
				]
			)
		);

		$method = new ReflectionMethod( Controller::class, 'provision_credentials' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null ) );
		$this->assertSame( (string) ( 15 * MINUTE_IN_SECONDS ), $this->options['email_deliverability_interval_seconds'] );
	}
}
