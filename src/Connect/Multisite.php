<?php
/**
 * The connection on a multisite network.
 *
 * @package Scanfully
 */

namespace Scanfully\Connect;

use Scanfully\Options\Controller as OptionsController;

/**
 * On a WordPress multisite network a site's own connection lives in that
 * site's options, and whoever may manage that site's options may make it:
 * the site's administrator, who need not be a super admin. Such a
 * connection is for monitoring only. It never signs anyone in (WP admin
 * access) and never runs a Manage job, because on a network those act on
 * every site of the network, which only a super admin may change. Only the
 * network connection, made from Network Admin by a super admin and kept in
 * the network's options (see Network), carries Manage and WP admin access.
 *
 * The API refuses the same on its side: on a multisite only a connection
 * approved and reported as the network's gets login links and Manage jobs.
 * The kind a connection asks for is the plugin's to say, so the plugin's own
 * refusals here are what holds; the API's add a second line.
 */
class Multisite {

	/**
	 * The connect form's field that confirms a monitoring-only connection.
	 */
	public const CONFIRM_FIELD = 'scanfully-monitoring-only';

	/**
	 * The query flag that brings the admin back to the connect page when the
	 * confirmation was missing.
	 */
	public const CONFIRM_NEEDED = 'scanfully-monitoring-only-needed';

	/**
	 * Whether the connection that applies here is for monitoring only: the
	 * site is part of a multisite network and the connection is not the
	 * network's.
	 *
	 * @return bool
	 */
	public static function monitoring_only(): bool {
		return Network::multisite() && ! OptionsController::network_scope();
	}

	/**
	 * Whether a connect request carries the confirmation, or does not need
	 * one (a single site).
	 *
	 * @param array<string, mixed> $request The request's query (normally $_GET).
	 *
	 * @return bool
	 */
	public static function confirmed( array $request ): bool {
		if ( ! self::monitoring_only() ) {
			return true;
		}

		return isset( $request[ self::CONFIRM_FIELD ] ) && '1' === $request[ self::CONFIRM_FIELD ];
	}

	/**
	 * What the connect page says before a connection on a network.
	 *
	 * @return string
	 */
	public static function notice(): string {
		return __( 'This site is part of a WordPress multisite network. You can connect it to Scanfully for monitoring. Updates, installs and WP admin access act on the whole network, so they need the network connection, which a network administrator makes in Network Admin › Settings › Scanfully.', 'scanfully' );
	}

	/**
	 * The confirmation the admin ticks before connecting on a network.
	 *
	 * @return string
	 */
	public static function confirmation(): string {
		return __( 'I understand, I only want monitoring for this site.', 'scanfully' );
	}

	/**
	 * What the connect page says about a connection on a network.
	 *
	 * @return string
	 */
	public static function connected_note(): string {
		return __( 'Monitoring only: this site is part of a multisite network, so Scanfully does not update software or sign anyone in here.', 'scanfully' );
	}

	/**
	 * What the connect page says when the confirmation was missing.
	 *
	 * @return string
	 */
	public static function confirm_needed(): string {
		return __( 'Tick the box to confirm that you only want monitoring for this site, then connect again.', 'scanfully' );
	}
}
