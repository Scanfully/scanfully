<?php
/**
 * The connect page class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Connect;

use Scanfully\Options\Controller as OptionsController;
use Scanfully\Util;

/**
 * Connect page
 */
class Page {

	/**
	 * The admin page slug, the same in a site's Settings and in Network Admin.
	 */
	public const SLUG = 'scanfully';

	/**
	 * Register the page and its install redirect.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'catch_install_request' ] );
		self::register_page();
	}

	/**
	 * Redirect install requests to the connect page.
	 *
	 * @return void
	 */
	public static function catch_install_request(): void {
		if ( isset( $_GET['scanfully-connect-install'] ) ) {
			wp_redirect( self::get_page_url() );
			exit;
		}
	}

	/**
	 * Get the connect page URL.
	 *
	 * @return string
	 */
	public static function get_page_url(): string {
		// the network connection is changed in Network Admin only, also when
		// a link to it shows on the main site.
		if ( Network::in_network_admin() || OptionsController::network_scope() ) {
			return Network::page_url();
		}

		return admin_url( 'options-general.php?page=' . self::SLUG );
	}

	/**
	 * Register the connect page in the settings menu.
	 *
	 * @return void
	 */
	public static function register_page(): void {
		add_action(
			'admin_menu',
			function () {
				$page_hook = add_options_page(
					__( 'Scanfully', 'scanfully' ),
					__( 'Scanfully', 'scanfully' ),
					'manage_options',
					self::SLUG,
					[ Page::class, 'render_page' ]
				);

				// enqueue our assets only on our plugin page.
				add_action( 'load-' . $page_hook, [ Page::class, 'enqueue_page_assets' ] );
			}
		);
	}

