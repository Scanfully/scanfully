<?php
/**
 * Product types the checkout test can buy.
 *
 * @package Scanfully\Tests\Unit\WooCheckout
 */

namespace Scanfully\Tests\Unit\WooCheckout;

use Mockery;
use ReflectionMethod;
use Scanfully\WooCheckout\Controller;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\WooCheckout\Controller
 */
final class ProductTypesTest extends TestCase {

	/**
	 * Call a private static method of the controller.
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 *
	 * @return mixed
	 */
	private function call( string $method, ...$args ) {
		$reflection = new ReflectionMethod( Controller::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, ...$args );
	}

	public function test_subscription_products_are_tried_last_when_subscriptions_is_active(): void {
		// Declares the class WooCommerce Subscriptions is recognised by.
		Mockery::mock( 'WC_Subscriptions_Product' );

		$this->assertSame( [ 'simple', 'variable', 'subscription', 'variable-subscription' ], $this->call( 'product_types' ) );
	}

	public function test_variable_subscriptions_are_bought_through_a_variation(): void {
		$this->assertTrue( $this->call( 'is_variable_type', 'variable' ) );
		$this->assertTrue( $this->call( 'is_variable_type', 'variable-subscription' ) );
		$this->assertFalse( $this->call( 'is_variable_type', 'subscription' ) );
		$this->assertFalse( $this->call( 'is_variable_type', 'simple' ) );
	}
}
