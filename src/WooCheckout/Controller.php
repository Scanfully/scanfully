<?php
/**
 * WooCheckout controller.
 *
 * @package Scanfully
 */

namespace Scanfully\WooCheckout;

use Scanfully\API\WooCheckoutConfigRequest;

/**
 * Central WooCheckout controller. Provides:
 *
 *  - Probe-secret lifecycle (generate once, persist).
 *  - Probe request validation (HMAC of the X-Scanfully-Probe header).
 *  - WooCommerce config discovery: default product, default shipping
 *    method, login policy, cart/checkout page variant.
 *  - Config reporting to the Scanfully API.
 *
 * All checkout-flow filters are intentionally minimal: the API drives the
 * real customer flow via go-rod, we only validate the probe identity and
 * expose the probe gateway to that request.
 */
class Controller {

	public const OPTION_PROBE_SECRET    = 'scanfully_woocheckout_probe_secret';
	public const OPTION_LAST_CONFIG     = 'scanfully_woocheckout_last_config';
	public const OPTION_SHIPPING_METHOD = 'scanfully_woocheckout_shipping_method';
	public const PROBE_GATEWAY_ID       = 'scanfully_probe';
	public const PROBE_HEADER           = 'X-Scanfully-Probe';
	public const MIN_WC_VERSION         = '8.0';

	/**
	 * Disabled reasons reported when scanning cannot run.
	 */
	public const REASON_WC_INACTIVE         = 'wc_inactive';
	public const REASON_WC_TOO_OLD          = 'wc_version_too_old';
	public const REASON_NO_ELIGIBLE_PRODUCT = 'no_eligible_product';

	/**
	 * Reasons the store-address based billing default is unavailable.
	 */
	public const BILLING_REASON_NO_SELLABLE_LOCATION = 'no_sellable_default_location';
	public const BILLING_REASON_LOCATION_MISMATCH    = 'default_location_mismatch';

	/**
	 * Memoised result of is_probe_request() — request-scoped.
	 *
	 * @var ?bool
	 */
	private static ?bool $probe_request_cache = null;

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function setup(): void {
		// Validate the probe header very early so downstream controllers
		// (gateway, login bridge, stub PSP) can consult it on `init`.
		add_action( 'init', [ self::class, 'prime_probe_request_check' ], 1 );

		// Resync triggers — keep the API's stored config fresh without
		// waiting for the daily cron.
		add_action( 'update_option_woocommerce_enable_guest_checkout', [ self::class, 'on_config_change' ] );
		add_action( 'update_option_woocommerce_default_country', [ self::class, 'on_config_change' ] );
		add_action( 'woocommerce_shipping_zone_method_added', [ self::class, 'on_config_change' ] );
		add_action( 'woocommerce_shipping_zone_method_deleted', [ self::class, 'on_config_change' ] );
		add_action( 'woocommerce_shipping_zone_method_status_toggled', [ self::class, 'on_config_change' ] );
		add_action( 'save_post_page', [ self::class, 'on_page_save' ], 10, 1 );

		// Warn shop admins when the default customer location is not
		// sellable: probe scans then have no billing default.
		add_action( 'admin_notices', [ self::class, 'maybe_render_location_notice' ] );
	}

