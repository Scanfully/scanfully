<?php

namespace Scanfully\Options;

class Page {

	public static $page = 'scanfully';

	public static function register(): void {
		add_action(
			'admin_menu',
			function () {
				$page = add_options_page(
					__( 'Scanfully', 'scanfully' ),
					__( 'Scanfully', 'scanfully' ),
					'manage_options',
					self::$page,
					[ Page::class, 'render' ]
				);

				add_action(
					'admin_print_styles-' . $page,
					function () {
						// wp_enqueue_style("scanfully", plugin_dir_url(__FILE__) . "assets/css/scanfully.css");
						?>
				<style type="text/css">
					.scanfully-settings {
						display: flex;
					}

					.scanfully-settings .scanfully-content {
						flex: 0 0 65%;
						padding: 0 2em 0 0;
					}

					.scanfully-settings .scanfully-content .scanfully-content-api-settings {
						padding: 1.5em 0;
					}

					.scanfully-settings .scanfully-content .scanfully-content-api-settings input[type="text"] {
						width: 100%;
					}

					.scanfully-settings .scanfully-content .scanfully-content-api-settings .button {
						margin-top: 1em;
						margin-right: .7em;
						float: right;
					}

					.scanfully-settings .scanfully-sidebar {
						padding: 0 2em;
						border-left: 1px solid #ccc;
					}

					.scanfully-sidebar-items {
						display: flex;
						flex-direction: column;
						padding: .8em 0;
						gap: .8em;
					}

					.scanfully-about {
						display: flex;
					}

					.scanfully-about img {
						width: 90px;
						margin-top: 1em;
						margin-right: 1em;
					}
				</style>
						<?php
					}
				);
			}
		);
	}

	public static function render(): void {
		?>
		<div class="wrap">
			<h1>Scanfully Settings</h1>
			<div class="scanfully-settings">
				<div class="scanfully-content">
					<p>
					<?php
					_e(
						'Welcome to Scanfully, your dashboard for your WordPress sites’ Performance and Health.',
						'scanfully'
					);
					?>
						</p>
					<p>
					<?php
					printf(
						/* translators: %s is a link to the plugin website */
						__(
							'Our WordPress plugin acts as the "glue" between your WordPress website and your Scanfully dashboard. More information about how our WordPress plugin works can be found %s',
							'scanfully'
						),
						"<a href='https://scanfully.com/wp-plugin' target='_blank'>" . __(
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
							<input name="submit" class="button button-primary" type="submit"
									value="<?php esc_attr_e( __( 'Save Changes', 'scanfully' ) ); ?>"/>
						</form>
					</div>
				</div>
				<div class="scanfully-sidebar">
					<h2>Scanfully</h2>
					<p><?php echo __( 'Plugin version', 'scanfully' ) . ' ' . esc_html( SCANFULLY_VERSION ); ?></p>
					<div class="scanfully-sidebar-items">
						<div>
							<h3><?php _e( 'Need help?', 'scanfully' ); ?></h3>
							<p>
							<?php
							printf(
								/* translators: %1$s is a link to the help center, %2$s is a link to the contact us page */
								__( 'Check out our %1$s or %2$s', 'scanfully' ),
								'<a href="https://scanfully.com/help" target="_blank">' . __(
									'help center',
									'scanfully'
								) . '</a>',
								'<a href="https://scanfully.com/contact" target="_blank">' . __(
									'contact us',
									'scanfully'
								) . '</a>'
							);
							?>
								</p>
						</div>
						<div>
							<h3><?php _e( 'Want to learn more?', 'scanfully' ); ?></h3>
							<p><?php _e( 'Check out our', 'scanfully' ); ?> <a href="https://scanfully.com/blog"
																				target="_blank">
																				<?php
																				_e(
																					'blog',
																					'scanfully'
																				);
																				?>
																								</a>
						</div>
						<div>
							<h3><?php _e( 'About Scanfully', 'scanfully' ); ?></h3>
							<div class="scanfully-about">
								<a href="https://www.scanfully.com" target="_blank"><img
											src="
											<?php
											echo plugins_url(
												'/assets/images/logo.png',
												SCANFULLY_PLUGIN_FILE
											);
											?>
													" alt="Scanfully"/></a>
								<p>
								<?php
								_e(
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