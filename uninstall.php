<?php
/**
 * Uninstall cleanup.
 *
 * Runs when the plugin is deleted from the Plugins screen. Removes everything
 * the plugin stored: options and transients (including the API tokens and the
 * WooCheckout probe secret), Scanfully's Action Scheduler jobs and the
 * WooCheckout probe user.
 *
 * The plugin itself is not loaded during uninstall, so this file only uses
 * WordPress functions and literal names instead of the plugin's classes.
 *
 * @package Scanfully
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove Scanfully's data from the current site.
 *
 * @return void
 */
function scanfully_uninstall_site(): void {
	scanfully_uninstall_probe_user();
	scanfully_uninstall_scheduled_actions();
	scanfully_uninstall_options();
}

/**
 * Delete the WooCheckout probe user, but only when it is still the account the
 * plugin created: the expected login, an @scanfully.invalid email and only
 * the customer role. Any other account is left alone.
 *
 * @return void
 */
function scanfully_uninstall_probe_user(): void {
	$user_id = (int) get_option( 'scanfully_woocheckout_probe_user_id', 0 );
	if ( $user_id <= 0 ) {
		return;
	}

	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User ) {
		return;
	}

	$is_probe_user = 'scanfully_probe' === $user->user_login
		&& '@scanfully.invalid' === substr( $user->user_email, -strlen( '@scanfully.invalid' ) )
		&& [ 'customer' ] === array_values( $user->roles );
	if ( ! $is_probe_user ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $user_id );
}

/**
 * Delete Scanfully's Action Scheduler jobs and their logs.
 *
 * Action Scheduler isn't loaded during uninstall, so its tables are cleaned
 * directly. Other plugins' jobs are untouched: only the `scanfully` group is
 * removed.
 *
 * @return void
 */
function scanfully_uninstall_scheduled_actions(): void {
	global $wpdb;

	$actions = $wpdb->prefix . 'actionscheduler_actions';
	$groups  = $wpdb->prefix . 'actionscheduler_groups';
	$logs    = $wpdb->prefix . 'actionscheduler_logs';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Action Scheduler tables have no API during uninstall; table names come from $wpdb->prefix.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $actions ) ) ) !== $actions
		|| $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $groups ) ) ) !== $groups
	) {
		return;
	}

	$group_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM {$groups} WHERE slug = %s", 'scanfully' ) );
	if ( $group_id <= 0 ) {
		return;
	}

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $logs ) ) ) === $logs ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE logs FROM {$logs} logs INNER JOIN {$actions} actions ON actions.action_id = logs.action_id WHERE actions.group_id = %d",
				$group_id
			)
		);
	}
	$wpdb->delete( $actions, [ 'group_id' => $group_id ], [ '%d' ] );
	$wpdb->delete( $groups, [ 'group_id' => $group_id ], [ '%d' ] );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Delete every Scanfully option and transient. All of them start with
 * `scanfully_`.
 *
 * @return void
 */
function scanfully_uninstall_options(): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off lookup of the plugin's own option names during uninstall.
	$names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'scanfully_' ) . '%',
			$wpdb->esc_like( '_transient_scanfully_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_scanfully_' ) . '%'
		)
	);

	foreach ( $names as $name ) {
		delete_option( $name );
	}

	// With a persistent object cache, transients live in the cache instead
	// of the options table. Delete the fixed-name ones explicitly; the rest
	// expire on their own within an hour.
	foreach ( [ 'scanfully_connect_state', 'scanfully_refresh_failures', 'scanfully_refresh_error', 'scanfully_sync_last_run', 'scanfully_email_deliverability_run_now_lock' ] as $transient ) {
		delete_transient( $transient );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $scanfully_site_id ) {
		switch_to_blog( (int) $scanfully_site_id );
		scanfully_uninstall_site();
		restore_current_blog();
	}
} else {
	scanfully_uninstall_site();
}
