<?php
/**
 * The events class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

use Scanfully\Options;

/**
 * Class Event
 */
abstract class Event {

	/**
	 * The type of event
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Action to listen to
	 *
	 * @var string
	 */
	private string $action;

	/**
	 * Priority of the action
	 *
	 * @var int
	 */
	private int $priority = 10;

	/**
	 * Accepted arguments
	 *
	 * @var int
	 */
	private int $accepted_args = 1;

	/**
	 * Constructor
	 *
	 * @param  string $event The type of event.
	 * @param  string $action The action to listen to.
	 * @param  int    $priority The priority of the action.
	 * @param  int    $accepted_args The accepted arguments.
	 */
	public function __construct(
		string $event,
		string $action,
		int $priority = 10,
		int $accepted_args = 1
	) {
		$this->type          = $event;
		$this->action        = $action;
		$this->priority      = $priority;
		$this->accepted_args = $accepted_args;

		$this->add_listener();
	}

	/**
	 * Add the listener to the action
	 *
	 * @return void
	 */
	private function add_listener(): void {
		add_action( $this->action, [ $this, 'listener_callback' ], $this->priority, $this->accepted_args );
	}

	/**
	 * Get the user who made the change: the signed-in user, unless the event
	 * names its own actor.
	 *
	 * @param array $args The arguments passed to the action.
	 *
	 * @return array
	 */
	private function get_user( array $args ): array {
		$current_user = $this->get_actor( $args ) ?? wp_get_current_user();

		return [
			'id'   => (int) ( $current_user->ID ?? 0 ),
			'name' => self::safe_display_name( $current_user ),
		];
	}

	/**
	 * The user who made the change, when that isn't the signed-in user (for
	 * a login, WordPress hasn't set the signed-in user yet). Null to use the
	 * signed-in user.
	 *
	 * @param array $args The arguments passed to the action.
	 *
	 * @return object|null
	 */
	protected function get_actor( array $args ): ?object {
		return null;
	}

	/**
	 * A user's display name, safe to send. WordPress defaults the display
	 * name to the login, which is often an email address; an email address
	 * is replaced by the user's first and last name, or left empty, so no
	 * email address leaves the site.
	 *
	 * @param object $user The user (a WP_User).
	 *
	 * @return string
	 */
	protected static function safe_display_name( object $user ): string {
		$name = trim( (string) ( $user->display_name ?? '' ) );
		if ( ! self::looks_like_email( $name ) ) {
			return $name;
		}

		$name = trim( ( $user->first_name ?? '' ) . ' ' . ( $user->last_name ?? '' ) );

		return self::looks_like_email( $name ) ? '' : $name;
	}

	/**
	 * Plain text from a value WordPress stores or returns HTML-encoded, such
	 * as the site title (`Barry&#039;s Shop`) or a theme name read through
	 * WP_Theme (`Tom &amp; Jerry`), so a name is spelled the same in every
	 * event.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	protected static function plain_text( $value ): string {
		return html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Whether a string is an email address.
	 *
	 * @param string $value The string.
	 *
	 * @return bool
	 */
	private static function looks_like_email( string $value ): bool {
		return false !== strpos( $value, '@' ) && false !== is_email( $value );
	}


	/**
	 * The callback for the action
	 *
	 * @param  mixed ...$args The arguments passed to the action.
	 *
	 * @return void
	 */
	public function listener_callback( ...$args ): void {

		// A site that isn't connected has nowhere to send events; the job
		// would only be discarded when it runs.
		if ( ! Options\Controller::get_options()->is_connected ) {
			return;
		}

		// check if we should fire the event.
		if ( ! $this->should_fire( $args ) ) {
			return;
		}

		// Schedule the API request as a background job so it does not block the current request.
		as_schedule_single_action(
			time(),
			Controller::ACTION_SEND_EVENT,
			self::fit_args(
				[
					'type' => $this->type,
					'user' => $this->get_user( $args ),
					'data' => $this->get_post_body( $args ),
				]
			),
			'scanfully'
		);
	}

	/**
	 * Action Scheduler rejects jobs whose arguments are longer than this, when
	 * encoded as JSON; the event would be lost.
	 */
	private const MAX_ARGS_LENGTH = 8000;

	/**
	 * Keep the job arguments within Action Scheduler's size limit: shorten
	 * long strings (such as a very long post title) first, and only replace
	 * the event data when that isn't enough.
	 *
	 * @param array $args The job arguments.
	 *
	 * @return array
	 */
	private static function fit_args( array $args ): array {
		foreach ( [ 0, 1000, 200 ] as $max_length ) {
			if ( $max_length > 0 ) {
				$args['data'] = self::shorten_strings( $args['data'], $max_length );
			}
			if ( strlen( (string) wp_json_encode( $args ) ) <= self::MAX_ARGS_LENGTH ) {
				return $args;
			}
		}

		$args['data'] = [ 'truncated' => true ];

		return $args;
	}

	/**
	 * Shorten every string in a value to a maximum length.
	 *
	 * @param mixed $value      The value.
	 * @param int   $max_length Maximum string length in characters.
	 *
	 * @return mixed
	 */
	private static function shorten_strings( $value, int $max_length ) {
		if ( is_string( $value ) ) {
			return mb_strlen( $value ) > $max_length ? mb_substr( $value, 0, $max_length ) : $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::shorten_strings( $item, $max_length );
			}
		}

		return $value;
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data passed to the action.
	 *
	 * @return array
	 */
	abstract public function get_post_body( array $data ): array;

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The event data.
	 *
	 * @return bool
	 */
	abstract public function should_fire( array $data ): bool;
}
