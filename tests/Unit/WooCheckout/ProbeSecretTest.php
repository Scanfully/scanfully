<?php
/**
 * Probe secret validation unit tests.
 *
 * @package Scanfully\Tests\Unit\WooCheckout
 */

namespace Scanfully\Tests\Unit\WooCheckout;

use ReflectionMethod;
use Scanfully\WooCheckout\Controller;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\WooCheckout\Controller
 */
final class ProbeSecretTest extends TestCase {

	/**
	 * Call the private secret validator.
	 *
	 * @param string $secret The secret.
	 *
	 * @return bool
	 */
	private function is_valid( string $secret ): bool {
		$method = new ReflectionMethod( Controller::class, 'is_valid_probe_secret' );
		$method->setAccessible( true );
		return $method->invoke( null, $secret );
	}

	public function test_a_64_character_hex_secret_is_valid(): void {
		$this->assertTrue( $this->is_valid( bin2hex( random_bytes( 32 ) ) ) );
	}

	/**
	 * @dataProvider provide_invalid_secrets
	 *
	 * @param string $secret Invalid secret.
	 */
	public function test_malformed_secrets_are_rejected( string $secret ): void {
		$this->assertFalse( $this->is_valid( $secret ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function provide_invalid_secrets(): array {
		return [
			'empty'      => [ '' ],
			'too short'  => [ str_repeat( 'a', 63 ) ],
			'too long'   => [ str_repeat( 'a', 65 ) ],
			'uppercase'  => [ str_repeat( 'A', 64 ) ],
			'non-hex'    => [ str_repeat( 'z', 64 ) ],
			'trailing newline' => [ str_repeat( 'a', 64 ) . "\n" ],
		];
	}
}
