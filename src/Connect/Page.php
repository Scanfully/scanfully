<?php

namespace Scanfully\Connect;

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
	 * @return void
	 * @todo Replace this with html template files.
	 *
	 */
	public static function render_page(): void {
		?>
		<div class="scanfully-secure-setup-wrapper">
			<div class="scanfully-setup-logo">
				<img src="<?php echo esc_attr( plugins_url( '/assets/images/logo-text.png', SCANFULLY_PLUGIN_FILE ) ); ?>" alt="Scanfully"/>
			</div>
			<div class="scanfully-setup-content">
				<p>Welcome to Scanfully, your dashboard for your WordPress sites’ Performance and Health.</p>
				<p>Our WordPress plugin acts as the "glue" between your WordPress website and your Scanfully dashboard. More information about how our WordPress plugin works can be found here</p>
				<hr/>
				<h2>Connection</h2>
				<?php if ( Controller::is_connected() ) : ?>
					<p><span class="scanfully-connected">Connected</span></p>
				<?php else: ?>
					<p><span class="scanfully-not-connected">Your website is currently not connected to your Scanfully account.</span></p>
					<p style="display: inline-block">
						<?php
						$button = new Button();
						$button->render();
						?>
					</p>
				<?php endif; ?>
			</div>
			<div class="scanfully-setup-footer">
				<p>version 1.0.0</p>
				<p><a href="https://scanfully.com/docs/">help center</a> - <a href="https://scanfully.com/contact/">contact us</a></p>
			</div>
		</div>
		<?php
	}

	//https://scanfully-plugin.test/wp-admin/options-general.php?page=scanfully
}