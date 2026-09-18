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
	if ( ! class_exists( 'WP_REST_Request' ) ) {
		/**
		 * Stub of WordPress' REST request, limited to headers.
		 */
		class WP_REST_Request {

			/**
			 * Request headers, keyed by canonical name.
			 *
			 * @var array<string, string>
			 */
			private array $headers = [];

			/**
			 * Canonicalize a header name the way WordPress does.
			 *
			 * @param string $key Header name.
			 *
			 * @return string
			 */
			public static function canonicalize_header_name( $key ) {
				return str_replace( '-', '_', strtolower( $key ) );
			}

			/**
			 * Set a header.
			 *
			 * @param string $key   Header name.
			 * @param string $value Header value.
			 *
			 * @return void
			 */
			public function set_header( $key, $value ) {
				$this->headers[ self::canonicalize_header_name( $key ) ] = $value;
			}

			/**
			 * Get a header.
			 *
			 * @param string $key Header name.
			 *
			 * @return string|null
			 */
			public function get_header( $key ) {
				return $this->headers[ self::canonicalize_header_name( $key ) ] ?? null;
			}
		}
	}

	if ( ! class_exists( 'WP_REST_Response' ) ) {
		/**
		 * Stub of WordPress' REST response.
		 */
		class WP_REST_Response {

			/**
			 * Response data.
			 *
			 * @var mixed
			 */
			private $data;

			/**
			 * HTTP status.
			 *
			 * @var int
			 */
			private int $status;

			/**
			 * Response headers.
			 *
			 * @var array<string, string>
			 */
			private array $headers = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $data   Response data.
			 * @param int   $status HTTP status.
			 */
			public function __construct( $data = null, $status = 200 ) {
				$this->data   = $data;
				$this->status = $status;
			}

			/**
			 * Set a header.
			 *
			 * @param string $key   Header name.
			 * @param string $value Header value.
			 *
			 * @return void
			 */
			public function header( $key, $value ) {
				$this->headers[ $key ] = $value;
			}

			/**
			 * Get the HTTP status.
			 *
			 * @return int
			 */
			public function get_status() {
				return $this->status;
			}

			/**
			 * Get the response data.
			 *
			 * @return mixed
			 */
			public function get_data() {
				return $this->data;
			}

			/**
			 * Get the response headers.
			 *
			 * @return array<string, string>
			 */
			public function get_headers() {
				return $this->headers;
			}
		}
	}

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
