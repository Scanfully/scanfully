<?php
/**
 * Multisite connection unit tests.
 *
 * @package Scanfully\Tests\Unit\Connect
 */

namespace Scanfully\Tests\Unit\Connect;

use Brain\Monkey\Functions;
use RuntimeException;
use Scanfully\Connect\Controller;
use Scanfully\Connect\Multisite;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Connect\Multisite
 * @covers \Scanfully\Connect\Controller
 */
final class MultisiteTest extends TestCase {

	/**
	 * Whether WordPress runs as a multisite network.
	 *
	 * @var bool
	 */
	private $network = false;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_multisite' )->alias( fn(): bool => $this->network );
	}

	protected function tearDown(): void {
		$_GET = [];
		parent::tearDown();
	}

	public function test_a_single_site_is_not_monitoring_only_and_needs_no_confirmation(): void {
		$this->assertFalse( Multisite::monitoring_only() );
		$this->assertTrue( Multisite::confirmed( [] ) );
	}

	public function test_a_site_of_a_network_is_monitoring_only_and_needs_the_confirmation(): void {
		$this->network = true;

		$this->assertTrue( Multisite::monitoring_only() );
		$this->assertFalse( Multisite::confirmed( [] ), 'no box ticked' );
		$this->assertFalse( Multisite::confirmed( [ Multisite::CONFIRM_FIELD => '' ] ) );
		$this->assertFalse( Multisite::confirmed( [ Multisite::CONFIRM_FIELD => 'yes' ] ) );
		$this->assertTrue( Multisite::confirmed( [ Multisite::CONFIRM_FIELD => '1' ] ) );
	}

	public function test_a_connect_request_on_a_network_without_the_confirmation_goes_back_to_the_page(): void {
		$this->network = true;
		$this->connect_stubs();
		Functions\expect( 'wp_redirect' )->never();
		$_GET = [
			'scanfully-connect'       => '1',
			'scanfully-connect-nonce' => 'nonce',
		];

		$this->assertSame( 'https://site.test/wp-admin/options-general.php?page=scanfully&' . Multisite::CONFIRM_NEEDED . '=1', $this->redirect_of_connect() );
	}

	public function test_a_connect_request_with_the_box_ticked_or_on_a_single_site_starts_the_connection(): void {
		$this->connect_stubs();
		// The request to Scanfully's OAuth server starts with its state: the
		// confirmation let the connect request through.
		Functions\when( 'wp_generate_password' )->alias(
			static function () {
				throw new RuntimeException( 'oauth:started' );
			}
		);
		$_GET = [
			'scanfully-connect'       => '1',
			'scanfully-connect-nonce' => 'nonce',
		];
		$this->assertStringStartsWith( 'oauth:', $this->redirect_of_connect(), 'a single site' );

		$this->network                     = true;
		$_GET[ Multisite::CONFIRM_FIELD ] = '1';
		$this->assertStringStartsWith( 'oauth:', $this->redirect_of_connect(), 'a network with the box ticked' );
	}

	public function test_the_texts_are_there(): void {
		foreach ( [ Multisite::notice(), Multisite::confirmation(), Multisite::connected_note(), Multisite::confirm_needed() ] as $text ) {
			$this->assertNotSame( '', $text );
		}
		$this->assertStringContainsString( 'network administrator', Multisite::notice() );
	}

	/**
	 * Stubs for a connect request by an administrator with a valid nonce.
	 *
	 * @return void
	 */
	private function connect_stubs(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://site.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias(
			static function ( ...$args ): string {
				if ( is_array( $args[0] ) ) {
					return $args[1] . '?' . http_build_query( $args[0] );
				}
				return $args[2] . ( false === strpos( $args[2], '?' ) ? '?' : '&' ) . $args[0] . '=' . $args[1];
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $location ) {
				throw new RuntimeException( $location );
			}
		);
	}

	/**
	 * Run the connect request and return where it redirected.
	 *
	 * @return string
	 */
	private function redirect_of_connect(): string {
		try {
			Controller::catch_connect_requests();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
		$this->fail( 'The connect request did not redirect.' );
	}
}
