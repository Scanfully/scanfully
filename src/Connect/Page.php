<?php

namespace Scanfully\Connect;

use Scanfully\Options\Controller as OptionsController;
use Scanfully\Util;

/**
 * Connect page
 */
class Page {

	private static string $page = 'scanfully';

	public static function register(): void {
		add_action( 'admin_init', [ Page::class, 'catch_install_request' ] );
		self::register_page();
	}

	public static function catch_install_request(): void {
		if ( isset( $_GET['scanfully-connect-install'] ) ) {
			wp_redirect( self::get_page_url() );
			exit;
		}
	}

	public static function get_page_url(): string {
		return admin_url( 'options-general.php?page=' . self::$page );
	}

	public static function register_page(): void {
		add_action(
			'admin_menu',
			function () {
				$page_hook = add_options_page(
					__( 'Scanfully', 'scanfully' ),
					__( 'Scanfully', 'scanfully' ),
					'manage_options',
					self::$page,
					[ Page::class, 'render_page' ]
				);

				// enqueue our assets only on our plugin page.
				add_action( 'load-' . $page_hook, [ Page::class, 'enqueue_page_assets' ] );
			}
		);
	}

	public static function enqueue_page_assets(): void {
		wp_enqueue_style(
			'scanfully-admin-css',
			plugins_url( '/assets/css/admin.css', SCANFULLY_PLUGIN_FILE ),
			array(),
			SCANFULLY_VERSION
		);
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		// get options
		$options = OptionsController::get_options();
		?>
		<div class="scanfully-secure-setup-wrapper">
			<div class="scanfully-setup-logo">
				<img src="<?php echo esc_attr( plugins_url( '/assets/images/logo-text.png', SCANFULLY_PLUGIN_FILE ) ); ?>" alt="Scanfully"/>
			</div>
			<div class="scanfully-connect-notices">
				<?php do_action( 'scanfully_connect_notices' ); ?>
			</div>
			<div class="scanfully-setup-content">
				<p><?php esc_html_e( 'Welcome to Scanfully, your dashboard for your WordPress sites’ Performance and Health.', 'scanfully' ); ?></p>
				<p><?php esc_html_e( 'Our WordPress plugin acts as the "glue" between your WordPress website and your Scanfully dashboard. More information about how our WordPress plugin works can be found here', 'scanfully' ); ?></p>
				<hr/>
				<h2><?php esc_html_e( 'Scanfully Connect', 'scanfully' ); ?></h2>
				<p><?php esc_html_e( 'Manage the connection of your website to your Scanfully account.', 'scanfully' ); ?></p>
				<ul class="scanfully-connect-details">
					<li>
						<div class="scanfully-connect-details-label"><?php esc_html_e( 'Connection status', 'scanfully' ); ?></div>
						<div class="scanfully-connect-details-value">
							<?php if ( $options->is_connected ) : ?>
								<span class="scanfully-connect-blob scanfully-connect-blob-success"><?php esc_html_e( 'Connected', 'scanfully' ); ?></span>
							<?php else : ?>
								<span class="scanfully-connect-blob scanfully-connect-blob-error"><?php esc_html_e( 'Not connected', 'scanfully' ); ?></span>
							<?php endif; ?>

						</div>
					</li>
					<?php if ( $options->is_connected ) : ?>
						<?php
						$last_used = "-";
						if ( $options->last_used != "" ) {
							$last_used_dt = \DateTime::createFromFormat( Controller::DATE_FORMAT, $options->last_used, new \DateTimeZone( 'UTC' ) );
							try {
								$last_used_dt->setTimezone( Util\Date::get_timezone() );
							} catch ( \Exception $e ) {
							}
							$last_used = $last_used_dt->format( get_option( 'date_format' ) . ' @ ' . get_option( 'time_format' ) );
						}
						?>
						<li>
							<div class="scanfully-connect-details-label"><?php esc_html_e( 'Last used', 'scanfully' ); ?></div>
							<div class="scanfully-connect-details-value"><span class="scanfully-connect-blob scanfully-connect-blob-info"><?php esc_html_e( $last_used ); ?></span></div>
						</li>
						<?php
						if ( $options->date_connected != '' ) :
							$connected = "-";
							try {
								$connectedDt = \DateTime::createFromFormat( Controller::DATE_FORMAT, $options->date_connected, new \DateTimeZone( 'UTC' ) );
								try {
									$connectedDt->setTimezone( Util\Date::get_timezone() );
								} catch ( \Exception $e ) {
								}
								$connected = $connectedDt->format( get_option( 'date_format' ) . ' @ ' . get_option( 'time_format' ) );
							} catch ( \Exception $e ) {
								$connectedDt = null;
							}
							?>
							<li>
								<div class="scanfully-connect-details-label"><?php esc_html_e( 'Date connected', 'scanfully' ); ?></div>
								<div class="scanfully-connect-details-value"><span class="scanfully-connect-blob scanfully-connect-blob-info"><?php esc_html_e( $connected ); ?></span></div>
							</li>
						<?php endif; ?>
					<?php endif; ?>
				</ul>
				<div class="scanfully-connect-button-wrapper">
					<?php if ( $options->is_connected ) : ?>
						<p style="display: flex; gap: 1em;">
							<?php Buttons::dashboard(); ?>
							<?php Buttons::disconnect(); ?>
						</p>
					<?php else : ?>
						<p style="display: inline-block">
							<?php Buttons::connect(); ?>
						</p>
					<?php endif; ?>
				</div>
			</div>
			<div class="scanfully-setup-footer">
				<p>version 1.0.0</p>
				<p><a href="https://scanfully.com/docs/"><?php esc_html_e('help center', 'scanfully'); ?></a> - <a href="https://scanfully.com/contact/"><?php esc_html_e('contact us', 'scanfully'); ?></a></p>
			</div>
		</div>
		<?php
	}
}