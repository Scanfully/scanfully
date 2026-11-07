<?php
/**
 * The PHP error monitoring controller class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Errors;

use Scanfully\Options\Controller as OptionsController;

/**
 * Class Controller
 *
 * The WordPress side of PHP error monitoring: the send job, a send right
 * after an update, the must-use plugin's upkeep, the setting to turn it off
 * on the Scanfully settings page, and what the site data says about it.
 *
 * @package Scanfully\Errors
 */
final class Controller {

	/**
	 * The admin-post action of the on/off switch.
	 */
	public const ADMIN_POST_TOGGLE = 'scanfully_php_errors_toggle';

	/**
	 * The nonce action of the on/off switch.
	 */
	private const NONCE_TOGGLE = 'scanfully_php_errors_toggle';

	/**
	 * Add the hooks.
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_action( 'upgrader_process_complete', [ Monitor::class, 'note_update' ], 10, 0 );
		add_action( 'upgrader_process_complete', [ self::class, 'send_after_update' ], 10, 0 );
		add_action( 'admin_init', [ MuPlugin::class, 'maybe_install' ] );
		add_action( 'admin_post_' . self::ADMIN_POST_TOGGLE, [ self::class, 'handle_toggle' ] );
		add_action( 'scanfully_connect_page_content_end', [ self::class, 'render_admin_panel' ] );
		add_action( 'scanfully_options_cleared', [ self::class, 'disconnected' ] );
	}

	/**
	 * `upgrader_process_complete`: send what is waiting right away, so the
	 * errors from before the update are apart from the ones after it.
	 *
	 * @return void
	 */
	public static function send_after_update(): void {
		if ( Monitor::should_run() ) {
			Reporter::schedule( Reporter::TRIGGER_UPDATE );
		}
	}

	/**
	 * The site disconnected: forget the errors and, on a single site, remove
	 * the must-use plugin (on a network other sites may use it; it does
	 * nothing for a site that isn't connected).
	 *
	 * @return void
	 */
	public static function disconnected(): void {
		Store::delete_all();
		if ( ! is_multisite() ) {
			MuPlugin::remove();
		}
	}

	/**
	 * Whether capture is switched on in the settings (the default).
	 *
	 * @return bool
	 */
	public static function is_switched_on(): bool {
		return 'no' !== get_option( Monitor::ENABLED_OPTION, 'yes' );
	}

	/**
	 * What the site data says about PHP error monitoring: whether it runs,
	 * and whether it catches errors from before plugins load (`full`, the
	 * must-use plugin) or only from the Scanfully plugin on (`partial`).
	 *
	 * @return array{enabled: bool, coverage: string, mu_plugin: bool}
	 */
	public static function status(): array {
		$enabled = Monitor::should_run();

		return [
			'enabled'   => $enabled,
			'coverage'  => ! $enabled ? 'off' : ( 'mu' === Monitor::origin() ? 'full' : 'partial' ),
			'mu_plugin' => MuPlugin::is_installed(),
		];
	}

	/**
	 * The admin-post handler of the on/off switch.
	 *
	 * @return void
	 */
	public static function handle_toggle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'scanfully' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_TOGGLE );

		$enable = isset( $_POST['scanfully_php_errors'] ) && 'on' === sanitize_key( wp_unslash( $_POST['scanfully_php_errors'] ) );
		update_option( Monitor::ENABLED_OPTION, $enable ? 'yes' : 'no', true );
		if ( $enable ) {
			MuPlugin::install();
		} else {
			Store::delete_all();
			if ( ! is_multisite() ) {
				MuPlugin::remove();
			}
		}

		wp_safe_redirect( add_query_arg( [ 'page' => 'scanfully' ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * The PHP error monitoring section of the settings page.
	 *
	 * @return void
	 */
	public static function render_admin_panel(): void {
		if ( 'yes' !== OptionsController::get_option( 'is_connected' ) ) {
			return;
		}
		$switched_on = self::is_switched_on();
		$runs        = Monitor::should_run();
		?>
		<hr />
		<h2><?php esc_html_e( 'PHP error monitoring', 'scanfully' ); ?></h2>
		<p><?php esc_html_e( 'Sends PHP fatal errors and warnings to Scanfully, so you see when a plugin, theme or update breaks part of the site.', 'scanfully' ); ?></p>
		<?php if ( $switched_on && ! $runs ) : ?>
			<p><em><?php esc_html_e( 'PHP error monitoring is turned off by code on this site (the scanfully_php_errors_enabled filter).', 'scanfully' ); ?></em></p>
		<?php else : ?>
			<?php if ( $runs ) : ?>
				<?php if ( 'mu' === Monitor::origin() ) : ?>
					<p><?php esc_html_e( 'PHP error monitoring is on, from before other plugins load.', 'scanfully' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'PHP error monitoring is on. The must-use plugins folder is not writable, so errors raised while plugins load before Scanfully are not caught.', 'scanfully' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'PHP error monitoring is off.', 'scanfully' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ADMIN_POST_TOGGLE ); ?>" />
				<input type="hidden" name="scanfully_php_errors" value="<?php echo esc_attr( $switched_on ? 'off' : 'on' ); ?>" />
				<?php wp_nonce_field( self::NONCE_TOGGLE ); ?>
				<div class="scanfully-connect-button-wrapper">
					<?php if ( $switched_on ) : ?>
						<button type="submit" class="button"><?php esc_html_e( 'Turn off PHP error monitoring', 'scanfully' ); ?></button>
					<?php else : ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Turn on PHP error monitoring', 'scanfully' ); ?></button>
					<?php endif; ?>
				</div>
			</form>
		<?php endif; ?>
		<?php
	}
}
