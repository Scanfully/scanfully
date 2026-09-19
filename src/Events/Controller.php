<?php
/**
 * The events controller class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

use Scanfully\API\EventRequest;
use Scanfully\Options;

/**
 * Class Controller
 *
 * @package Scanfully\Events
 */
class Controller {

	/**
	 * Action Scheduler hook name for sending events.
	 */
	public const ACTION_SEND_EVENT = 'scanfully_send_event';

	/**
	 * The events
	 *
	 * @var Event[]
	 */
	private static array $events = [];

	/**
	 * Register the events
	 *
	 * @param  Event $event The event to register.
	 *
	 * @return void
	 */
	public static function register( Event $event ): void {
		self::$events[] = $event;
	}

	/**
	 * Get the registered events
	 *
	 * @return Event[]
	 */
	public static function get_events(): array {
		return self::$events;
	}

	/**
	 * Register the Action Scheduler callback that performs the actual API send.
	 *
	 * @return void
	 */
	public static function register_send_callback(): void {
		add_action( self::ACTION_SEND_EVENT, [ self::class, 'send_event' ], 10, 4 );
	}

	/**
	 * Number of times a temporarily failed event is retried.
	 */
	private const MAX_SEND_RETRIES = 3;

	/**
	 * Seconds between retries of a failed event.
	 */
	private const SEND_RETRY_DELAY = 5 * MINUTE_IN_SECONDS;

	/**
	 * Action Scheduler callback: send a scheduled event to the API.
	 *
	 * A temporary failure (no response, 401 while the token is refreshed, 429
	 * or a server error) is retried a few times. Every failure throws, so
	 * Action Scheduler records the job as failed with the reason instead of
	 * the event disappearing silently.
	 *
	 * @param  string $type    The event type.
	 * @param  array  $user    The user data.
	 * @param  array  $data    The event data.
	 * @param  int    $attempt Retry number; 0 for the first attempt.
	 *
	 * @return void
	 * @throws \RuntimeException When the API did not accept the event.
	 */
	public static function send_event( string $type, array $user, array $data, int $attempt = 0 ): void {
		$options = Options\Controller::get_options();
		if ( ! $options->is_connected ) {
			return;
		}

		$request = new EventRequest();
		$status  = $request->send(
			[
				'type' => $type,
				'user' => $user,
				'data' => $data,
			]
		);

		if ( null !== $status && $status >= 200 && $status < 300 ) {
			return;
		}

		$temporary = null === $status || 401 === $status || 429 === $status || $status >= 500;
		if ( $temporary && $attempt < self::MAX_SEND_RETRIES ) {
			as_schedule_single_action( time() + self::SEND_RETRY_DELAY, self::ACTION_SEND_EVENT, [ $type, $user, $data, $attempt + 1 ], 'scanfully' );
		}

		throw new \RuntimeException(
			sprintf(
				'Scanfully API did not accept the %s event (%s).',
				esc_html( $type ),
				null === $status ? 'no response' : 'HTTP ' . (int) $status
			)
		);
	}

	/**
	 * Setup custom events.
	 * Some events aren't straight forward WP actions to Scanfully events. This is where we can add them.
	 *
	 * @return void
	 */
	public static function setup_custom_events(): void {
		self::plugin_update_event();
		self::theme_update_event();
	}

