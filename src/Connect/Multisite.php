<?php
/**
 * The connection on a multisite network.
 *
 * @package Scanfully
 */

namespace Scanfully\Connect;

/**
 * On a WordPress multisite network the plugin's connection lives on one
 * subsite (its options), and whoever may manage that subsite's options may
 * make it: the subsite's administrator, who need not be a super admin.
 * Such a connection is for monitoring only. It never signs anyone in (WP
 * admin access) and never runs a Manage job, because on a network those act
 * on every site of the network, which only a super admin may change. A
 * connection made at network level, by a super admin, is what will carry
 * Manage on a network; until it exists, every connection on a network is a
 * monitoring-only one.
 *
 * The API refuses the same on its side (a site that reports multisite gets
 * no login link and no Manage job), so neither side relies on the other.
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
	 * Whether this site's connection is for monitoring only: the site is part
	 * of a multisite network.
	 *
	 * @return bool
	 */
	public static function monitoring_only(): bool {
		return function_exists( 'is_multisite' ) && is_multisite();
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
		return __( 'This site is part of a WordPress multisite network. You can connect it to Scanfully for monitoring. Updates, installs and WP admin access act on the whole network, so they need a connection at network level, made by a network administrator.', 'scanfully' );
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
