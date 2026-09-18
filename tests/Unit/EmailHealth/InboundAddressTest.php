<?php
/**
 * Inbound address validation unit tests.
 *
 * @package Scanfully\Tests\Unit\EmailHealth
 */

namespace Scanfully\Tests\Unit\EmailHealth;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use ReflectionMethod;
use Scanfully\EmailHealth\Controller;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\EmailHealth\Controller
 */
final class InboundAddressTest extends TestCase {

	private const NONCE = '0e5a6c9e-3b1f-4d2a-9c7e-5f8b2a1d4c6e';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
	}

	/**
	 * Call a private static method on the EmailHealth controller.
	 *
	 * @param string $name    Method name.
	 * @param mixed  ...$args Arguments.
	 *
	 * @return mixed
	 */
	private function call( string $name, ...$args ) {
		$method = new ReflectionMethod( Controller::class, $name );
		$method->setAccessible( true );
		return $method->invoke( null, ...$args );
	}

	/**
	 * A template in the format the API builds.
	 *
	 * @param string $domain Inbound domain.
	 *
	 * @return string
	 */
	private function template( string $domain = 'inbox.scanfully.com' ): string {
		return 'ping+' . str_repeat( 'a', 26 ) . '.{nonce}@' . $domain;
	}

	/**
	 * @dataProvider provide_valid_templates
	 *
	 * @param string $template Template.
	 */
	public function test_api_templates_on_scanfully_domains_are_accepted( string $template ): void {
		$this->assertTrue( $this->call( 'is_valid_inbound_template', $template ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function provide_valid_templates(): array {
		$site = str_repeat( 'a', 26 );
		return [
			'production inbox' => [ "ping+{$site}.{nonce}@inbox.scanfully.com" ],
			'scanfully.dev'    => [ "ping+{$site}.{nonce}@inbox.scanfully.dev" ],
			'apex domain'      => [ "ping+{$site}.{nonce}@scanfully.com" ],
		];
	}

	/**
	 * @dataProvider provide_invalid_templates
	 *
	 * @param string $template Template.
	 */
	public function test_other_templates_are_rejected( string $template ): void {
		$this->assertFalse( $this->call( 'is_valid_inbound_template', $template ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function provide_invalid_templates(): array {
		$site = str_repeat( 'a', 26 );
		return [
			'other domain'         => [ "ping+{$site}.{nonce}@evil.test" ],
			'lookalike suffix'     => [ "ping+{$site}.{nonce}@inbox.scanfully.com.evil.test" ],
			'lookalike prefix'     => [ "ping+{$site}.{nonce}@evilscanfully.com" ],
			'extra recipient'      => [ "ping+{$site}.{nonce}@inbox.scanfully.com,victim@example.com" ],
			'other local part'     => [ "anyone+{$site}.{nonce}@inbox.scanfully.com" ],
			'short site segment'   => [ 'ping+abc.{nonce}@inbox.scanfully.com' ],
			'missing placeholder'  => [ "ping+{$site}.{$site}@inbox.scanfully.com" ],
			'trailing newline'     => [ "ping+{$site}.{nonce}@inbox.scanfully.com\n" ],
			'empty'                => [ '' ],
		];
	}

	public function test_expanding_a_valid_template_gives_one_ping_address(): void {
		$address = $this->call( 'expand_inbound_address', $this->template(), self::NONCE );

		$this->assertMatchesRegularExpression( '/^ping\+a{26}\.[a-z2-7]{26}@inbox\.scanfully\.com$/', $address );
	}

	public function test_expanding_a_tampered_stored_template_sends_nothing(): void {
		$address = $this->call( 'expand_inbound_address', $this->template() . ',victim@example.com', self::NONCE );

		$this->assertSame( '', $address );
	}

	public function test_extra_domains_can_be_allowed_with_a_filter(): void {
		Filters\expectApplied( 'scanfully_email_deliverability_inbound_domains' )->andReturn( [ 'scanfully.com', 'mailpit.test' ] );

		$this->assertTrue( $this->call( 'is_valid_inbound_template', $this->template( 'mailpit.test' ) ) );
	}

	public function test_provisioning_ignores_an_address_outside_scanfully(): void {
		$saved = [];
		Functions\when( 'get_option' )->justReturn( 'site-1' );
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value ) use ( &$saved ) {
				$saved[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
				[
					'secret'           => 'secret',
					'inbound_address'  => 'ping+' . str_repeat( 'a', 26 ) . '.{nonce}@inbox.scanfully.com,victim@example.com',
					'interval_seconds' => 3600,
				]
			)
		);
		Functions\when( 'delete_transient' )->justReturn( true );

		$this->assertFalse( $this->call( 'provision_credentials' ) );
		$this->assertSame( [], $saved, 'Nothing may be stored from a rejected response.' );
	}
}
