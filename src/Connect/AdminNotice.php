<?php

namespace Scanfully\Connect;

use Scanfully\Options\Controller as OptionsController;

class AdminNotice {
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

	public static function print_notice(): void {
		?>
		<div class="notice notice-info is-dismissible scanfully-not-connected-notice">
			<div class="scanfully-notice-header">
				<span class="scanfully-notice-logo"></span>
				<h2>Welcome to Scanfully!</h2>
			</div>
			<p>Scanfully is the best tool to monitor your performance & site health for WordPress.<br/>
				Connect your website to your Scanfully account to get started.
				<a href="<?php echo esc_url( Page::get_page_url() ); ?>">Finish setting up Scanfully</a>
			</p>
		</div>
		<?php
	}
}