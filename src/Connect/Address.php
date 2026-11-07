<?php
/**
 * The connection address class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Connect;

use Scanfully\Manage;
use Scanfully\Options\Controller as OptionsController;

/**
 * The address the site was connected from, to tell a copy of the site from
 * the site itself. A host's one-click staging, a migration copy or a backup
 * restored elsewhere copies the plugin's options, tokens included: without
 * this check both copies would pull and run the same site's Scanfully Manage
 * jobs, each job on whichever copy pulled first.
 *
 * The plugin keeps home_url() as it was at connect: host plus path,
 * lowercased, without the scheme and without a trailing slash, so a move from
 * http to https is the same site. While the current address differs,
 * Scanfully Manage is paused: no clock, no pull, no run, no result sent, and
 * the manage and scopes pokes are answered 409 site_moved. The site's admin
 * decides: keep the connection (the same site at a new address) or reconnect
 * the copy as a site of its own. Regular monitoring is not affected.
 */
class Address {

	/**
	 * The option holding the address, without the Scanfully prefix.
	 */
	public const OPTION = 'address';

	/**
	 * The code of a poke answered while the address differs.
	 */
	public const MOVED_CODE = 'site_moved';

	/**
	 * Query parameter of the "Keep the connection" action.
	 */
	private const KEEP_PARAM = 'scanfully-keep-address';

	/**
	 * Nonce action of the "Keep the connection" action.
	 */
	private const KEEP_NONCE = 'scanfully-keep-address';

	/**
	 * Query parameter marking that the connection was kept.
	 */
	private const KEPT_PARAM = 'scanfully-address-kept';

	/**
	 * Register the action and the notices.
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_action( 'admin_init', [ self::class, 'catch_requests' ] );
		add_action( 'admin_notices', [ self::class, 'print_admin_notice' ] );
		add_action( 'scanfully_connect_notices', [ self::class, 'print_page_notice' ] );
	}

	/**
	 * An address in the stored form: host plus path, lowercased, without the
	 * scheme and without a trailing slash.
	 *
	 * @param string $url The address.
	 *
	 * @return string The normalized address, or '' when it has no host.
	 */
	public static function normalize( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || '' === $parts['host'] ) {
			return '';
		}
		$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';

