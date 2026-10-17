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
	 * Action Scheduler hook name for working out what changed in a saved
	 * post and sending it (PostChanges\Builder).
	 */
	public const ACTION_BUILD_POST_EVENT = 'scanfully_build_post_event';

	/**
	 * Action Scheduler hook name for naming and sending a content event: a
	 * permanent delete, a term change or a summary row (Content\Builder).
	 */
	public const ACTION_BUILD_CONTENT_EVENT = 'scanfully_build_content_event';

	/**
	 * Action Scheduler hook name for reporting the posts or terms changed
	 * over a minute's cap as summary rows (Content\Overflow).
	 */
	public const ACTION_SEND_OVERFLOW = 'scanfully_send_content_overflow';

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
		add_action( self::ACTION_BUILD_POST_EVENT, [ PostChanges\Builder::class, 'build' ], 10, 5 );
		add_action( self::ACTION_BUILD_CONTENT_EVENT, [ Content\Builder::class, 'build' ], 10, 5 );
		add_action( self::ACTION_SEND_OVERFLOW, [ Content\Overflow::class, 'send' ], 10, 2 );
	}

	/**
	 * Number of times a temporarily failed event is retried.
	 */
	private const MAX_SEND_RETRIES = 3;

	/**
	 * Seconds between retries of a failed event.
	 */
	public const SEND_RETRY_DELAY = 5 * MINUTE_IN_SECONDS;

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
		self::deliver(
			$type,
			$user,
			$data,
			$attempt,
			static function () use ( $type, $user, $data, $attempt ): void {
				as_schedule_single_action( time() + self::SEND_RETRY_DELAY, self::ACTION_SEND_EVENT, [ $type, $user, $data, $attempt + 1 ], 'scanfully' );
			}
		);
	}

	/**
	 * Send an event to the API. On a temporary failure with retries left,
	 * `$retry` schedules the next attempt; every failure then throws.
	 *
	 * @param  string   $type    The event type.
	 * @param  array    $user    The user data.
	 * @param  array    $data    The event data.
	 * @param  int      $attempt Retry number; 0 for the first attempt.
	 * @param  callable $retry   Schedules the next attempt.
	 *
	 * @return void
	 * @throws \RuntimeException When the API did not accept the event.
	 */
	public static function deliver( string $type, array $user, array $data, int $attempt, callable $retry ): void {
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
			$retry();
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
		self::update_failed_events();
	}

	/**
	 * Plugins and themes whose failed update was already reported in this
	 * request, keyed `plugin:<basename>` or `theme:<stylesheet>`, so an
	 * automatic update that fails while installing isn't reported twice.
	 *
	 * @var array<string, bool>
	 */
	private static array $reported_failures = [];

	/**
	 * Failed plugin, theme and core update events.
	 *
	 * Two sources, because neither sees every failure:
	 * - `upgrader_install_package_result` sees a plugin or theme update that
	 *   fails while the package is installed (an incompatible package, files
	 *   that can't be copied), for manual, WP-CLI and automatic updates. It
	 *   doesn't see failures before that, such as a download that fails.
	 * - `automatic_updates_complete` has the result of every automatic update,
	 *   including download failures, fatal error rollbacks and core updates.
	 *   A failed manual core update has no hook with its result, so only
	 *   automatic core updates are reported.
	 *
	 * @return void
	 */
	private static function update_failed_events(): void {
		add_filter( 'upgrader_package_options', [ self::class, 'remember_update_item' ], 99, 1 );
		add_filter( 'upgrader_install_package_result', [ self::class, 'install_package_failed' ], 99, 2 );
		add_action( 'automatic_updates_complete', [ self::class, 'automatic_updates_failed' ], 10, 1 );
	}

	/**
	 * Keep the name and the version on offer of a plugin or theme that is
	 * about to be updated in the upgrader's hook extras: once a failed update
	 * has removed the old files, they can no longer be read.
	 *
	 * @param mixed $options The upgrader package options.
	 *
	 * @return mixed
	 */
	public static function remember_update_item( $options ) {
		if ( ! is_array( $options ) || ! isset( $options['hook_extra'] ) || ! is_array( $options['hook_extra'] ) ) {
			return $options;
		}

		if ( isset( $options['hook_extra']['plugin'] ) ) {
			$plugin = (string) $options['hook_extra']['plugin'];
			$data   = get_file_data( WP_PLUGIN_DIR . '/' . $plugin, [ 'Name' => 'Plugin Name' ] );
			$offers = get_site_transient( 'update_plugins' );

			$options['hook_extra']['scanfully_old_name']    = (string) ( $data['Name'] ?? '' );
			$options['hook_extra']['scanfully_new_version'] = (string) ( $offers->response[ $plugin ]->new_version ?? '' );
		} elseif ( isset( $options['hook_extra']['theme'] ) ) {
			$theme  = (string) $options['hook_extra']['theme'];
			$data   = get_file_data( get_theme_root( $theme ) . '/' . $theme . '/style.css', [ 'Name' => 'Theme Name' ] );
			$offers = get_site_transient( 'update_themes' );

			$options['hook_extra']['scanfully_old_name']    = (string) ( $data['Name'] ?? '' );
			$options['hook_extra']['scanfully_new_version'] = (string) ( $offers->response[ $theme ]['new_version'] ?? '' );
		}

		return $options;
	}

	/**
	 * Report a plugin or theme update that failed while installing the
	 * package. Scanfully's own failed updates are reported too: the old
	 * version stays in place, so the event can still be sent.
	 *
	 * @param mixed $result     The install result.
	 * @param mixed $hook_extra The upgrader hook extras.
	 *
	 * @return mixed The install result, unchanged.
	 */
	public static function install_package_failed( $result, $hook_extra ) {
		if ( ! is_wp_error( $result ) || ! is_array( $hook_extra ) ) {
			return $result;
		}

		foreach ( [ 'plugin', 'theme' ] as $type ) {
			if ( ! isset( $hook_extra[ $type ] ) ) {
				continue;
			}

			$slug = (string) $hook_extra[ $type ];
			$name = (string) ( $hook_extra['scanfully_old_name'] ?? '' );

			self::$reported_failures[ $type . ':' . $slug ] = true;

			do_action(
				'scanfully_' . $type . '_update_failed',
				[
					'name'        => '' !== $name ? $name : $slug,
					'slug'        => $slug,
					'version'     => (string) ( $hook_extra['scanfully_old_version'] ?? '' ),
					'new_version' => (string) ( $hook_extra['scanfully_new_version'] ?? '' ),
					'error'       => self::failure_message( $result ),
				]
			);
			break;
		}

		return $result;
	}

	/**
	 * Report the automatic updates that failed.
	 *
	 * @param mixed $update_results The results per type ('core', 'plugin',
	 *                              'theme', 'translation'), each a list of
	 *                              objects with item, result, name and
	 *                              messages.
	 *
	 * @return void
	 */
	public static function automatic_updates_failed( $update_results ): void {
		if ( ! is_array( $update_results ) ) {
			return;
		}

		foreach ( [ 'plugin', 'theme' ] as $type ) {
			foreach ( (array) ( $update_results[ $type ] ?? [] ) as $update ) {
				// WordPress itself only counts `true` as a successful plugin or theme update.
				if ( ! is_object( $update ) || true === ( $update->result ?? null ) || ! is_object( $update->item ?? null ) ) {
					continue;
				}

				$slug = (string) ( $update->item->$type ?? '' );
				if ( '' === $slug || isset( self::$reported_failures[ $type . ':' . $slug ] ) ) {
					continue;
				}
				self::$reported_failures[ $type . ':' . $slug ] = true;

				// The automatic updater reads names through get_plugin_data()
				// and WP_Theme, which return them HTML-encoded.
				$name = html_entity_decode( (string) ( $update->name ?? '' ), ENT_QUOTES, 'UTF-8' );
				do_action(
					'scanfully_' . $type . '_update_failed',
					[
						'name'        => '' !== $name ? $name : $slug,
						'slug'        => $slug,
						'version'     => (string) ( $update->item->current_version ?? '' ),
						'new_version' => (string) ( $update->item->new_version ?? '' ),
						'error'       => self::failure_message( $update->result ?? null, (array) ( $update->messages ?? [] ) ),
					]
				);
			}
		}

		foreach ( (array) ( $update_results['core'] ?? [] ) as $update ) {
			// A core update returns the new version on success.
			if ( ! is_object( $update ) || ( ! empty( $update->result ) && ! is_wp_error( $update->result ) ) ) {
				continue;
			}

			$item = is_object( $update->item ?? null ) ? $update->item : new \stdClass();
			do_action(
				'scanfully_core_update_failed',
				[
					'version'     => (string) ( $item->current ?? $item->version ?? '' ),
					'old_version' => (string) get_bloginfo( 'version' ),
					'error'       => self::failure_message( $update->result ?? null, (array) ( $update->messages ?? [] ) ),
				]
			);
		}
	}

	/**
	 * The error message of a failed update as plain text: the message of a
	 * WP_Error (with its detail, such as the reason a download failed), or
	 * else the last message the upgrader showed. Empty when there is none.
	 *
	 * @param mixed $result   The update result.
	 * @param array $messages The upgrader's messages.
	 *
	 * @return string
	 */
	private static function failure_message( $result, array $messages = [] ): string {
		$message = '';
		if ( is_wp_error( $result ) ) {
			$message = $result->get_error_message();
			$detail  = $result->get_error_data();
			if ( is_string( $detail ) && '' !== $detail ) {
				$message .= ' ' . $detail;
			}
		} else {
			foreach ( array_reverse( $messages ) as $line ) {
				if ( is_string( $line ) && '' !== trim( $line ) ) {
					$message = $line;
					break;
				}
			}
		}

		return trim( self::strip_paths( html_entity_decode( wp_strip_all_tags( $message ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Remove absolute server paths from an error message. WordPress puts the
	 * full path in the error data of file errors (such as "Could not create
	 * directory." or "Destination folder already exists."). Paths under a
	 * known WordPress directory become relative (`wp-content/plugins/akismet/`);
	 * any other absolute path keeps only its last part (`…/akismet.zip`).
	 * URLs are left alone.
	 *
	 * @param string $message The message.
	 *
	 * @return string
	 */
	private static function strip_paths( string $message ): string {
		foreach ( self::path_roots() as $root => $label ) {
			$quoted = preg_quote( $root, '#' );
			// The root followed by a separator, then the root on its own.
			$message = (string) preg_replace( '#' . $quoted . '[/\\\\]#', '' === $label ? '' : $label . '/', $message );
			$message = (string) preg_replace( '#' . $quoted . '(?=[\s\'"<>(),;:]|\.(?:\s|$)|$)#', '' === $label ? './' : $label, $message );
		}

		// Any other absolute path: Unix (not part of a URL), then Windows.
		$message = (string) preg_replace( '#(?<![\w:/.~\-])/(?:[^\s\'"<>/\\\\]+/)+([^\s\'"<>/\\\\]*)#', '…/$1', $message );
		$message = (string) preg_replace( '#(?<![\w:])[A-Za-z]:[/\\\\](?:[^\s\'"<>/\\\\]+[/\\\\])*([^\s\'"<>/\\\\]*)#', '…/$1', $message );

		return $message;
	}

	/**
	 * The known WordPress directories and the relative name each one gets in
	 * an error message, longest path first, without a trailing separator.
	 *
	 * @return array<string, string>
	 */
	private static function path_roots(): array {
		global $wp_filesystem;

		$candidates = [
			[ WP_PLUGIN_DIR, 'wp-content/plugins' ],
			[ ABSPATH, '' ],
		];
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$candidates[] = [ WP_CONTENT_DIR, 'wp-content' ];
		}
		if ( function_exists( 'get_theme_root' ) ) {
			$candidates[] = [ get_theme_root(), 'wp-content/themes' ];
		}
		if ( function_exists( 'get_temp_dir' ) ) {
			$candidates[] = [ get_temp_dir(), 'tmp' ];
		}
		// The file system WordPress writes through (FTP, SSH) can see the
		// site under other paths.
		if ( is_object( $wp_filesystem ) ) {
			if ( method_exists( $wp_filesystem, 'wp_plugins_dir' ) ) {
				$candidates[] = [ $wp_filesystem->wp_plugins_dir(), 'wp-content/plugins' ];
			}
			if ( method_exists( $wp_filesystem, 'wp_content_dir' ) ) {
				$candidates[] = [ $wp_filesystem->wp_content_dir(), 'wp-content' ];
			}
			if ( method_exists( $wp_filesystem, 'abspath' ) ) {
				$candidates[] = [ $wp_filesystem->abspath(), '' ];
			}
		}

		$roots = [];
		foreach ( $candidates as [ $path, $label ] ) {
			$path = rtrim( (string) $path, '/\\' );
			// A root of "/" (an FTP account that starts in the site) would
			// match everything.
			if ( strlen( $path ) > 1 && ! isset( $roots[ $path ] ) ) {
				$roots[ $path ] = $label;
			}
		}
		uksort(
			$roots,
			static function ( string $a, string $b ): int {
				return strlen( $b ) - strlen( $a );
			}
		);

		return $roots;
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
