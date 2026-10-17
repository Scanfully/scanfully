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
		if ( WooCheckout\Controller::is_woocommerce_supported() ) {
			WooCheckout\Controller::setup();
			WooCheckout\ProbeGateway::setup();
			// Loading BlocksIntegration requires WooCommerce Blocks' base class;
			// without it the class declaration itself is a fatal error.
			if ( class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
				WooCheckout\BlocksIntegration::setup();
			}
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
		Events\Controller::register( new Events\PluginUpdateFailed() ); // when a plugin update fails.
		Events\Controller::register( new Events\ThemeUpdateFailed() ); // when a theme update fails.
		Events\Controller::register( new Events\PluginInstalled() ); // when a plugin is installed.
		Events\Controller::register( new Events\PluginDeleted() ); // when a plugin is deleted.
		Events\Controller::register( new Events\ThemeInstalled() ); // when a theme is installed.
		Events\Controller::register( new Events\ThemeDeleted() ); // when a theme is deleted.
		Events\Controller::register( new Events\ThemeSwitched() ); // when another theme is activated.
		Events\Controller::register( new Events\PermalinksChanged() ); // when the permalink structure is changed.
		Events\Controller::register( new Events\PostSaved() ); // when a post status is changed.
		Events\Controller::register( new Events\CoreUpdate() ); // when the core is updated.
		Events\Controller::register( new Events\CoreUpdateFailed() ); // when an automatic core update fails.
		Events\Controller::register( new Events\UserCreated() ); // when a user is created.
		Events\Controller::register( new Events\UserDeleted() ); // when a user is deleted.
		Events\Controller::register( new Events\UserRoleChanged() ); // when a user's role is changed.
		Events\Controller::register( new Events\UserLogin() ); // when a user with elevated rights logs in.
		foreach ( Events\SiteOptionChanged::OPTIONS as $option ) {
			Events\Controller::register( new Events\SiteOptionChanged( $option ) ); // when a key setting is changed.
		}

		// custom events.
		Events\Controller::setup_custom_events();
	}
}
