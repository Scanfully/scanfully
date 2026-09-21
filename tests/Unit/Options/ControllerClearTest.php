<?php
/**
 * Options clear unit tests.
 *
 * @package Scanfully\Tests\Unit\Options
 */

namespace Scanfully\Tests\Unit\Options;

use Brain\Monkey\Functions;
use Scanfully\Options\Controller;
use Scanfully\WooCheckout\Controller as WooCheckoutController;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Options\Controller::clear
 */
final class ControllerClearTest extends TestCase {

	public function test_clear_removes_the_woocheckout_probe_secret(): void {
		$deleted = [];
		Functions\when( 'delete_option' )->alias(
			static function ( string $name ) use ( &$deleted ) {
				$deleted[] = $name;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->justReturn( true );

		Controller::clear();

		$this->assertContains( WooCheckoutController::OPTION_PROBE_SECRET, $deleted );
		$this->assertContains( 'scanfully_connect_access_token', $deleted );
		$this->assertContains( 'scanfully_connect_refresh_token', $deleted );
	}
}
