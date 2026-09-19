<?php
/**
 * Connection admin notice unit tests.
 *
 * @package Scanfully\Tests\Unit\Connect
 */

namespace Scanfully\Tests\Unit\Connect;

use Brain\Monkey\Functions;
use Scanfully\Connect\AdminNotice;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Connect\AdminNotice
 */
final class AdminNoticeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->stubEscapeFunctions();
		$this->stubTranslationFunctions();
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.test/wp-admin/' . $path );
	}

	public function test_nothing_is_loaded_outside_wp_admin(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\expect( 'get_option' )->never();

		AdminNotice::setup();

		$this->assertFalse( has_action( 'admin_notices', [ AdminNotice::class, 'print_notice' ] ) );
	}

	public function test_the_welcome_notice_is_registered_in_wp_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		AdminNotice::setup();

		$this->assertNotFalse( has_action( 'admin_notices', [ AdminNotice::class, 'print_notice' ] ) );
	}

	/**
	 * @dataProvider provide_notices
	 *
	 * @param string $method Print method.
	 */
	public function test_users_who_cannot_manage_options_see_no_notice( string $method ): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		ob_start();
		AdminNotice::$method();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * @dataProvider provide_notices
	 *
	 * @param string $method Print method.
	 */
	public function test_administrators_see_the_notice( string $method ): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url );
		Functions\when( 'get_transient' )->justReturn( false );

		ob_start();
		AdminNotice::$method();
		$this->assertStringContainsString( 'scanfully-not-connected-notice', (string) ob_get_clean() );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function provide_notices(): array {
		return [
			'welcome notice'           => [ 'print_notice' ],
			'broken connection notice' => [ 'print_stale_notice' ],
		];
	}
}
