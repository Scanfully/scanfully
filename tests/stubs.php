<?php
/**
 * Minimal WordPress and WooCommerce class stubs for the unit suite.
 *
 * The unit suite runs without a WordPress runtime (Brain Monkey mocks
 * functions, not classes). PHPUnit's `processUncoveredFiles` coverage option
 * still `include`s every file under `src/`, so any class that `extends` a
 * WordPress or WooCommerce class fatals at load time unless that parent class
 * exists. These empty stubs make such files loadable; real behaviour is
 * exercised by the integration suite under a genuine WordPress runtime.
 *
 * Guarded with `class_exists()` so a real runtime always wins.
 *
 * @package Scanfully\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Namespaces.DisallowCurlyBraceSyntax, Universal.Namespaces.OneDeclarationPerFile, Universal.Namespaces.DisallowDeclarationWithoutName

namespace {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		/**
		 * Stub of WooCommerce's payment gateway base class.
		 */
		abstract class WC_Payment_Gateway {}
	}
}

namespace Automattic\WooCommerce\Blocks\Payments\Integrations {
	if ( ! class_exists( AbstractPaymentMethodType::class ) ) {
		/**
		 * Stub of WooCommerce Blocks' payment method integration base class.
		 */
		abstract class AbstractPaymentMethodType {}
	}
}