		return strtolower( $parts['host'] . $path );
	}

	/**
	 * The site's address now, normalized.
	 *
	 * @return string
	 */
	public static function current(): string {
		return self::normalize( home_url() );
	}

	/**
	 * The address the site was connected from, or '' when none is stored.
	 *
	 * @return string
	 */
	public static function stored(): string {
		return OptionsController::get_option( self::OPTION );
	}

	/**
	 * Store the current address as the connection's own: at connect, and
	 * when the admin keeps the connection at a new address.
	 *
	 * @return void
	 */
	public static function remember(): void {
		OptionsController::set_option( self::OPTION, self::current() );
	}

	/**
	 * A connection made before this version has no address: it adopts the
	 * current one once.
	 *
	 * @return void
	 */
	public static function adopt_if_missing(): void {
		if ( OptionsController::get_options()->is_connected && '' === self::stored() && '' !== self::current() ) {
			self::remember();
		}
	}

	/**
	 * Whether the site runs at another address than the one it was
	 * connected from: a copy of the site, or the site moved.
	 *
	 * @return bool
	 */
	public static function moved(): bool {
		if ( ! OptionsController::get_options()->is_connected ) {
			return false;
		}
		$stored = self::stored();

		return '' !== $stored && self::current() !== $stored;
	}

	/**
	 * The answer to a poke while the address differs.
	 *
	 * @return \WP_REST_Response
	 */
	public static function moved_response(): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'status'  => self::MOVED_CODE,
				'code'    => self::MOVED_CODE,
				'message' => 'This site runs at another address than the one it was connected from.',
			],
			409
		);
	}

	/**
	 * Handle "Keep the connection": the same site at a new address. The new
	 * address becomes the connection's own and Scanfully Manage goes on.
	 *
	 * @return void
	 */
	public static function catch_requests(): void {
		if ( ! isset( $_GET[ self::KEEP_PARAM ] ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::KEEP_NONCE ) ) {
			wp_die( 'Invalid Scanfully nonce' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to do this.' );
		}

		self::keep();

		wp_safe_redirect( add_query_arg( [ self::KEPT_PARAM => '1' ], Page::get_page_url() ) );
		exit;
	}

	/**
	 * Adopt the current address and resume Scanfully Manage: the clock
	 * starts again and the connection's scopes are read now.
	 *
	 * @return void
	 */
	public static function keep(): void {
		if ( ! OptionsController::get_options()->is_connected ) {
			return;
		}
		self::remember();
		Manage\Controller::sync_clock();
		if ( function_exists( 'as_schedule_single_action' ) && Scopes::applies() ) {
			as_schedule_single_action( time(), Scopes::ACTION_CHECK_NOW, [], 'scanfully', true );
		}
	}

	/**
	 * The URL of "Keep the connection".
	 *
	 * @return string
	 */
	public static function keep_url(): string {
		return add_query_arg(
			[
				self::KEEP_PARAM => '1',
				'_wpnonce'       => wp_create_nonce( self::KEEP_NONCE ),
			],
			Page::get_page_url()
		);
	}

	/**
	 * The URL of "Reconnect as its own site": the plugin's reconnect, which
	 * forgets this copy's connection (without revoking the tokens it shares
	 * with the original site) and starts a new one.
	 *
	 * @return string
	 */
	public static function reconnect_url(): string {
		return add_query_arg(
			[
				'scanfully-reconnect'       => '1',
				'scanfully-reconnect-nonce' => wp_create_nonce( 'scanfully-reconnect' ),
			],
			Page::get_page_url()
		);
	}

	/**
	 * What the notice says, with the stored address.
	 *
	 * @return string
	 */
	public static function notice_text(): string {
		return sprintf(
			/* translators: %s: the address the site was connected from, such as scanfully.com. */
			__( 'This WordPress site looks like a copy of %s. Scanfully Manage is paused here. If this is the same site at a new address, keep the connection; if it is a copy (staging, a backup), reconnect it as its own site.', 'scanfully' ),
			self::stored()
		);
	}

	/**
	 * Whether the notice shows: the address differs on a connection of the
	 * OAuth server, to a user who can change the connection.
	 *
	 * @return bool
	 */
	private static function shows(): bool {
		return self::moved() && Scopes::applies() && current_user_can( 'manage_options' );
	}

	/**
	 * The notice on every admin page but the Scanfully settings page (which
	 * shows its own), for a connection that allows Scanfully Manage: that is
	 * what the copy pauses.
	 *
	 * @return void
	 */
	public static function print_admin_notice(): void {
		global $pagenow;
		if ( 'options-general.php' === $pagenow && isset( $_GET['page'] ) && 'scanfully' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks the page to show the notice on.
			return;
		}
		if ( ! self::shows() || ! in_array( OAuth::SCOPE_MANAGE, Controller::granted_scopes(), true ) ) {
			return;
		}
		?>
		<div class="notice notice-warning scanfully-site-moved-notice">
			<p><strong><?php esc_html_e( 'Scanfully', 'scanfully' ); ?></strong></p>
			<p><?php echo esc_html( self::notice_text() ); ?></p>
			<p>
				<a href="<?php echo esc_url( self::keep_url() ); ?>" class="button button-primary"><?php esc_html_e( 'Keep the connection', 'scanfully' ); ?></a>
				<a href="<?php echo esc_url( self::reconnect_url() ); ?>" class="button"><?php esc_html_e( 'Reconnect as its own site', 'scanfully' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * The notice on the Scanfully settings page, for every connection of
	 * the OAuth server; and the confirmation after the connection was kept.
	 *
	 * @return void
	 */
	public static function print_page_notice(): void {
		if ( isset( $_GET[ self::KEPT_PARAM ] ) && ! self::moved() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows a confirmation.
			?>
			<div class="scanfully-connect-notice scanfully-connect-notice-success">
				<p><?php esc_html_e( 'The connection was kept at this address. Scanfully Manage runs again.', 'scanfully' ); ?></p>
			</div>
			<?php
			return;
		}
		if ( ! self::shows() ) {
			return;
		}
		?>
		<div class="scanfully-connect-notice scanfully-connect-notice-error scanfully-site-moved-notice">
			<p><?php echo esc_html( self::notice_text() ); ?></p>
			<p style="display: flex; gap: 1em;">
				<a href="<?php echo esc_url( self::keep_url() ); ?>" class="button button-primary"><?php esc_html_e( 'Keep the connection', 'scanfully' ); ?></a>
				<a href="<?php echo esc_url( self::reconnect_url() ); ?>" class="button"><?php esc_html_e( 'Reconnect as its own site', 'scanfully' ); ?></a>
			</p>
		</div>
		<?php
	}
}
