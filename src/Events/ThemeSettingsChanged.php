<?php
/**
 * The ThemeSettingsChanged event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class ThemeSettingsChanged
 *
 * The theme's settings changed in one save: a Customizer publish, or theme
 * mods, the logo or Additional CSS changed another way (WP-CLI, the old
 * Header and Background screens, the Site Logo block, importers). One row
 * lists every change (ThemeSettings\Collector).
 *
 * Data: `stylesheet` and `name` (the theme), `source` (`customizer` or
 * `other`), `scheduled` (a scheduled Customizer change cron published),
 * `settings` (each with `id`, `label` and `section` from the Customizer,
 * `kind` (`color`, `media`, `page`), `change`, `old` and `new` as short
 * values, `old_file` and `new_file` for images, `old_name` and `new_name`
 * for pages, `large`, `secret`), `logo` (`old` and `new` with `id` and
 * `file`) and `css` (added and removed lines, as for file edits). See
 * ThemeSettings\Builder.
 *
 * @package Scanfully\Events
 */
class ThemeSettingsChanged extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'ThemeSettingsChanged' );
	}

	/**
	 * The person who made the change when the collector names one (a
	 * scheduled Customizer change, published by cron).
	 *
	 * @param array $args The arguments passed to the action: the event data.
	 *
	 * @return object|null
	 */
	protected function get_actor( array $args ): ?object {
		$user_id = (int) ( $args[0]['_actor'] ?? 0 );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;

		return $user instanceof \WP_User ? $user : null;
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data passed to the action: the event data.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$body = parent::get_post_body( $data );
		unset( $body['_actor'] );

		return $body;
	}
}
