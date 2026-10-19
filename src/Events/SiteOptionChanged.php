<?php
/**
 * The site option changed event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class SiteOptionChanged
 *
 * A setting of the core settings screens changed; one row per setting.
 * SettingsChanges\Collector notes the changes of a request and reports them
 * at its end; the background job adds names (SettingsChanges\Settings).
 *
 * Data: `option`, `old` and `new` as strings. Instead of `old` and `new`:
 * `secret: true` for the post via email login and password, `added` and
 * `removed` (counts) for the comment word lists, `added` and `removed`
 * (URLs) for the update services. Added by the job: `old_name` and
 * `new_name` for pages, categories and the site icon, `old_example` and
 * `new_example` for date and time formats. `timezone` holds the zone as
 * WordPress shows it; `new_admin_email` is an admin email change waiting
 * for confirmation (`old` is the current address, `new` the requested
 * one), with `cancelled: true` when the request was withdrawn.
 *
 * @package Scanfully\Events
 */
class SiteOptionChanged extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'SiteOptionChanged' );
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
