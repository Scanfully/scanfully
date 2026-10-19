<?php
/**
 * The content event limiter class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\Content;

/**
 * Class Limiter
 *
 * How many post or term events a site sends a minute. Bulk actions in one
 * request already become summary rows; the cap is for tools that change
 * many posts or terms in many requests. What is over the cap is not
 * dropped: it goes to the Overflow, which reports it a minute later.
 *
 * Settings, menus, widgets, theme settings and the Site Editor have a cap of their own; what is over it is
 * dropped, as for user events (a person changes them a few at a time).
 *
 * @package Scanfully\Events\Content
 */
final class Limiter {

	/**
	 * Settings changes (SettingsChanges\Collector).
	 */
	public const KIND_SETTING = 'setting';

	/**
	 * Menu changes (MenuChanges\Collector).
	 */
	public const KIND_MENU = 'menu';

	/**
	 * Widget changes (WidgetChanges\Collector).
	 */
	public const KIND_WIDGET = 'widget';

	/**
	 * Theme settings changes (ThemeSettings\Collector).
	 */
	public const KIND_THEME = 'theme';

	/**
	 * Site Editor styles and templates (SiteEditor\Collector).
	 */
	public const KIND_SITE_EDITOR = 'site_editor';

	/**
	 * Default number of events a minute, per kind; 0 or less is no cap.
	 */
	private const PER_MINUTE = 30;

	/**
	 * The filter of each kind's cap; posts use
	 * `scanfully_post_saved_events_per_minute`.
	 */
	private const FILTERS = [
		Report::KIND_TERM  => 'scanfully_term_events_per_minute',
		self::KIND_SETTING => 'scanfully_settings_events_per_minute',
		self::KIND_MENU    => 'scanfully_menu_events_per_minute',
		self::KIND_WIDGET  => 'scanfully_widget_events_per_minute',
		self::KIND_THEME   => 'scanfully_theme_settings_events_per_minute',
		self::KIND_SITE_EDITOR => 'scanfully_site_editor_events_per_minute',
	];

	/**
	 * Take room for events in this minute's cap: how many of them may be
	 * sent now. One transient read and write per request, however many
	 * events it has.
	 *
	 * @param string $kind   Report::KIND_POST, Report::KIND_TERM or one of the
	 *                       kinds above.
	 * @param int    $wanted How many events the request has.
	 *
	 * @return int
	 */
	public static function take( string $kind, int $wanted ): int {
		if ( $wanted < 1 ) {
			return 0;
		}

		$limit = (int) apply_filters( self::FILTERS[ $kind ] ?? 'scanfully_post_saved_events_per_minute', self::PER_MINUTE ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The filters start with scanfully_.
		if ( $limit < 1 ) {
			return $wanted;
		}

		$bucket = 'scanfully_' . $kind . '_events_' . (int) floor( time() / MINUTE_IN_SECONDS );
		$count  = (int) get_transient( $bucket );
		$taken  = max( 0, min( $wanted, $limit - $count ) );
		if ( $taken > 0 ) {
			set_transient( $bucket, $count + $taken, 2 * MINUTE_IN_SECONDS );
		}

		return $taken;
	}
}
