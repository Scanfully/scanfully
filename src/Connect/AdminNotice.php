<?php

namespace Scanfully\Connect;

use Scanfully\Options\Controller as OptionsController;

class AdminNotice {

	/**
	 * The number of days after which a connection is considered stale.
	 */
	private const STALE_THRESHOLD_DAYS = 2;

	/**
	 * Set up the admin notice
	 *
	 * @return void
	 */
	public static function setup(): void {
		global $pagenow;

		$options = OptionsController::get_options();

		if ( $options->is_connected && self::is_connection_stale( $options ) ) {
			add_action( 'admin_notices', [ AdminNotice::class, 'print_stale_notice' ] );
			add_action( 'admin_enqueue_scripts', function () {
				wp_enqueue_style( 'scanfully-not-connected-notice', plugins_url( '/assets/css/not-connected-notice.css', SCANFULLY_PLUGIN_FILE ), [], SCANFULLY_VERSION );
			} );
			return;
		}

		// Don't show the onboarding notice on the Scanfully settings page.
		if ( 'options-general.php' === $pagenow && isset( $_GET['page'] ) && 'scanfully' === $_GET['page'] ) {
			return;
		}

		if ( ! $options->is_connected ) {
			add_action( 'admin_notices', [ AdminNotice::class, 'print_notice' ] );
			add_action( 'admin_enqueue_scripts', function () {
				wp_enqueue_style( 'scanfully-not-connected-notice', plugins_url( '/assets/css/not-connected-notice.css', SCANFULLY_PLUGIN_FILE ), [], SCANFULLY_VERSION );
			} );
		}
	}

	/**
	 * Check if the connection is stale based on the last_used timestamp.
	 *
	 * @param \Scanfully\Options\Options $options
	 *
	 * @return bool
	 */
	private static function is_connection_stale( \Scanfully\Options\Options $options ): bool {
		// If refresh has failed repeatedly, the connection is broken.
		if ( \Scanfully\Cron\Controller::has_refresh_failure() ) {
			return true;
		}

		$reference_date = $options->last_used;

		// Fall back to date_connected if last_used is empty.
		if ( empty( $reference_date ) ) {
			$reference_date = $options->date_connected;
		}

		// If neither date is available, don't flag as stale.
		if ( empty( $reference_date ) ) {
			return false;
		}

		try {
			$dt  = \DateTime::createFromFormat( Controller::DATE_FORMAT, $reference_date, new \DateTimeZone( 'UTC' ) );
			if ( ! $dt ) {
				return false;
			}
			$now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
			$diff = $now->getTimestamp() - $dt->getTimestamp();

			return $diff > ( self::STALE_THRESHOLD_DAYS * DAY_IN_SECONDS );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Print the stale connection notice (non-dismissible).
	 *
	 * @return void
	 */
	public static function print_stale_notice(): void {
		$reconnect_url = add_query_arg( [
			'page'                       => 'scanfully',
			'scanfully-reconnect'        => 1,
			'scanfully-reconnect-nonce'  => wp_create_nonce( 'scanfully-reconnect' ),
		], admin_url( 'options-general.php' ) );
		?>
		<div class="notice notice-error scanfully-not-connected-notice">
			<div class="scanfully-notice-header">
				<span class="scanfully-notice-logo"></span>
				<h2><?php esc_html_e( 'Scanfully connection issue', 'scanfully' ); ?></h2>
			</div>
			<p>
				<?php esc_html_e( 'Your Scanfully connection does not appear to be working. The plugin has not communicated with the Scanfully service for over 2 days.', 'scanfully' ); ?><br/>
				<?php
				$refresh_error = \Scanfully\Cron\Controller::get_refresh_error();
				if ( ! empty( $refresh_error ) ) {
					printf( '<strong>%s</strong> %s<br/>', esc_html__( 'Reason:', 'scanfully' ), esc_html( $refresh_error ) );
				}
				?>
				<?php esc_html_e( 'Please reconnect your website to restore monitoring.', 'scanfully' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $reconnect_url ); ?>" class="button button-primary"><?php esc_html_e( 'Reconnect to Scanfully', 'scanfully' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Print the not-connected notice.
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