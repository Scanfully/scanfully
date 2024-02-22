<?php

namespace Scanfully\Connect;

use Scanfully\Options\Controller as OptionsController;

class AdminNotice {

	/**
	 * Set up the admin notice
	 *
	 * @return void
	 */
	public static function setup(): void {
		global $pagenow;

		if ( 'options-general.php' === $pagenow && isset( $_GET['page'] ) && 'scanfully' === $_GET['page'] ) {
			return;
		}

		// check if we are connected
		$options = OptionsController::get_options();
		if ( $options->is_connected ) {
			return;
		}

		add_action( 'admin_notices', [ AdminNotice::class, 'print_notice' ] );

		add_action( 'admin_enqueue_scripts', function () {
			wp_enqueue_style( 'scanfully-not-connected-notice', plugins_url( '/assets/css/not-connected-notice.css', SCANFULLY_PLUGIN_FILE ), [], SCANFULLY_VERSION );
		} );
	}

	/**
	 * Print the notice
	 *
	 * @return void
	 */
	public static function print_notice(): void {
		?>
		<div class="notice notice-info is-dismissible scanfully-not-connected-notice">
			<div class="scanfully-notice-header">
				<span class="scanfully-notice-logo"></span>
				<h2><?php esc_html_e( 'Welcome to Scanfully!', 'scanfully' ); ?></h2>
			</div>
			<p><?php esc_html_e( 'Scanfully is the best tool to monitor your performance & site health for WordPress.', 'scanfully' ); ?><br/>
				<?php esc_html_e( 'Connect your website to your Scanfully account to get started.', 'scanfully' ); ?>
				<a href="<?php echo esc_url( Page::get_page_url() ); ?>"><?php esc_html_e( 'Finish setting up Scanfully', 'scanfully' ); ?></a>
			</p>
		</div>
		<?php
	}
}