	/**
	 * Render a warning on the WooCommerce settings screen when the default
	 * customer location is not in the allowed selling countries.
	 *
	 * @return void
	 */
	public static function maybe_render_location_notice(): void {
		if ( ! function_exists( 'get_current_screen' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'woocommerce_page_wc-settings' !== $screen->id ) {
			return;
		}
		$store_address = self::probe_store_address();
		if ( self::BILLING_REASON_NO_SELLABLE_LOCATION !== $store_address['reason'] || ! self::is_woocommerce_supported() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Scanfully: the shop\'s default customer location is not one of the countries you sell to, so WooCommerce checkout tests have no billing address to use. Review "Selling location(s)" under WooCommerce > Settings > General, or configure custom billing details in the Scanfully dashboard.', 'scanfully' )
		);
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Whether WooCommerce meets the minimum required version.
	 *
	 * @return bool
	 */
	public static function is_woocommerce_supported(): bool {
		if ( ! self::is_woocommerce_active() ) {
			return false;
		}
		$version = defined( 'WC_VERSION' ) ? WC_VERSION : ( WC()->version ?? '' );
		if ( '' === $version ) {
			return false;
		}
		return version_compare( $version, self::MIN_WC_VERSION, '>=' );
	}

	/**
	 * Get the active WooCommerce version, or an empty string when missing.
	 *
	 * @return string
	 */
	public static function wc_version(): string {
		if ( defined( 'WC_VERSION' ) ) {
			return (string) WC_VERSION;
		}
		if ( self::is_woocommerce_active() && isset( WC()->version ) ) {
			return (string) WC()->version;
		}
		return '';
	}

	/**
	 * Return the probe secret, generating it once on first call.
	 *
	 * @return string Hex-encoded 32-byte secret.
	 */
	public static function get_or_create_probe_secret(): string {
		$secret = (string) get_option( self::OPTION_PROBE_SECRET, '' );
		if ( '' !== $secret ) {
			return $secret;
		}
		try {
			$secret = bin2hex( random_bytes( 32 ) );
		} catch ( \Exception $e ) {
			// Fallback. wp_generate_password is OK for non-cryptographic
			// contexts but we want strong entropy; if random_bytes fails the
			// environment is severely degraded and this is the best we can do.
			$secret = wp_generate_password( 64, false, false );
		}
		update_option( self::OPTION_PROBE_SECRET, $secret, false );
		return $secret;
	}

	/**
	 * Cache result of is_probe_request() at the `init` hook so subsequent
	 * filters and REST endpoints get a constant-time answer.
	 *
	 * @return void
	 */
	public static function prime_probe_request_check(): void {
		self::is_probe_request();
	}

	/**
	 * Whether the current request is a valid Scanfully probe.
	 *
	 * Reads the X-Scanfully-Probe header, parses `scan_id:hex(hmac)` and
	 * constant-time compares against HMAC-SHA256(secret, scan_id).
	 *
	 * @return bool
	 */
	public static function is_probe_request(): bool {
		if ( null !== self::$probe_request_cache ) {
			return self::$probe_request_cache;
		}

		$header = self::read_probe_header();
		if ( '' === $header ) {
			error_log( '[scanfully] is_probe_request: empty header' );
			self::$probe_request_cache = false;
			return false;
		}
		error_log( '[scanfully] is_probe_request: raw_header_len=' . strlen( $header ) . ' raw=' . $header );

		$parts = explode( ':', $header, 2 );
		if ( 2 !== count( $parts ) ) {
			error_log( '[scanfully] is_probe_request: malformed header=' . substr( $header, 0, 80 ) );
			self::$probe_request_cache = false;
			return false;
		}
		list( $scan_id, $signature ) = $parts;
		if ( '' === $scan_id || '' === $signature ) {
			error_log( '[scanfully] is_probe_request: empty scan_id or signature' );
			self::$probe_request_cache = false;
			return false;
		}

		$secret = (string) get_option( self::OPTION_PROBE_SECRET, '' );
		if ( '' === $secret ) {
			error_log( '[scanfully] is_probe_request: secret option missing' );
			self::$probe_request_cache = false;
			return false;
		}

		$expected = hash_hmac( 'sha256', $scan_id, $secret );
		$ok       = hash_equals( $expected, $signature );

		if ( ! $ok ) {
			error_log( '[scanfully] is_probe_request: hmac mismatch scan_id=' . $scan_id . ' secret_len=' . strlen( $secret ) . ' secret_sha256_8=' . substr( hash( 'sha256', $secret ), 0, 8 ) . ' got_sig=' . substr( $signature, 0, 12 ) . ' expected=' . substr( $expected, 0, 12 ) );
		}

		self::$probe_request_cache = $ok;
		return $ok;
	}

	/**
	 * Read the probe header from the current request, in all the casings
	 * PHP/WP might present.
	 *
	 * @return string
	 */
	private static function read_probe_header(): string {
		// REST requests: use the helper if available.
		if ( function_exists( 'rest_get_server' ) ) {
			$server = rest_get_server();
			if ( method_exists( $server, 'get_headers' ) && isset( $_SERVER ) ) {
				$headers = $server->get_headers( $_SERVER );
				if ( isset( $headers['X_SCANFULLY_PROBE'] ) ) {
					return (string) $headers['X_SCANFULLY_PROBE'];
				}
			}
		}
		if ( isset( $_SERVER['HTTP_X_SCANFULLY_PROBE'] ) ) {
			return (string) wp_unslash( $_SERVER['HTTP_X_SCANFULLY_PROBE'] );
		}
		return '';
	}

	/**
	 * Return the current probe scan id (extracted from the probe header).
	 * Empty string when the header is missing or invalid.
	 *
	 * @return string
	 */
	public static function current_scan_id(): string {
		if ( ! self::is_probe_request() ) {
			return '';
		}
		$header = self::read_probe_header();
		$parts  = explode( ':', $header, 2 );
		return isset( $parts[0] ) ? (string) $parts[0] : '';
	}

	/**
	 * Pick the default product URL the orchestrator should start the scan
	 * at: in stock, paid (`price > 0`), published; simple products first,
	 * variable products as fallback. Used when the user has not chosen a
	 * product in the Scanfully dashboard.
	 *
	 * @return string Empty string when no eligible product exists.
	 */
	public static function pick_product_url(): string {
		$product = self::pick_product();
		if ( null === $product ) {
			return '';
		}
		return (string) $product->get_permalink();
	}

	/**
	 * Pick the eligible product object: simple products first, falling back
	 * to variable products when no eligible simple product exists.
	 *
	 * @return \WC_Product|null
	 */
	private static function pick_product(): ?\WC_Product {
		if ( ! self::is_woocommerce_active() || ! function_exists( 'wc_get_products' ) ) {
			return null;
		}

		// Simple products first; variable products only when no eligible
		// simple product exists (their variation resolution adds moving
		// parts to the scan).
		foreach ( [ 'simple', 'variable' ] as $type ) {
			$product = self::pick_product_of_type( $type );
			if ( null !== $product ) {
				return $product;
			}
		}

		return null;
	}

	/**
	 * Pick the first eligible product of the given type. Ordered by ID
	 * ascending so the same product is picked deterministically across
	 * repeated scans on the same shop.
	 *
	 * @param string $type Product type ('simple' or 'variable').
	 * @return \WC_Product|null
	 */
	private static function pick_product_of_type( string $type ): ?\WC_Product {
		$query_args = [
			'status'       => 'publish',
			'type'         => $type,
			'limit'        => 50,
			'orderby'      => 'ID',
			'order'        => 'ASC',
			'return'       => 'objects',
			'stock_status' => 'instock',
		];

		$products = wc_get_products( $query_args );
		if ( ! is_array( $products ) ) {
			return null;
		}

		foreach ( $products as $product ) {
			if ( ! ( $product instanceof \WC_Product ) ) {
				continue;
			}
			if ( self::is_eligible_product( $product, $type ) ) {
				return $product;
			}
		}

		return null;
	}

	/**
	 * Whether the product is probe-eligible: in stock, paid (`price > 0`),
	 * has a permalink, purchasable (variable).
	 *
	 * @param \WC_Product $product Product to check.
	 * @param string      $type    Product type ('simple' or 'variable').
	 * @return bool
	 */
	private static function is_eligible_product( \WC_Product $product, string $type ): bool {
		// For variable products get_price() is the minimum variation
		// price, so this also guards against free-only variations.
		$price = (float) $product->get_price();
		if ( $price <= 0 ) {
			return false;
		}
		if ( '' === (string) $product->get_permalink() ) {
			return false;
		}
		if ( 'variable' === $type && ( ! $product->is_purchasable() || ! $product->is_in_stock() ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Search probe-eligible products for the dashboard product select.
	 * Empty term returns the first eligible products (simple before
	 * variable). Uses WooCommerce's own product search for term matching.
	 *
	 * @param string $term  Search term (may be empty).
	 * @param int    $limit Maximum number of results.
	 * @return array<int,array<string,mixed>> Rows of id/name/price/url/type.
	 */
	public static function search_products( string $term, int $limit = 20 ): array {
		if ( ! self::is_woocommerce_active() || ! function_exists( 'wc_get_products' ) ) {
			return [];
		}

		$results = [];

		if ( '' !== $term && class_exists( '\WC_Data_Store' ) ) {
			try {
				$data_store = \WC_Data_Store::load( 'product' );
			} catch ( \Exception $e ) {
				return [];
			}
			// Over-fetch: search results still need eligibility filtering.
			$ids = $data_store->search_products( $term, '', false, false, $limit * 5 );
			foreach ( $ids as $id ) {
				if ( count( $results ) >= $limit ) {
					break;
				}
				$id = (int) $id;
				if ( $id <= 0 ) {
					continue;
				}
				$product = wc_get_product( $id );
				if ( ! ( $product instanceof \WC_Product ) || 'publish' !== $product->get_status() ) {
					continue;
				}
				$type = $product->get_type();
				if ( ! in_array( $type, [ 'simple', 'variable' ], true ) ) {
					continue;
				}
				if ( ! $product->is_in_stock() || ! self::is_eligible_product( $product, $type ) ) {
					continue;
				}
				$results[] = self::product_row( $product, $type );
			}
			return $results;
		}

		foreach ( [ 'simple', 'variable' ] as $type ) {
			if ( count( $results ) >= $limit ) {
				break;
			}
			$products = wc_get_products(
				[
					'status'       => 'publish',
					'type'         => $type,
					'limit'        => 50,
					'orderby'      => 'ID',
					'order'        => 'ASC',
					'return'       => 'objects',
					'stock_status' => 'instock',
				]
			);
			if ( ! is_array( $products ) ) {
				continue;
			}
			foreach ( $products as $product ) {
				if ( count( $results ) >= $limit ) {
					break;
				}
				if ( ! ( $product instanceof \WC_Product ) || ! self::is_eligible_product( $product, $type ) ) {
					continue;
				}
				$results[] = self::product_row( $product, $type );
			}
		}

		return $results;
	}

	/**
	 * Map a product to the row shape the product search endpoint returns.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $type    Product type.
	 * @return array<string,mixed>
	 */
	private static function product_row( \WC_Product $product, string $type ): array {
		$price = '';
		if ( function_exists( 'wc_price' ) ) {
			$price = html_entity_decode( wp_strip_all_tags( wc_price( (float) $product->get_price() ) ), ENT_QUOTES );
		}
		return [
			'id'    => (int) $product->get_id(),
			'name'  => (string) $product->get_name(),
			'price' => $price,
			'url'   => (string) $product->get_permalink(),
			'type'  => $type,
		];
	}

	/**
	 * Pick the default shipping method (zone + instance) for the shop's
	 * base country. Returns ['zone_id' => int, 'instance_id' => string,
	 * 'title' => string] or null when none exists.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function pick_shipping_method(): ?array {
		$override = (string) get_option( self::OPTION_SHIPPING_METHOD, '' );
		$override = (string) apply_filters( 'scanfully_woocheckout_shipping_method', $override );
		if ( '' !== $override && false !== strpos( $override, ':' ) ) {
			list( $zone_id_raw, $instance_id_raw ) = explode( ':', $override, 2 );
			$instance_id                          = (string) $instance_id_raw;
			$zone_id                              = (int) $zone_id_raw;
			$title                                = self::shipping_method_title( $zone_id, $instance_id );
			if ( '' !== $title ) {
				return [
					'zone_id'     => $zone_id,
					'instance_id' => $instance_id,
					'title'       => $title,
				];
			}
		}

		if ( ! self::is_woocommerce_active() || ! class_exists( '\WC_Shipping_Zones' ) ) {
			return null;
		}

		$base_country = self::base_country();

		// Walk zones in two passes: first try zones whose locations include
		// our base country; then fall back to the "Rest of the World" zone
		// (id 0). Inside a zone, take the first enabled instance.
		$zones    = \WC_Shipping_Zones::get_zones();
		$pass_one = [];
		foreach ( $zones as $zone_data ) {
			$pass_one[] = (int) $zone_data['zone_id'];
		}
		// Append Rest of the World last.
		$pass_one[] = 0;

		$best = null;
		foreach ( $pass_one as $zone_id ) {
			$zone = \WC_Shipping_Zones::get_zone( $zone_id );
			if ( ! $zone ) {
				continue;
			}
			if ( $zone_id !== 0 && ! self::zone_covers_country( $zone, $base_country ) ) {
				continue;
			}
			$instance = self::first_enabled_method( $zone );
			if ( null === $instance ) {
				continue;
			}
			$best = [
				'zone_id'     => $zone_id,
				'instance_id' => (string) $instance['instance_id'],
				'title'       => (string) $instance['title'],
			];
			break;
		}

		return $best;
	}

	/**
	 * Find the first enabled shipping method instance on a zone, returning
	 * ['instance_id' => string, 'title' => string].
	 *
	 * @param \WC_Shipping_Zone $zone The zone.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function first_enabled_method( \WC_Shipping_Zone $zone ): ?array {
		$methods = $zone->get_shipping_methods( true );
		if ( ! is_array( $methods ) || empty( $methods ) ) {
			return null;
		}
		// Order by instance_id ascending for determinism across scans.
		ksort( $methods, SORT_NUMERIC );
		foreach ( $methods as $instance_id => $method ) {
			if ( ! is_object( $method ) ) {
				continue;
			}
			if ( method_exists( $method, 'is_enabled' ) && ! $method->is_enabled() ) {
				continue;
			}
			$id    = (string) ( $method->id ?? '' );
			$value = '' !== $id ? sprintf( '%s:%s', $id, (string) $instance_id ) : (string) $instance_id;
			$title = (string) ( $method->get_title() ?? $method->title ?? $id );
			return [
				'instance_id' => $value,
				'title'       => $title,
			];
		}
		return null;
	}

	/**
	 * Whether a zone's location list covers the given two-letter country.
	 *
	 * @param \WC_Shipping_Zone $zone Zone instance.
	 * @param string            $country Two-letter country code.
	 *
	 * @return bool
	 */
	private static function zone_covers_country( \WC_Shipping_Zone $zone, string $country ): bool {
		if ( '' === $country ) {
			return false;
		}
		$locations = $zone->get_zone_locations();
		if ( ! is_array( $locations ) ) {
			return false;
		}
		$country = strtoupper( $country );
		foreach ( $locations as $loc ) {
			$type = isset( $loc->type ) ? (string) $loc->type : '';
			$code = isset( $loc->code ) ? strtoupper( (string) $loc->code ) : '';
			if ( 'country' === $type && $code === $country ) {
				return true;
			}
			if ( 'state' === $type && 0 === strpos( $code, $country . ':' ) ) {
				return true;
			}
			if ( 'postcode' === $type && 0 === strpos( $code, $country . ':' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve the displayed title for a given (zone_id, instance_value).
	 *
	 * @param int    $zone_id  Zone id.
	 * @param string $instance Format "method_id:instance_id" or "instance_id".
	 *
	 * @return string
	 */
	private static function shipping_method_title( int $zone_id, string $instance ): string {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return '';
		}
		$zone = \WC_Shipping_Zones::get_zone( $zone_id );
		if ( ! $zone ) {
			return '';
		}
		$instance_id = $instance;
		if ( false !== strpos( $instance, ':' ) ) {
			$parts       = explode( ':', $instance, 2 );
			$instance_id = $parts[1];
		}
		foreach ( $zone->get_shipping_methods( true ) as $key => $method ) {
			if ( (string) $key === (string) $instance_id ) {
				return (string) ( $method->get_title() ?? '' );
			}
		}
		return '';
	}

	/**
	 * Whether the shop disables guest checkout (i.e. login required).
	 *
	 * @return bool
	 */
	public static function is_login_required(): bool {
		return 'no' === (string) get_option( 'woocommerce_enable_guest_checkout', 'yes' );
	}

	/**
	 * Detect cart/checkout page variant: 'shortcode', 'block', or 'unknown'.
	 *
	 * @param string $page WC page identifier ('cart' or 'checkout').
	 *
	 * @return string
	 */
	public static function detect_page_variant( string $page ): string {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return 'unknown';
		}
		$page_id = (int) wc_get_page_id( $page );
		if ( $page_id <= 0 ) {
			return 'unknown';
		}
		$post = get_post( $page_id );
		if ( ! $post ) {
			return 'unknown';
		}
		$content     = (string) $post->post_content;
		$block_token = 'cart' === $page ? 'wp:woocommerce/cart' : 'wp:woocommerce/checkout';
		$short_token = 'cart' === $page ? 'woocommerce_cart' : 'woocommerce_checkout';
		if ( false !== strpos( $content, $block_token ) ) {
			return 'block';
		}
		if ( false !== strpos( $content, '[' . $short_token ) ) {
			return 'shortcode';
		}
		return 'unknown';
	}

	/**
	 * Shop's base country (two-letter, uppercase).
	 *
	 * @return string
	 */
	public static function base_country(): string {
		$raw = (string) get_option( 'woocommerce_default_country', 'NL' );
		// Stored as "NL" or "NL:NH"; we only want the country.
		$parts = explode( ':', $raw, 2 );
		return strtoupper( (string) $parts[0] );
	}

	/**
	 * The shop's own store address for use as probe billing default, or the
	 * reason it is unavailable.
	 *
	 * Uses wc_get_customer_default_location() (not the raw base country: the
	 * base is not validated against the selling locations) and re-validates
	 * the result against the allowed countries, because the
	 * `woocommerce_customer_default_location_array` filter runs after WC's
	 * own validity check and can reintroduce a non-sellable country. Street
	 * parts are only attached when the default location matches the base
	 * country (they belong to the base address); geolocated or filtered
	 * mismatches yield no address rather than a wrong-country one.
	 *
	 * @return array{address: ?array<string,string>, reason: string}
	 */
	public static function probe_store_address(): array {
		if ( ! self::is_woocommerce_active() || ! function_exists( 'wc_get_customer_default_location' ) ) {
			return [
				'address' => null,
				'reason'  => self::BILLING_REASON_NO_SELLABLE_LOCATION,
			];
		}

		$location = wc_get_customer_default_location();
		$country  = isset( $location['country'] ) ? strtoupper( (string) $location['country'] ) : '';
		$state    = isset( $location['state'] ) ? (string) $location['state'] : '';

		if ( '' === $country ) {
			return [
				'address' => null,
				'reason'  => self::BILLING_REASON_NO_SELLABLE_LOCATION,
			];
		}

		$allowed = WC()->countries->get_allowed_countries();
		if ( ! isset( $allowed[ $country ] ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning(
					sprintf( 'Scanfully: default customer location %s is not in the allowed selling countries; probe billing default unavailable. Check WooCommerce > Settings > General > Selling location(s).', $country ),
					[ 'source' => 'scanfully' ]
				);
			}
			return [
				'address' => null,
				'reason'  => self::BILLING_REASON_NO_SELLABLE_LOCATION,
			];
		}

		if ( $country !== self::base_country() ) {
			return [
				'address' => null,
				'reason'  => self::BILLING_REASON_LOCATION_MISMATCH,
			];
		}

		$countries = WC()->countries;
		return [
			'address' => [
				'address_1'    => (string) $countries->get_base_address(),
				'address_2'    => (string) $countries->get_base_address_2(),
				'city'         => (string) $countries->get_base_city(),
				'postcode'     => (string) $countries->get_base_postcode(),
				'country'      => $country,
				'state'        => $state,
				'calling_code' => (string) $countries->get_country_calling_code( $country ),
			],
			'reason'  => '',
		];
	}

	/**
	 * Build the full config payload sent to the Scanfully API.
	 *
	 * @return array<string,mixed>
	 */
	public static function build_payload(): array {
		$wc_active    = self::is_woocommerce_active();
		$wc_supported = self::is_woocommerce_supported();

		$payload = [
			'enabled'                => false,
			'disabled_reason'        => '',
			'product_url'            => '',
			'cart_url'               => '',
			'checkout_url'           => '',
			'base_country'           => self::base_country(),
			'cart_type'              => 'unknown',
			'checkout_type'          => 'unknown',
			'shipping_zone_id'       => null,
			'shipping_method_id'     => '',
			'shipping_method_title'  => '',
			'login_required'         => false,
			'wc_version'             => '',
			'gateway_id'             => self::PROBE_GATEWAY_ID,
			'store_address'          => null,
			'billing_default_reason' => '',
			'flow_hints'             => null,
		];

		if ( ! $wc_active ) {
			$payload['disabled_reason'] = self::REASON_WC_INACTIVE;
			return $payload;
		}

		$payload['wc_version']     = self::wc_version();
		$payload['login_required'] = self::is_login_required();

		if ( ! $wc_supported ) {
			$payload['disabled_reason'] = self::REASON_WC_TOO_OLD;
			return $payload;
		}

		$store_address                     = self::probe_store_address();
		$payload['store_address']          = $store_address['address'];
		$payload['billing_default_reason'] = $store_address['reason'];

		if ( function_exists( 'wc_get_cart_url' ) ) {
			$payload['cart_url'] = (string) wc_get_cart_url();
		}
		if ( function_exists( 'wc_get_checkout_url' ) ) {
			$payload['checkout_url'] = (string) wc_get_checkout_url();
		}
		$payload['cart_type']     = self::detect_page_variant( 'cart' );
		$payload['checkout_type'] = self::detect_page_variant( 'checkout' );

		$product = self::pick_product();
		if ( null !== $product ) {
			$payload['product_url'] = (string) $product->get_permalink();
		}

		$payload['flow_hints'] = self::flow_hints( $product );

		// Shipping is best-effort: if a method can be picked we report it so
		// the orchestrator selects it explicitly; otherwise we leave it
		// empty and let WooCommerce decide. WC happily allows checkout on
		// shops with no shipping methods (the cart has no shipping line),
		// so missing shipping is not a hard disable.
		$shipping = self::pick_shipping_method();
		if ( null !== $shipping ) {
			$payload['shipping_zone_id']      = (int) $shipping['zone_id'];
			$payload['shipping_method_id']    = (string) $shipping['instance_id'];
			$payload['shipping_method_title'] = (string) $shipping['title'];
		}

		// Ensure probe secret exists before we report (the API stores it on
		// first push and reuses it on subsequent ones).
		self::get_or_create_probe_secret();

		// A missing product is reported but the payload stays complete: the
		// API decides per configured flow whether one is needed at all, and
		// a shop that adds the product on its checkout never opens a product
		// page.
		if ( null === $product ) {
			$payload['disabled_reason'] = self::REASON_NO_ELIGIBLE_PRODUCT;
			return $payload;
		}

		$payload['enabled'] = true;

		return $payload;
	}

	/**
	 * Signals about the shop's purchase flow, used to pre-fill the flow
	 * configuration wizard in the dashboard. All cheap option and page
	 * reads; the shop is never probed over HTTP from here.
	 *
	 * @param \WC_Product|null $product The picked probe product, if any.
	 * @return array<string,mixed>
	 */
	private static function flow_hints( ?\WC_Product $product ): array {
		return [
			'has_published_product_page' => null !== $product,
			'has_published_cart_page'    => self::has_published_wc_page( 'cart' ),
			'cart_redirect_after_add'    => 'yes' === (string) get_option( 'woocommerce_cart_redirect_after_add', 'no' ),
			'checkout_is_front_page'     => self::is_checkout_front_page(),
			'product_count'              => self::published_product_count(),
		];
	}

	/**
	 * Whether the given WooCommerce page exists and is published.
	 *
	 * @param string $page WC page identifier ('cart' or 'checkout').
	 * @return bool
	 */
	private static function has_published_wc_page( string $page ): bool {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return false;
		}
		$page_id = (int) wc_get_page_id( $page );
		if ( $page_id <= 0 ) {
			return false;
		}
		$post = get_post( $page_id );
		return $post instanceof \WP_Post && 'publish' === $post->post_status;
	}

	/**
	 * Whether the checkout page is also the site's front page, as one-page
	 * shops configure it.
	 *
	 * @return bool
	 */
	private static function is_checkout_front_page(): bool {
		if ( ! function_exists( 'wc_get_page_id' ) || 'page' !== (string) get_option( 'show_on_front' ) ) {
			return false;
		}
		$checkout_id = (int) wc_get_page_id( 'checkout' );
		return $checkout_id > 0 && (int) get_option( 'page_on_front' ) === $checkout_id;
	}

	/**
	 * Number of published products, used to recognise single-product shops.
	 *
	 * @return int
	 */
	private static function published_product_count(): int {
		$counts = wp_count_posts( 'product' );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * Report current config to the Scanfully API.
	 *
	 * The API is the source of truth for the probe secret: it generates one
	 * on first report and echoes it back on every push. Store it so
	 * is_probe_request() validates against the same HMAC key the API signs
	 * with.
	 *
	 * @return void
	 */
	public static function report(): void {
		$payload = self::build_payload();
		update_option( self::OPTION_LAST_CONFIG, $payload, false );
		$response = ( new WooCheckoutConfigRequest() )->send( $payload );

		if ( is_array( $response ) && 200 === (int) ( $response['status'] ?? 0 ) && is_array( $response['body'] ?? null ) ) {
			$secret = (string) ( $response['body']['probe_secret'] ?? '' );
			if ( '' !== $secret && get_option( self::OPTION_PROBE_SECRET, '' ) !== $secret ) {
				update_option( self::OPTION_PROBE_SECRET, $secret, false );
			}
		}
	}

	/**
	 * Hook callback: a WC option/zone changed — resync.
	 *
	 * @return void
	 */
	public static function on_config_change(): void {
		self::report();
	}

	/**
	 * Hook callback for `save_post_page`: only trigger a resync when the
	 * saved page is WC's cart or checkout page.
	 *
	 * @param int $post_id Post id of the saved page.
	 *
	 * @return void
	 */
	public static function on_page_save( int $post_id ): void {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return;
		}
		$cart_id     = (int) wc_get_page_id( 'cart' );
		$checkout_id = (int) wc_get_page_id( 'checkout' );
		if ( $post_id !== $cart_id && $post_id !== $checkout_id ) {
			return;
		}
		self::report();
	}
}