	/**
	 * Enqueue the connect page styles and scripts.
	 *
	 * @return void
	 */
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
		self::render( false );
	}

	/**
	 * Render Network Admin › Settings › Scanfully: the network connection.
	 *
	 * @return void
	 */
	public static function render_network_page(): void {
		if ( ! Network::user_may_manage() ) {
			return;
		}
		OptionsController::with_scope(
			true,
			static function (): void {
				self::render( true );
			}
		);
	}

	/**
	 * Render the connection page: a site's own, or the network's (in Network
	 * Admin). The main site's own page shows the network connection without
	 * its buttons while that applies.
	 *
	 * @param bool $network_page Whether this is the Network Admin page.
	 *
	 * @return void
	 */
	private static function render( bool $network_page ): void {
		$options      = OptionsController::get_options();
		$through_main = ! $network_page && OptionsController::network_scope();
		// A subsite the network's connection monitors.
		$child = $network_page || $through_main ? null : Children::current();
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
							<?php elseif ( null !== $child ) : ?>
								<span class="scanfully-connect-blob scanfully-connect-blob-info"><?php esc_html_e( 'Not connected on its own', 'scanfully' ); ?></span>
							<?php else : ?>
								<span class="scanfully-connect-blob scanfully-connect-blob-error"><?php esc_html_e( 'Not connected', 'scanfully' ); ?></span>
							<?php endif; ?>

						</div>
					</li>
					<?php self::render_network_child( $child ); ?>
					<?php if ( $options->is_connected ) : ?>
						<?php
						$last_used = '-';
						if ( $options->last_used != '' ) :
							$last_used = self::format_stored_date( $options->last_used );
						endif;
						?>
						<li>
							<div class="scanfully-connect-details-label"><?php esc_html_e( 'Last used', 'scanfully' ); ?></div>
							<div class="scanfully-connect-details-value"><span class="scanfully-connect-blob scanfully-connect-blob-info"><?php echo esc_html( $last_used ); ?></span></div>
						</li>
						<?php
						if ( $options->date_connected != '' ) :
							$connected = self::format_stored_date( $options->date_connected );
							?>
							<li>
								<div class="scanfully-connect-details-label"><?php esc_html_e( 'Date connected', 'scanfully' ); ?></div>
								<div class="scanfully-connect-details-value"><span class="scanfully-connect-blob scanfully-connect-blob-info"><?php echo esc_html( $connected ); ?></span></div>
							</li>
						<?php endif; ?>
						<li>
							<div class="scanfully-connect-details-label"><?php esc_html_e( 'Scanfully may', 'scanfully' ); ?></div>
							<div class="scanfully-connect-details-value scanfully-connect-scopes">
								<?php foreach ( Controller::granted_scopes() as $scope ) : ?>
									<span class="scanfully-connect-blob scanfully-connect-blob-info"><?php echo esc_html( self::scope_label( $scope ) ); ?></span>
								<?php endforeach; ?>
								<?php $changed = self::scopes_changed_note(); ?>
								<?php if ( '' !== $changed ) : ?>
									<p class="description scanfully-connect-scopes-changed"><?php echo esc_html( $changed ); ?></p>
								<?php endif; ?>
								<?php if ( Multisite::monitoring_only() ) : ?>
									<p class="description scanfully-connect-monitoring-only-note"><?php echo esc_html( Multisite::connected_note() ); ?></p>
								<?php endif; ?>
							</div>
						</li>
					<?php endif; ?>
				</ul>
				<div class="scanfully-connect-button-wrapper">
					<?php if ( $through_main ) : ?>
						<p class="scanfully-connect-network-note"><?php echo esc_html( Network::main_site_note() ); ?></p>
						<?php if ( current_user_can( 'manage_network_options' ) ) : ?>
							<p><a class="button" href="<?php echo esc_url( Network::page_url() ); ?>"><?php esc_html_e( 'Open the network connection', 'scanfully' ); ?></a></p>
						<?php endif; ?>
					<?php elseif ( $options->is_connected ) : ?>
						<?php if ( $network_page ) : ?>
							<p class="scanfully-connect-network-note"><?php esc_html_e( 'Network connection: Scanfully Manage and WP admin access act on every site of the network.', 'scanfully' ); ?></p>
						<?php endif; ?>
						<p style="display: flex; gap: 1em;">
							<?php Buttons::dashboard(); ?>
							<?php if ( Multisite::monitoring_only() ) : ?>
								<?php Buttons::change_access_monitoring_only(); ?>
							<?php else : ?>
								<?php Buttons::change_access(); ?>
							<?php endif; ?>
							<?php Buttons::disconnect(); ?>
						</p>
					<?php elseif ( $network_page ) : ?>
						<p class="scanfully-connect-network-notice"><?php echo esc_html( Network::notice() ); ?></p>
						<p style="display: inline-block">
							<?php Buttons::connect(); ?>
						</p>
					<?php elseif ( Multisite::monitoring_only() ) : ?>
						<?php if ( null !== $child ) : ?>
							<p class="scanfully-connect-network-child-optional"><?php echo esc_html( Multisite::connect_also_note() ); ?></p>
						<?php endif; ?>
						<p class="scanfully-connect-monitoring-only-notice"><?php echo esc_html( Multisite::notice() ); ?></p>
						<?php Buttons::connect_monitoring_only(); ?>
					<?php else : ?>
						<p style="display: inline-block">
							<?php Buttons::connect(); ?>
						</p>
					<?php endif; ?>
				</div>

				
				<?php \Scanfully\EmailHealth\Controller::render_admin_panel(); ?>
				<?php do_action( 'scanfully_connect_page_content_end' ); ?>
			</div>
			<div class="scanfully-setup-footer">
				<p>version <?php echo esc_html( SCANFULLY_VERSION ); ?></p>
				<p><a href="https://docs.scanfully.com/"><?php esc_html_e( 'help center', 'scanfully' ); ?></a> - <a href="https://scanfully.com/contact/"><?php esc_html_e( 'contact us', 'scanfully' ); ?></a></p>
			</div>
		</div>
		<?php
	}

	/**
	 * The row of a subsite the network's connection monitors: monitored
	 * through the network, and what goes there. Nothing for another site.
	 *
	 * @param Target|null $child The subsite's child target, or null.
	 *
	 * @return void
	 */
	public static function render_network_child( ?Target $child ): void {
		if ( null === $child ) {
			return;
		}
		?>
		<li class="scanfully-connect-network-child">
			<div class="scanfully-connect-details-label"><?php esc_html_e( 'Network', 'scanfully' ); ?></div>
			<div class="scanfully-connect-details-value">
				<span class="scanfully-connect-blob scanfully-connect-blob-success"><?php esc_html_e( 'Monitored through the network', 'scanfully' ); ?></span>
				<p class="description scanfully-connect-network-child-note"><?php echo esc_html( Multisite::network_monitored_note() ); ?></p>
			</div>
		</li>
		<?php
	}

	/**
	 * Format a stored UTC date (Controller::DATE_FORMAT) for display in the
	 * site's timezone and date format.
	 *
	 * @param string $value The stored date.
	 *
	 * @return string The formatted date, or '-' when it can't be read.
	 */
	private static function format_stored_date( string $value ): string {
		$date = \DateTime::createFromFormat( Controller::DATE_FORMAT, $value, new \DateTimeZone( 'UTC' ) );
		if ( ! $date instanceof \DateTime ) {
			return '-';
		}

		try {
			$date->setTimezone( Util\Date::get_timezone() );
		} catch ( \Exception $e ) {
			// Invalid site timezone: keep showing the date in UTC.
			unset( $e );
		}

		return $date->format( get_option( 'date_format' ) . ' @ ' . get_option( 'time_format' ) );
	}

	/**
	 * The note under the scopes when they last changed in the Scanfully
	 * dashboard, with when, or '' when they are as the connection approved
	 * them.
	 *
	 * @return string
	 */
	public static function scopes_changed_note(): string {
		$at = Scopes::changed_at();
		if ( $at <= 0 ) {
			return '';
		}
		$when = wp_date( get_option( 'date_format' ) . ' @ ' . get_option( 'time_format' ), $at );

		/* translators: %s: the date and time of the change. */
		return sprintf( __( 'Changed in your Scanfully dashboard on %s.', 'scanfully' ), false === $when ? '-' : $when );
	}

	/**
	 * What a scope lets Scanfully do, as the settings page says it.
	 *
	 * @param string $scope The scope.
	 *
	 * @return string
	 */
	public static function scope_label( string $scope ): string {
		switch ( $scope ) {
			case OAuth::SCOPE_READ:
				return __( 'Read site data: health, plugins and themes, and activity', 'scanfully' );
			case OAuth::SCOPE_MANAGE:
				return __( 'Update, install, activate, deactivate and remove plugins and themes, update WordPress and translations', 'scanfully' );
			case OAuth::SCOPE_LOGIN:
				return __( 'Sign you in to WP admin', 'scanfully' );
			default:
				return $scope;
		}
	}
}
