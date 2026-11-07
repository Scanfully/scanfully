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
	if ( ! class_exists( 'WP_Post' ) ) {
		/**
		 * Stub of WordPress' post, which copies the fields it is given.
		 */
		#[AllowDynamicProperties]
		final class WP_Post {
			/**
			 * Constructor.
			 *
			 * @param object $post The post fields.
			 */
			public function __construct( $post ) {
				foreach ( get_object_vars( $post ) as $key => $value ) {
					$this->$key = $value;
				}
			}
		}
	}

	if ( ! class_exists( 'WP_Term' ) ) {
		/**
		 * Stub of WordPress' term, which copies the fields it is given.
		 */
		#[AllowDynamicProperties]
		final class WP_Term {
			/**
			 * Constructor.
			 *
			 * @param object $term The term fields.
			 */
			public function __construct( $term ) {
				foreach ( get_object_vars( $term ) as $key => $value ) {
					$this->$key = $value;
				}
			}
		}
	}

	if ( ! class_exists( 'WP_Upgrader' ) ) {
		/**
		 * Stub of WordPress' upgrader base, so code that loads the upgraders
		 * when they are missing finds them loaded.
		 */
		class WP_Upgrader {
			/**
			 * The upgrader's messages, by key.
			 *
			 * @var array<string, string>
			 */
			public $strings = [];
		}
	}

	if ( ! class_exists( 'WP_Upgrader_Skin' ) ) {
		/**
		 * Stub of WordPress' upgrader skin: keeps the result it is given.
		 */
		class WP_Upgrader_Skin {
			/**
			 * The upgrader's result.
			 *
			 * @var mixed
			 */
			public $result = false;

			/**
			 * The upgrader, for its strings.
			 *
			 * @var object|null
			 */
			public $upgrader = null;

			/**
			 * Constructor.
			 *
			 * @param array $args Unused.
			 */
			public function __construct( $args = [] ) {
				unset( $args );
			}

			/**
			 * Keep the result.
			 *
			 * @param mixed $result The result.
			 *
			 * @return void
			 */
			public function set_result( $result ) {
				$this->result = $result;
			}
		}
	}

	if ( ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
		/**
		 * Stub of WordPress' background upgrader skin: collects the messages,
		 * translating the upgrader's string keys as WordPress does.
		 */
		class Automatic_Upgrader_Skin extends WP_Upgrader_Skin {
			/**
			 * The messages.
			 *
			 * @var string[]
			 */
			protected $messages = [];

			/**
			 * Keep a message.
			 *
			 * @param mixed $feedback The message or its key.
			 * @param mixed ...$args  Replacements.
			 *
			 * @return void
			 */
			public function feedback( $feedback, ...$args ) {
				if ( $feedback instanceof WP_Error ) {
					$feedback = $feedback->get_error_message();
				}
				if ( ! is_string( $feedback ) ) {
					return;
				}
				if ( is_object( $this->upgrader ) && isset( $this->upgrader->strings[ $feedback ] ) ) {
					$feedback = $this->upgrader->strings[ $feedback ];
				}
				$this->messages[] = $args ? vsprintf( $feedback, $args ) : $feedback;
			}

			/**
			 * The messages.
			 *
			 * @return string[]
			 */
			public function get_upgrade_messages() {
				return $this->messages;
			}
		}
	}

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

	if ( ! class_exists( 'WP_Error' ) ) {
		/**
		 * Stub of WordPress' error, with its message.
		 */
		class WP_Error {

			/**
			 * The error message.
			 *
			 * @var string
			 */
			private string $message;

			/**
			 * The error code.
			 *
			 * @var string
			 */
			private string $code;

			/**
			 * The error data.
			 *
			 * @var mixed
			 */
			private $data;

			/**
			 * Constructor.
			 *
			 * @param string $code    Error code.
			 * @param string $message Error message.
			 * @param mixed  $data    Error data.
			 */
			public function __construct( $code = '', $message = '', $data = '' ) {
				$this->code    = (string) $code;
				$this->message = (string) $message;
				$this->data    = $data;
			}

			/**
			 * Get the error code.
			 *
			 * @return string
			 */
			public function get_error_code() {
				return $this->code;
			}

			/**
			 * Get the error data.
			 *
			 * @return mixed
			 */
			public function get_error_data() {
				return $this->data;
			}

			/**
			 * Get the error message.
			 *
			 * @return string
			 */
			public function get_error_message() {
				return $this->message;
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
		abstract class WC_Payment_Gateway {

			/**
			 * Gateway ID.
			 *
			 * @var string
			 */
			public $id = '';

			/**
			 * Whether the gateway is enabled ('yes' or 'no').
			 *
			 * @var string
			 */
			public $enabled = 'no';

			/**
			 * Title shown at checkout.
			 *
			 * @var string
			 */
			public $title = '';

			/**
			 * Description shown at checkout.
			 *
			 * @var string
			 */
			public $description = '';

			/**
			 * Title shown in the admin.
			 *
			 * @var string
			 */
			public $method_title = '';

			/**
			 * Description shown in the admin.
			 *
			 * @var string
			 */
			public $method_description = '';

			/**
			 * Whether the gateway has payment fields.
			 *
			 * @var bool
			 */
			public $has_fields = false;

			/**
			 * Supported features.
			 *
			 * @var array<int, string>
			 */
			public $supports = [ 'products' ];
		}
	}

	if ( ! class_exists( 'ActionScheduler' ) ) {
		/**
		 * Stub of Action Scheduler's facade, for the lock and store the sync
		 * endpoint uses to start the queue runner. The stubs record what they
		 * are asked in $GLOBALS['scanfully_test_action_scheduler'].
		 */
		class ActionScheduler {
			/**
			 * The lock.
			 *
			 * @return ActionScheduler_Lock
			 */
			public static function lock() {
				return new ActionScheduler_Lock();
			}

			/**
			 * The store.
			 *
			 * @return null
			 */
			public static function store() {
				return null;
			}

			/**
			 * Whether the data store is ready: true unless a test sets
			 * $GLOBALS['scanfully_test_action_scheduler']['initialized'].
			 *
			 * @param string|null $function_name The calling function.
			 *
			 * @return bool
			 */
			public static function is_initialized( $function_name = null ) {
				return (bool) ( $GLOBALS['scanfully_test_action_scheduler']['initialized'] ?? true );
			}
		}

		/**
		 * Stub of Action Scheduler's lock.
		 */
		class ActionScheduler_Lock {
			/**
			 * Set a lock.
			 *
			 * @param string $lock_type The lock type.
			 *
			 * @return bool
			 */
			public function set( $lock_type ) {
				$GLOBALS['scanfully_test_action_scheduler']['locks'][] = $lock_type;
				return true;
			}
		}

		/**
		 * Stub of Action Scheduler's async queue runner.
		 */
		class ActionScheduler_AsyncRequest_QueueRunner {
			/**
			 * Constructor.
			 *
			 * @param mixed $store The store.
			 */
			public function __construct( $store ) {}

			/**
			 * Dispatch a runner.
			 *
			 * @return void
			 */
			public function maybe_dispatch() {
				$GLOBALS['scanfully_test_action_scheduler']['dispatched'][] = true;
			}
		}
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
