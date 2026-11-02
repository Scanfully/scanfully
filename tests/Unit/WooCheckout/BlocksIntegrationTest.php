<?php
/**
 * Block payment method registry unit tests.
 *
 * @package Scanfully\Tests\Unit\WooCheckout
 */

namespace Scanfully\Tests\Unit\WooCheckout;

use ReflectionProperty;
use Scanfully\WooCheckout\BlocksIntegration;
use Scanfully\WooCheckout\Controller;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\WooCheckout\BlocksIntegration::keep_only_probe_method
 */
final class BlocksIntegrationTest extends TestCase {

	protected function tearDown(): void {
		$this->set_probe_request( null );
		parent::tearDown();
	}

	/**
	 * Set the memoised probe-request result.
	 *
	 * @param ?bool $is_probe Whether the current request is a probe, null to reset.
	 */
	private function set_probe_request( ?bool $is_probe ): void {
		$property = new ReflectionProperty( Controller::class, 'probe_request_cache' );
		$property->setAccessible( true );
		$property->setValue( null, $is_probe );
	}

	/**
	 * A registry double holding the given payment method names.
	 *
	 * @param string[] $names Registered payment method names.
	 *
	 * @return object
	 */
	private function registry( array $names ): object {
		return new class( $names ) {
			/**
			 * Registered methods by name.
			 *
			 * @var array<string, bool>
			 */
			public array $registered;

			/**
			 * @param string[] $names Registered payment method names.
			 */
			public function __construct( array $names ) {
				$this->registered = array_fill_keys( $names, true );
			}

			/**
			 * @return array<string, bool>
			 */
			public function get_all_registered(): array {
				return $this->registered;
			}

			/**
			 * @param string $name Payment method name.
			 */
			public function unregister( string $name ): void {
				unset( $this->registered[ $name ] );
			}
		};
	}

	public function test_a_probe_request_keeps_only_the_probe_method(): void {
		$this->set_probe_request( true );
		$registry = $this->registry( [ 'mollie_wc_gateway_ideal', Controller::PROBE_GATEWAY_ID, 'cod' ] );

		BlocksIntegration::keep_only_probe_method( $registry );

		$this->assertSame( [ Controller::PROBE_GATEWAY_ID ], array_keys( $registry->registered ) );
	}

	public function test_a_normal_request_keeps_every_method(): void {
		$this->set_probe_request( false );
		$registry = $this->registry( [ 'mollie_wc_gateway_ideal', Controller::PROBE_GATEWAY_ID, 'cod' ] );

		BlocksIntegration::keep_only_probe_method( $registry );

		$this->assertSame( [ 'mollie_wc_gateway_ideal', Controller::PROBE_GATEWAY_ID, 'cod' ], array_keys( $registry->registered ) );
	}
}
