<?php

namespace Scanfully\Connect;

use Scanfully\Options\Controller as OptionsController;

class Page {

	private static $page = 'scanfully';

	public static function register(): void {
		add_action( 'admin_init', [ SecureSetup::class, 'catch_get_request' ] );
		self::register_page();
	}

	public static function catch_get_request(): void {
		if ( isset( $_GET['scanfully-secure-setup-redirect'] ) ) {
			//https://scanfully-plugin.test/wp-admin/plugin-install.php?s=scanfully&tab=search&type=term&scanfully-secure-setup-redirect=12345
			$s = add_query_arg( [ 'page' => self::$page, 'scanfully-secure-setup' => $_GET['scanfully-secure-setup-redirect'] ], admin_url( 'options-general.php' ) );
			wp_redirect( $s );
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
							$last_used_dt->setTimezone( new \DateTimeZone( get_option( 'timezone_string' ) ) );
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
								$connectedDt->setTimezone( new \DateTimeZone( get_option( 'timezone_string' ) ) );
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
						<p style="display: inline-block">
							<?php
							$button = new DisconnectButton();
							$button->render();
							?>
						</p>
					<?php else : ?>
						<p style="display: inline-block">
							<?php
							$button = new AuthorizeButton();
							$button->render();
							?>
						</p>
					<?php endif; ?>
				</div>
			</div>
			<div class="scanfully-setup-footer">
				<p>version 1.0.0</p>
				<p><a href="https://scanfully.com/docs/">help center</a> - <a href="https://scanfully.com/contact/">contact us</a></p>
			</div>
		</div>
		<?php
	}
}