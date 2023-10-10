<?php
/**
 * The page class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Options;

/**
 * The Page class is responsible for registering and displaying the admin page.
 */
class Page {

	/**
	 * The page slug.
	 *
	 * @var string
	 */
	public static $page = 'scanfully';

	/**
	 * Register the options page.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action(
			'admin_menu',
			function () {
				$page_hook = add_options_page(
					__( 'Scanfully', 'scanfully' ),
					__( 'Scanfully', 'scanfully' ),
					'manage_options',
					self::$page,
					[ Page::class, 'render' ]
				);

				// enqueue our assets only on our plugin page.
				add_action( 'load-' . $page_hook, [ Page::class, 'enqueue_assets' ] );
			}
		);
	}

	/**
	 * Enqueuing admin assets only on our plugin page.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		wp_enqueue_style(
			'scanfully-admin-css',
			plugins_url( '/assets/css/admin.css', SCANFULLY_PLUGIN_FILE ),
			array(),
			SCANFULLY_VERSION
		);
	}

	/**
	 * Renders the options page.
	 *
	 * @return void
	 */
	public static function render(): void {
		?>
		<div class="wrap">
			<h1>Scanfully Settings</h1>
			<div class="scanfully-settings">
				<div class="scanfully-content">
					<p>
						<?php
						esc_html_e(
							'Welcome to Scanfully, your dashboard for your WordPress sites’ Performance and Health.',
							'scanfully'
						);
						?>
					</p>
					<p>
						<?php
						printf(
						/* translators: %s is a link to the plugin website */
							esc_html__(
								'Our WordPress plugin acts as the "glue" between your WordPress website and your Scanfully dashboard. More information about how our WordPress plugin works can be found %s',
								'scanfully'
							),
							"<a href='https://scanfully.com/wp-plugin' target='_blank'>" . esc_html__(
								'here',
								'scanfully'
							) . '</a>'
						);
						?>
					</p>
					<div class="scanfully-content-api-settings">
						<form action="options.php" method="post">
							<?php
							settings_fields( 'scanfully_plugin_options' );
							do_settings_sections( self::$page );
							?>
							<input name="submit" class="button button-primary" type="submit" value="<?php esc_attr_e( 'Save Changes', 'scanfully' ); ?>"/>
						</form>
					</div>
				</div>
				<div class="scanfully-sidebar">
					<h2>Scanfully</h2>
					<p>
						<?php
						echo esc_html__(
							'Plugin version',
							'scanfully'
						) . ' ' . esc_html( SCANFULLY_VERSION );
						?>
					</p>
					<div class="scanfully-sidebar-items">
						<div>
							<h3><?php esc_html_e( 'Need help?', 'scanfully' ); ?></h3>
							<p>
								<?php
								printf(
								/* translators: %1$s is a link to the help center, %2$s is a link to the contact us page */
									esc_html__( 'Check out our %1$s or %2$s', 'scanfully' ),
									'<a href="https://scanfully.com/help" target="_blank">' . esc_html__(
										'help center',
										'scanfully'
									) . '</a>',
									'<a href="https://scanfully.com/contact" target="_blank">' . esc_html__(
										'contact us',
										'scanfully'
									) . '</a>'
								);
								?>
							</p>
						</div>
						<div>
							<h3><?php esc_html_e( 'Want to learn more?', 'scanfully' ); ?></h3>
							<p><?php esc_html_e( 'Check out our', 'scanfully' ); ?> <a href="https://scanfully.com/blog" target="_blank">
									<?php
									esc_html_e(
										'blog',
										'scanfully'
									);
									?>
								</a>
						</div>
						<div>
							<h3><?php esc_html_e( 'About Scanfully', 'scanfully' ); ?></h3>
							<div class="scanfully-about">
								<a href="https://www.scanfully.com" target="_blank"><img src="<?php echo esc_attr( plugins_url( '/assets/images/logo.png', SCANFULLY_PLUGIN_FILE ) ); ?>" alt="Scanfully"/></a>
								<p>
									<?php
									esc_html_e(
										'One dashboard for your WordPress sites’ Performance and Health. Your ScanFully
                                    Dashboard
                                    consolidates all your WordPress sites, sending you timely alerts for required
                                    changes.',
										'scanfully'
									);
									?>
								</p>
							</div>
						</div>
					</div>
				</div>
			</div>

		</div>
		<?php
	}
}