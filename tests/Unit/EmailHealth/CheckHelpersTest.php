<?php
/**
 * Email check helper unit tests: attempt nonces and transport detection.
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
final class CheckHelpersTest extends TestCase {

	/**
	 * Generate an attempt nonce.
	 *
	 * @return string
	 */
	private function nonce(): string {
		$method = new ReflectionMethod( Controller::class, 'generate_nonce' );
		$method->setAccessible( true );
		return $method->invoke( null );
	}

	public function test_attempt_nonces_are_version_4_uuids(): void {
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $this->nonce() );
	}

	public function test_attempt_nonces_do_not_repeat_when_mt_rand_is_seeded(): void {
		// A fixed seed, as another plugin might set, makes mt_rand() repeat.
		mt_srand( 42 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- Simulates another plugin.
		$first = $this->nonce();
		mt_srand( 42 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- Simulates another plugin.
		$second = $this->nonce();
		mt_srand(); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- Restore a random seed.

		$this->assertNotSame( $first, $second );
	}

	public function test_only_the_active_smtp_plugin_header_is_read(): void {
		Functions\when( 'is_plugin_active' )->alias( static fn( string $file ) => 'fluent-smtp/fluent-smtp.php' === $file );
		Functions\expect( 'get_plugins' )->never();
		Functions\expect( 'get_plugin_data' )->once()->andReturn( [ 'Version' => '2.2.1' ] );

		$this->assertSame( 'fluent-smtp/2.2.1', Controller::detect_transport() );
	}

	public function test_without_an_smtp_plugin_wp_mail_is_reported(): void {
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( '7.1.1' );
		Functions\expect( 'get_plugin_data' )->never();

		$this->assertSame( 'wp_mail/7.1.1', Controller::detect_transport() );
	}
}
