<?php
/**
 * Connect page date formatting unit tests.
 *
 * @package Scanfully\Tests\Unit\Connect
 */

namespace Scanfully\Tests\Unit\Connect;

use Brain\Monkey\Functions;
use ReflectionMethod;
use Scanfully\Connect\Page;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Connect\Page
 */
final class PageDateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->alias(
			static function ( string $name ) {
				$values = [
					'timezone_string' => 'Europe/Amsterdam',
					'date_format'     => 'Y-m-d',
					'time_format'     => 'H:i',
				];
				return $values[ $name ] ?? '';
			}
		);
	}

	/**
	 * Format a stored date the way the connect page does.
	 *
	 * @param string $value Stored date.
	 *
	 * @return string
	 */
	private function format( string $value ): string {
		$method = new ReflectionMethod( Page::class, 'format_stored_date' );
		$method->setAccessible( true );
		return $method->invoke( null, $value );
	}

	public function test_a_stored_utc_date_is_shown_in_the_site_timezone(): void {
		$this->assertSame( '2026-09-19 @ 14:30', $this->format( '2026-09-19 12:30:00' ) );
	}

	/**
	 * @dataProvider provide_unreadable_dates
	 *
	 * @param string $value Stored date.
	 */
	public function test_an_unreadable_date_shows_a_dash_instead_of_breaking_the_page( string $value ): void {
		$this->assertSame( '-', $this->format( $value ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function provide_unreadable_dates(): array {
		return [
			'garbage'        => [ 'not a date' ],
			'iso format'     => [ '2026-09-19T12:30:00Z' ],
			'date only'      => [ '2026-09-19' ],
		];
	}
}