	/**
	 * Custom plugin update event.
	 *
	 * @return void
	 */
	private static function plugin_update_event(): void {

		// this is an odd one, but we need to hook into the upgrader_package_options to get the old version number and pass it to the upgrader_install_package_result via the hook_extra.
		add_filter(
			'upgrader_package_options',
			function ( $options ) {

				// check if a plugin is being updated.
				if ( isset( $options['hook_extra']['plugin'] ) ) {
					$data = get_file_data( WP_PLUGIN_DIR . '/' . $options['hook_extra']['plugin'], array( 'Version' => 'Version' ) );

					if ( ! empty( $data['Version'] ) ) {
						$options['hook_extra']['scanfully_old_version'] = $data['Version'];
					}
				}

				return $options;
			},
			99,
			1
		);

		// this is a filter run after the plugin has been updated.
		// Preferably we would use the upgrader_process_complete but it's a weird action that can be called from 2 places.
		// On a single update, it does contain the hook_extra but on a bulk update it doesn't.
		// And for some reason when you update via AJAX (which is what happens when you update a plugin from the plugin page) it is treated as a bulk update with 1 plugin.
		add_filter(
			'upgrader_install_package_result',
			function ( $result, $hook_extra ) {

				// WordPress applies this filter to failed installs too; only a
				// successful update is an update event.
				if ( is_wp_error( $result ) || ! is_array( $hook_extra ) ) {
					return $result;
				}

				// check if a plugin is being updated.
				if ( isset( $hook_extra['plugin'] ) ) {

					$plugin_slug = $hook_extra['plugin'];

					// don't fire for our own plugin.
					if ( \Scanfully\Main::is_own_plugin( $plugin_slug ) ) {
						return $result;
					}

					// get new plugin data.
					$plugin_data = get_file_data(
						WP_PLUGIN_DIR . '/' . $plugin_slug,
						[
							'Name'        => 'Plugin Name',
							'Version'     => 'Version',
							'Author'      => 'Author',
							'RequiresWP'  => 'Requires at least',
							'RequiresPHP' => 'Requires PHP',
						]
					);

					// fire our custom action so our event system can pick it up.
					do_action(
						'scanfully_plugin_updated',
						[
							'name'         => $plugin_data['Name'] ?? '',
							'version'      => $plugin_data['Version'] ?? '',
							'old_version'  => $hook_extra['scanfully_old_version'] ?? '', // this is the old version number.
							'author'       => $plugin_data['Author'] ?? '',
							'slug'         => $plugin_slug,
							'requires_wp'  => $plugin_data['RequiresWP'] ?? '',
							'requires_php' => $plugin_data['RequiresPHP'] ?? '',
						]
					);

				}

				return $result;
			},
			99,
			2
		);
	}

	/**
	 * Theme update event.
	 *
	 * @return void
	 */
	private static function theme_update_event(): void {

		// this is an odd one, but we need to hook into the upgrader_package_options to get the old version number and pass it to the upgrader_install_package_result via the hook_extra.
		add_filter(
			'upgrader_package_options',
			function ( $options ) {

				// check if a plugin is being updated.
				if ( isset( $options['hook_extra']['theme'], $options['destination'] ) ) {

					$data = get_file_data( $options['destination'] . '/' . $options['hook_extra']['theme'] . '/style.css', [ 'Version' => 'Version' ] );

					if ( ! empty( $data['Version'] ) ) {
						$options['hook_extra']['scanfully_old_version'] = $data['Version'];
					}
				}

				return $options;
			},
			99,
			1
		);

		add_filter(
			'upgrader_install_package_result',
			function ( $result, $hook_extra ) {

				// WordPress applies this filter to failed installs too; only a
				// successful update is an update event.
				if ( is_wp_error( $result ) || ! is_array( $hook_extra ) ) {
					return $result;
				}

				// check if a theme is being updated.
				if ( isset( $hook_extra['theme'] ) ) {

					$theme_slug = $hook_extra['theme'];

					// the theme can live in any registered theme directory.
					$theme_data = get_file_data(
						get_theme_root( $theme_slug ) . '/' . $theme_slug . '/style.css',
						[
							'Name'        => 'Theme Name',
							'Version'     => 'Version',
							'Author'      => 'Author',
							'Template'    => 'Template',
							'RequiresWP'  => 'Requires at least',
							'RequiresPHP' => 'Requires PHP',
						]
					);

					// fire our custom action so our event system can pick it up.
					do_action(
						'scanfully_theme_updated',
						[
							'name'         => $theme_data['Name'] ?? '',
							'version'      => $theme_data['Version'] ?? '',
							'old_version'  => $hook_extra['scanfully_old_version'] ?? '', // this is the old version number.
							'author'       => $theme_data['Author'] ?? '',
							'slug'         => $theme_slug,
							'requires_wp'  => $theme_data['RequiresWP'] ?? '',
							'requires_php' => $theme_data['RequiresPHP'] ?? '',
						]
					);

				}

				return $result;
			},
			99,
			2
		);
	}
}
