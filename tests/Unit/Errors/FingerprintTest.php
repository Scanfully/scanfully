<?php
/**
 * PHP error fingerprint unit tests.
 *
 * @package Scanfully\Tests\Unit\Errors
 */

namespace Scanfully\Tests\Unit\Errors;

use Scanfully\Errors\Fingerprint;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Errors\Fingerprint
 */
final class FingerprintTest extends TestCase {

	public function test_numbers_quoted_values_and_identifiers_are_replaced(): void {
		$this->assertSame( 'Undefined array key "?"', Fingerprint::normalize( 'Undefined array key "customer_id"' ) );
		$this->assertSame( 'Undefined array key ?', Fingerprint::normalize( 'Undefined array key 3' ) );
		$this->assertSame( "Undefined index: '?'", Fingerprint::normalize( "Undefined index: 'it\\'s'" ) );
		$this->assertSame( 'Allowed memory size of ? bytes exhausted (tried to allocate ? bytes)', Fingerprint::normalize( 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)' ) );
		$this->assertSame( 'Order ? was paid ? (ref ?)', Fingerprint::normalize( "Order 1234 was paid  12.50\n(ref 9f86d081884c7d659a2feaa0c55ad015)" ) );
		$this->assertSame( 'Call to undefined function wc_get_order_v2()', Fingerprint::normalize( 'Call to undefined function wc_get_order_v2()' ) );
	}

	public function test_the_same_error_on_another_line_or_with_another_value_has_the_same_fingerprint(): void {
		$first  = Fingerprint::of( 'warning', 'wp-content/plugins/shop/orders.php', 'shop_total', 'Undefined array key "total" for order 12' );
		$second = Fingerprint::of( 'warning', 'wp-content/plugins/shop/orders.php', 'shop_total', 'Undefined array key "tax" for order 999' );

		$this->assertSame( $first, $second );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $first );
	}

	public function test_the_level_file_and_function_tell_errors_apart(): void {
		$base = Fingerprint::of( 'warning', 'a.php', 'f', 'm' );

		$this->assertNotSame( $base, Fingerprint::of( 'fatal', 'a.php', 'f', 'm' ) );
		$this->assertNotSame( $base, Fingerprint::of( 'warning', 'b.php', 'f', 'm' ) );
		$this->assertNotSame( $base, Fingerprint::of( 'warning', 'a.php', 'g', 'm' ) );
		$this->assertNotSame( $base, Fingerprint::of( 'warning', 'a.php', 'f', 'other' ) );
	}
}
