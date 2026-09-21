<?php
/**
 * Site timezone integration tests.
 *
 * Runs against real WordPress settings (wp-env) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Util\Date;

/**
 * Verifies dates follow the site's timezone setting in both of its forms.
 */
final class SiteTimezoneTest extends TestCase {

	/**
	 * Original timezone settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $original = [];

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wp_timezone' ) ) {
			$this->markTestSkipped( 'WordPress runtime not available. Run this suite under wp-env.' );
		}
		$this->original = [
			'timezone_string' => get_option( 'timezone_string' ),
			'gmt_offset'      => get_option( 'gmt_offset' ),
		];
	}

	protected function tearDown(): void {
		if ( function_exists( 'update_option' ) ) {
			foreach ( $this->original as $name => $value ) {
				update_option( $name, $value );
			}
		}
		parent::tearDown();
	}

	public function test_a_manual_utc_offset_is_used(): void {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', '2' );

		$this->assertSame( '+02:00', Date::get_timezone()->getName() );
	}

	public function test_a_city_timezone_is_used(): void {
		update_option( 'timezone_string', 'Europe/Amsterdam' );

		$this->assertSame( 'Europe/Amsterdam', Date::get_timezone()->getName() );
	}
}
