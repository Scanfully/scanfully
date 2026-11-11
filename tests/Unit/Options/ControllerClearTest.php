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
 * @covers \Scanfully\Options\Controller
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
		Functions\when( 'is_multisite' )->justReturn( false );

		Controller::clear();

		$this->assertContains( WooCheckoutController::OPTION_PROBE_SECRET, $deleted );
		$this->assertContains( 'scanfully_connect_access_token', $deleted );
		$this->assertContains( 'scanfully_connect_refresh_token', $deleted );
	}

	public function test_clearing_the_network_connection_removes_the_network_options(): void {
		$deleted = [];
		$network = [];
		Functions\when( 'delete_option' )->alias(
			static function ( string $name ) use ( &$deleted ) {
				$deleted[] = $name;
				return true;
			}
		);
		Functions\when( 'delete_site_option' )->alias(
			static function ( string $name ) use ( &$network ) {
				$network[] = $name;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( true );

		Controller::with_scope( true, [ Controller::class, 'clear' ] );

		$this->assertContains( 'scanfully_connect_access_token', $network );
		$this->assertContains( 'scanfully_connect_site_id', $network );
		$this->assertContains( 'scanfully_connect_email_deliverability_secret', $network );
		$this->assertNotContains( 'scanfully_connect_access_token', $deleted, 'the main site keeps its own options' );
		// what the main site's requests wrote for the connection, by name.
		$this->assertContains( WooCheckoutController::OPTION_PROBE_SECRET, $deleted );
		$this->assertContains( 'scanfully_connect_last_request_error', $deleted );
	}

	public function test_the_scope_reads_and_writes_the_network_options(): void {
		$site_options = [ 'scanfully_connect_site_id' => 'network-1' ];
		Functions\when( 'get_site_option' )->alias( static fn( string $name ) => $site_options[ $name ] ?? false );
		Functions\when( 'get_option' )->justReturn( 'site-1' );
		$written = [];
		Functions\when( 'update_site_option' )->alias(
			static function ( string $name, $value ) use ( &$written ) {
				$written[ $name ] = $value;
				return true;
			}
		);

		$this->assertSame( 'site-1', Controller::get_option( 'site_id' ), 'the site\'s own by default' );
		$this->assertSame( 'network-1', Controller::with_scope( true, static fn() => Controller::get_option( 'site_id' ) ) );
		Controller::with_scope(
			true,
			static function (): void {
				Controller::set_option( 'scopes', 'wordpress:read' );
			}
		);
		$this->assertSame( [ 'scanfully_connect_scopes' => 'wordpress:read' ], $written );
		$this->assertFalse( Controller::network_scope(), 'the scope ends with the call' );
	}
}
