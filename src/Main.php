<?php
/**
 * The main class file.
 *
 * @package Scanfully
 */

namespace Scanfully;

/**
 * The main class, this is where it all starts.
 */
class Main {

	/**
	 * The singleton instance.
	 *
	 * @var ?Main
	 */
	private static ?Main $instance = null;

	/**
	 * Singleton getter
	 *
	 * @return Main|null
	 */
	public static function get(): ?Main {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	const API_URL = 'https://api.scanfully.com/v1';
	const DASHBOARD_URL = 'https://app.scanfully.com';
	const CONNECT_URL = 'https://app.scanfully.com/connect';

	/**
	 * Get the Scanfully API URL.
	 *
	 * @return string
	 */
	public static function get_api_url(): string {
		return apply_filters( 'scanfully_api_url', self::API_URL );
	}

	/**
	 * Whether a plugin basename (as passed by WordPress plugin hooks) is
	 * Scanfully itself.
	 *
	 * @param string $plugin Plugin basename, e.g. `scanfully/scanfully.php`.
	 *
	 * @return bool
	 */
	public static function is_own_plugin( string $plugin ): bool {
		return defined( 'SCANFULLY_PLUGIN_FILE' ) && plugin_basename( SCANFULLY_PLUGIN_FILE ) === $plugin;
	}

	/**
	 * Whether requests to the Scanfully API verify the TLS certificate.
	 *
	 * Always on by default. Local development against an API with a
	 * self-signed certificate can turn it off with the `scanfully_sslverify`
	 * filter; never do that in production.
	 *
	 * @return bool
	 */
	public static function get_sslverify(): bool {
		return (bool) apply_filters( 'scanfully_sslverify', true );
	}

	/**
	 * Get the Scanfully dashboard URL.
	 *
	 * @return string
	 */
	public static function get_dashboard_url(): string {
		return apply_filters( 'scanfully_dashboard_url', self::DASHBOARD_URL );
	}

	/**
	 * Get the Scanfully connect page URL.
	 *
	 * @return string
	 */
	public static function get_connect_url(): string {
		return apply_filters( 'scanfully_connect_url', self::CONNECT_URL );
	}

	/**
	 * Set up the plugin.
	 *
	 * @return void
	 */
	public function setup(): void {
		/** Record the memory limit before anything raises it. */
		Health\Controller::record_boot_memory_limit();

		/** Stop autoloading tokens stored by earlier versions (runs once). */
		Options\Controller::maybe_stop_autoloading_tokens();

		/** Register all events */
		$this->register_events();

		/** Register cron */
		Cron\Controller::setup();

		/** Register connect */
		Connect\Controller::setup();
		Connect\Page::register();
		Connect\AdminNotice::setup();

		/** Register Page Edit */
		PageEdit\Controller::setup();

		/** Register WooCheckout (probe scanning) when WooCommerce is present. */
		if ( class_exists( 'WooCommerce' ) ) {
			WooCheckout\Controller::setup();
			WooCheckout\ProbeGateway::setup();
			WooCheckout\BlocksIntegration::setup();
			WooCheckout\StubPSP::setup();
			WooCheckout\ProbePing::setup();
			WooCheckout\ProductSearch::setup();
			WooCheckout\LoginBridge::setup();
			WooCheckout\AdminFilter::setup();
		}

		/** Register on-demand sync endpoint */
		Sync\Controller::setup();
	}

	/**
	 * Register all events.
	 *
	 * @return void
	 */
	private function register_events(): void {
		Events\Controller::register_send_callback();
		Events\Controller::register( new Events\ActivatedPlugin() ); // when a plugin is activated.
		Events\Controller::register( new Events\DeactivatedPlugin() ); // when a plugin is deactivated.
		Events\Controller::register( new Events\PluginUpdate() ); // when a plugin is updated.
		Events\Controller::register( new Events\ThemeUpdate() ); // when a plugin is updated.
		Events\Controller::register( new Events\RewriteRules() ); // when new rewrite rules are saved.
		Events\Controller::register( new Events\PostSaved() ); // when a post status is changed.
		Events\Controller::register( new Events\CoreUpdate() ); // when the core is updated.

		// custom events.
		Events\Controller::setup_custom_events();
	}
}
