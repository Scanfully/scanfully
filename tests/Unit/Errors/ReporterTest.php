<?php
/**
 * PHP error reporter unit tests: the shape of what is sent.
 *
 * @package Scanfully\Tests\Unit\Errors
 */

namespace Scanfully\Tests\Unit\Errors;

use Scanfully\Errors\Reporter;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Errors\Reporter
 */
final class ReporterTest extends TestCase {

	public function test_an_entry_has_exactly_the_fields_of_the_wire_contract(): void {
		$entry = Reporter::entry(
			[
				'fingerprint' => str_repeat( 'a', 40 ),
				'level'       => 'fatal',
				'source'      => [
					'kind' => 'plugin',
					'slug' => 'shop',
					'name' => 'Shop',
					'version' => '1.2.0',
					'extra' => 'x',
				],
				'message'     => 'Call to undefined function shop_x()',
				'file'        => 'wp-content/plugins/shop/shop.php',
				'line'        => 12,
				'function'    => 'shop_boot',
				'trace'       => [
					[
						'file' => 'wp-settings.php',
						'line' => 545,
						'function' => 'include_once',
						'args' => [ 'x' ],
					],
				],
				'context'     => [
					'request' => 'admin',
					'path' => '/wp-admin/',
					'php' => '8.2.1',
					'wp' => '6.8',
					'maintenance' => true,
				],
				'first_seen'  => 1,
			],
			3,
			1762500000,
			1762500060
		);

		$this->assertSame(
			[
				'fingerprint' => str_repeat( 'a', 40 ),
				'level'       => 'fatal',
				'source'      => [
					'kind' => 'plugin',
					'slug' => 'shop',
					'name' => 'Shop',
					'version' => '1.2.0',
				],
				'message'     => 'Call to undefined function shop_x()',
				'file'        => 'wp-content/plugins/shop/shop.php',
				'line'        => 12,
				'function'    => 'shop_boot',
				'trace'       => [
					[
						'file' => 'wp-settings.php',
						'line' => 545,
						'function' => 'include_once',
					],
				],
				'context'     => [
					'request' => 'admin',
					'path' => '/wp-admin/',
					'php' => '8.2.1',
					'wp' => '6.8',
					'maintenance' => true,
				],
				'count'       => 3,
				'first_seen'  => '2025-11-07T07:20:00Z',
				'last_seen'   => '2025-11-07T07:21:00Z',
			],
			$entry
		);
	}

	public function test_the_payload_has_the_errors_and_the_overflow(): void {
		$this->assertSame(
			[
				'errors' => [],
				'overflow' => 0,
			],
			Reporter::payload( [], -1 )
		);
		$this->assertSame(
			[
				'errors' => [ [ 'level' => 'fatal' ] ],
				'overflow' => 4,
			],
			Reporter::payload( [ [ 'level' => 'fatal' ] ], 4 )
		);
	}
}
