<?php
/**
 * WooCommerce feature compatibility integration tests.
 *
 * Runs with WooCommerce active (wp-env) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Scanfully is declared compatible with WooCommerce features.
 */
final class WooCommerceCompatibilityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( FeaturesUtil::class ) ) {
			$this->markTestSkipped( 'WooCommerce not available. Run this suite under wp-env.' );
		}
	}

	/**
	 * @dataProvider provide_features
	 *
	 * @param string $feature WooCommerce feature ID.
	 */
	public function test_scanfully_is_declared_compatible( string $feature ): void {
		$plugins = FeaturesUtil::get_compatible_plugins_for_feature( $feature );

		$this->assertContains( plugin_basename( SCANFULLY_PLUGIN_FILE ), $plugins['compatible'] ?? [] );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function provide_features(): array {
		return [
			'HPOS order storage'   => [ 'custom_order_tables' ],
			'cart/checkout blocks' => [ 'cart_checkout_blocks' ],
		];
	}
}
