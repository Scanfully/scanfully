<?php
/**
 * Email deliverability controller.
 *
 * Single-class home for the per-cycle Pinger flow, the admin status panel,
 * the Run-Now AJAX handler, and the small helper bundle (transport
 * detection, anti-loop check, AS heartbeat freshness). See
 * src/EmailHealth/README.md for the full feature plan + manual verification
 * matrix.
 *
 * @package Scanfully
 */

namespace Scanfully\EmailHealth;

use Scanfully\API\EmailDeliverabilityAttemptRequest;
use Scanfully\API\EmailDeliverabilityAttemptResultRequest;
use Scanfully\API\EmailDeliverabilityProvisionRequest;
use Scanfully\API\EmailDeliverabilityStateRequest;
use Scanfully\Cron\Controller as CronController;
use Scanfully\Options\Controller as OptionController;
use Scanfully\Util;

/**
 * Class Controller.
 */
class Controller {

	/**
	 * Default ping cadence (seconds) used as a safety fallback before the
	 * server-side cadence is fetched. Mirrors the API's
	 * EMAIL_HEALTH_DELIVERABILITY_DEFAULT_INTERVAL_SECONDS default.
	 */
	private const FALLBACK_INTERVAL_SECONDS = 21600;

	/**
	 * Faster cadence applied for FAILURE_BACKOFF_SECONDS after a failure.
	 */
	private const FAILURE_INTERVAL_SECONDS = 1800;

	/**
	 * How long (seconds) after the most recent failure we run on the faster
	 * cadence before reverting to the default.
	 */
	private const FAILURE_BACKOFF_SECONDS = 86400; // 24h

	/**
	 * Server-side rate limit for the "Run check now" admin button.
	 */
	private const RUN_NOW_LOCK_SECONDS = 60;

	/**
	 * Transient key for the run-now rate limit.
	 */
	private const RUN_NOW_LOCK_KEY = 'scanfully_email_deliverability_run_now_lock';

	/**
	 * AJAX action name for "Run check now".
	 */
	private const AJAX_RUN_NOW = 'scanfully_email_deliverability_run_now';

	/**
	 * admin-post action name for saving the From address.
	 */
	private const ADMIN_POST_SAVE_FROM = 'scanfully_email_deliverability_save_from';

	/**
	 * Nonce action used by the From-address save form.
	 */
	private const NONCE_SAVE_FROM = 'scanfully_email_deliverability_save_from';

	/**
	 * Domain suffixes whose admin_email indicates a feedback loop with
	 * Scanfully's own outbound mail; the Pinger refuses to send if
	 * admin_email matches.
	 */
	private const LOOP_SUFFIXES = [ 'scanfully.com', 'scanfully.dev' ];

	/**
	 * Bootstrap. Called from Main::setup().
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( CronController::ACTION_EMAIL_DELIVERABILITY_PING, [ self::class, 'run_ping' ], 10, 1 );
		add_action( 'wp_ajax_' . self::AJAX_RUN_NOW, [ self::class, 'handle_ajax_run_now' ] );
		add_action( 'admin_post_' . self::ADMIN_POST_SAVE_FROM, [ self::class, 'handle_save_from_address' ] );
	}

	// --- Per-cycle Pinger ----------------------------------------------------

	/**
	 * Per-cycle Pinger entry point. Wired to the AS recurring action.
	 *
	 * @param array $args Optional args: {source: 'scheduled'|'manual'}.
	 *
	 * @return void
	 */
	public static function run_ping( $args = [] ): void {
		$args = is_array( $args ) ? $args : [];
		$source = isset( $args['source'] ) ? (string) $args['source'] : 'scheduled';

		// (1) Heartbeat first so admin UI can detect AS staleness even when we
		// short-circuit below.
		OptionController::set_option( 'email_deliverability_last_as_run_at', self::utc_now_iso(), false );

		// (2) Pre-flight guards.
		if ( 'yes' !== OptionController::get_option( 'is_connected' ) ) {
			return;
		}
		if ( 'yes' !== self::get_enabled_option() ) {
			return;
		}
		if ( self::is_local_environment() && ! apply_filters( 'scanfully_email_deliverability_force_in_local', false ) ) {
			return;
		}
		if ( self::is_from_address_loopable() ) {
			self::log_warn( 'Configured From address is on a Scanfully domain; refusing to send ping (anti-loop).' );
			return;
		}

		$site_id = OptionController::get_option( 'site_id' );
		if ( '' === $site_id ) {
			return;
		}

		// (3) Lazy provision.
		$secret = OptionController::get_option( 'email_deliverability_secret' );
		$inbound_address = OptionController::get_option( 'email_deliverability_inbound_address' );
		if ( '' === $secret || '' === $inbound_address ) {
			if ( ! self::provision_credentials() ) {
				return;
			}
			$secret = OptionController::get_option( 'email_deliverability_secret' );
			$inbound_address = OptionController::get_option( 'email_deliverability_inbound_address' );
			if ( '' === $secret || '' === $inbound_address ) {
				return;
			}
		}

		// (4) Compose attempt.
		$nonce = wp_generate_uuid4();
		$timestamp = self::utc_now_iso();
		$token = Token::compute( $secret, $site_id, $nonce, $timestamp );
		$transport_hint = self::detect_transport();

		// (5) Log attempt server-side BEFORE sending mail.
		$attempt_req = new EmailDeliverabilityAttemptRequest();
		$attempt_resp = $attempt_req->send(
			[
				'nonce' => $nonce,
				'timestamp' => $timestamp,
				'token' => $token,
				'transport_hint' => $transport_hint,
				'source' => $source,
			]
		);
		if ( null === $attempt_resp || $attempt_resp['status'] < 200 || $attempt_resp['status'] >= 300 ) {
			// 401 (invalid HMAC) or 409 (site not provisioned) means our local
			// secret is stale or missing on the server. Re-provision and retry
			// once within the same cycle so existing customers self-heal.
			$status = null === $attempt_resp ? 0 : (int) $attempt_resp['status'];
			if ( 401 === $status || 409 === $status ) {
				if ( ! self::provision_credentials() ) {
					self::record_failure();
					self::reschedule_if_changed();
					return;
				}
				$secret = OptionController::get_option( 'email_deliverability_secret' );
				$inbound_address = OptionController::get_option( 'email_deliverability_inbound_address' );
				if ( '' === $secret || '' === $inbound_address ) {
					self::record_failure();
					self::reschedule_if_changed();
					return;
				}
				$nonce = wp_generate_uuid4();
				$timestamp = self::utc_now_iso();
				$token = Token::compute( $secret, $site_id, $nonce, $timestamp );
				$attempt_resp = $attempt_req->send(
					[
						'nonce' => $nonce,
						'timestamp' => $timestamp,
						'token' => $token,
						'transport_hint' => $transport_hint,
						'source' => $source,
					]
				);
				if ( null === $attempt_resp || $attempt_resp['status'] < 200 || $attempt_resp['status'] >= 300 ) {
					self::record_failure();
					self::reschedule_if_changed();
					return;
				}
			} else {
				self::record_failure();
				self::reschedule_if_changed();
				return;
			}
		}

		// (6) Build mail.
		$to = self::expand_inbound_address( $inbound_address, $nonce );
		if ( '' === $to ) {
			self::record_failure();
			self::reschedule_if_changed();
			return;
		}
		$subject = sprintf( 'Scanfully deliverability ping %s', $nonce );
		$body = wp_json_encode(
			[
				'site_id' => $site_id,
				'nonce' => $nonce,
				'timestamp' => $timestamp,
				'token' => $token,
				'php_version' => PHP_VERSION,
				'wp_version' => get_bloginfo( 'version' ),
				'plugin_version' => defined( 'SCANFULLY_VERSION' ) ? SCANFULLY_VERSION : '',
			]
		);
		$headers = [
			'From: ' . self::get_from_address(),
			'X-Scanfully-Token: ' . $token,
		];

		// (7) One-shot wp_mail_failed capture.
		$captured_error = '';
		$capture = static function ( $wp_error ) use ( &$captured_error ) {
			if ( is_wp_error( $wp_error ) ) {
				$captured_error = (string) $wp_error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $capture );
		$sent = wp_mail( $to, $subject, $body, $headers );
		remove_action( 'wp_mail_failed', $capture );

		// (8) Reconcile the local send result with the adaptive cadence.
		if ( false === $sent || '' !== $captured_error ) {
			// wp_mail() reported a failure. Always tell the server so its state
			// machine has the data. But some transports return false (or fire
			// wp_mail_failed) even when the message is actually delivered; the
			// server is authoritative because it confirms inbound arrival. If it
			// already reports "healthy", treat this as a false positive and clear
			// the backoff instead of pinning the site to the 30-minute cadence
			// indefinitely (every failing cycle would otherwise re-stamp the
			// marker so the 24h window never expires).
			self::post_attempt_failure( $nonce, $captured_error );
			if ( self::server_reports_healthy() ) {
				self::clear_failure();
			} else {
				self::record_failure();
			}
		} else {
			// Local send succeeded: clear any stale backoff so the cadence
			// reverts to the default interval on the next reschedule.
			self::clear_failure();
		}

		// (9) Recompute schedule (applies adaptive cadence).
		self::reschedule_if_changed();
	}

	/**
	 * Provision (or rotate) the per-site HMAC secret. The API endpoint always
	 * returns 200 with a fresh secret + inbound address + interval, so this is
	 * a single, idempotent flow. Returns true on success.
	 *
	 * Called in two situations:
	 *   1. Lazy provision when the local secret is missing (first connect or
	 *      after disconnect cleared it).
	 *   2. Self-heal when /attempt rejects our token with 401 or 409.
	 *
	 * @return bool
	 */
	private static function provision_credentials(): bool {
		$req = new EmailDeliverabilityProvisionRequest();
		$res = $req->send();
		if ( null === $res || 200 !== (int) $res['status'] || ! is_array( $res['body'] ) ) {
			return false;
		}
		$body = $res['body'];
		if ( empty( $body['secret'] ) || empty( $body['inbound_address'] ) ) {
			return false;
		}
		OptionController::set_option( 'email_deliverability_secret', (string) $body['secret'], false );
		OptionController::set_option( 'email_deliverability_inbound_address', (string) $body['inbound_address'], false );
		if ( ! empty( $body['interval_seconds'] ) ) {
			OptionController::set_option( 'email_deliverability_interval_seconds', (string) (int) $body['interval_seconds'], false );
		}
		return true;
	}

	/**
	 * POST /attempt-result for a failed wp_mail() call. Best-effort; failures
	 * are logged but do not propagate.
	 *
	 * @param string $nonce       The attempt nonce.
	 * @param string $error_message Captured wp_mail_failed message (may be empty).
	 *
	 * @return void
	 */
	private static function post_attempt_failure( string $nonce, string $error_message ): void {
		$req = new EmailDeliverabilityAttemptResultRequest();
		$req->send(
			[
				'nonce' => $nonce,
				'wp_mail_returned' => false,
				'wp_mail_error' => $error_message,
			]
		);
	}

	/**
	 * Substitute the {nonce} placeholder with the encoded nonce in the
	 * inbound address template returned by /provision.
	 *
	 * @param string $template The address template.
	 * @param string $nonce    Canonical hyphenated nonce UUID.
	 *
	 * @return string Empty string on encode failure.
	 */
	private static function expand_inbound_address( string $template, string $nonce ): string {
		try {
			$encoded = AddressCodec::encode( $nonce );
		} catch (\Throwable $e) {
			self::log_warn( 'AddressCodec encode failed: ' . $e->getMessage() );
			return '';
		}
		return apply_filters( 'scanfully_email_expand_inbound_address', str_replace( '{nonce}', $encoded, $template ) );
	}

	/**
	 * Record that the most recent cycle failed. Drives the adaptive cadence.
	 *
	 * @return void
	 */
	private static function record_failure(): void {
		OptionController::set_option( 'email_deliverability_last_failure_at', self::utc_now_iso(), false );
	}

	/**
	 * Clear the adaptive-cadence failure marker so the schedule reverts to the
	 * default interval on the next reschedule.
	 *
	 * @return void
	 */
	private static function clear_failure(): void {
		if ( '' !== OptionController::get_option( 'email_deliverability_last_failure_at' ) ) {
			OptionController::set_option( 'email_deliverability_last_failure_at', '', false );
		}
	}

	/**
	 * Whether the server currently reports the site as healthy. Used to detect
	 * false-positive wp_mail() failures where the transport returns false (or
	 * fires wp_mail_failed) even though the message is actually delivered.
	 * Best-effort: on a transport error fetch_state() returns null, so we fall
	 * back to the conservative behaviour of honouring the local failure signal.
	 *
	 * @return bool
	 */
	private static function server_reports_healthy(): bool {
		$state = self::fetch_state();
		return is_array( $state ) && isset( $state['state'] ) && 'healthy' === (string) $state['state'];
	}

	/**
	 * Reschedule the recurring AS action when the desired cadence changes.
	 *
	 * @return void
	 */
	private static function reschedule_if_changed(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		$desired = self::current_interval_seconds();

		// Refresh option cache so the admin UI shows the up-to-date interval.
		OptionController::set_option( 'email_deliverability_interval_seconds', (string) $desired, false );

		// Compare against currently scheduled by inspecting the next action's
		// schedule. AS does not expose interval directly; re-scheduling on
		// every cycle is cheap and idempotent when the interval matches.
		as_unschedule_all_actions( CronController::ACTION_EMAIL_DELIVERABILITY_PING, [], 'scanfully' );
		as_schedule_recurring_action( time() + $desired, $desired, CronController::ACTION_EMAIL_DELIVERABILITY_PING, [], 'scanfully' );
	}

	/**
	 * Current ping interval in seconds. 30m for 24h after a failure;
	 * otherwise the configured default (or fallback).
	 *
	 * @return int
	 */
	public static function current_interval_seconds(): int {
		$last_failure = OptionController::get_option( 'email_deliverability_last_failure_at' );
		if ( '' !== $last_failure ) {
			$ts = strtotime( $last_failure );
			if ( false !== $ts && ( time() - $ts ) < self::FAILURE_BACKOFF_SECONDS ) {
				return self::FAILURE_INTERVAL_SECONDS;
			}
		}
		$cached = (int) OptionController::get_option( 'email_deliverability_interval_seconds' );
		if ( $cached > 0 ) {
			return $cached;
		}
		return self::FALLBACK_INTERVAL_SECONDS;
	}

	// --- Helpers -------------------------------------------------------------

	/**
	 * Best-effort detection of the active mail transport. Returns a
	 * "{slug}/{version}" string or empty if nothing detected.
	 *
	 * @return string
	 */
	public static function detect_transport(): string {
		$candidates = [
			'wp-mail-smtp/wp-mail-smtp.php' => 'wp-mail-smtp',
			'fluent-smtp/fluent-smtp.php' => 'fluent-smtp',
			'easy-wp-smtp/easy-wp-smtp.php' => 'easy-wp-smtp',
			'post-smtp/postman-smtp.php' => 'post-smtp',
			'wp-ses/wp-ses.php' => 'wp-ses',
			'wp-mailgun-smtp/wp-mailgun-smtp.php' => 'mailgun-smtp',
		];
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		foreach ( $candidates as $file => $slug ) {
			if ( isset( $plugins[ $file ] ) && is_plugin_active( $file ) ) {
				$version = isset( $plugins[ $file ]['Version'] ) ? (string) $plugins[ $file ]['Version'] : '';
				return $version ? $slug . '/' . $version : $slug;
			}
		}
		return 'wp_mail/' . get_bloginfo( 'version' );
	}

	/**
	 * Resolve the From address used for outbound deliverability pings.
	 * Falls back to the site's admin_email when the override option is empty.
	 *
	 * @return string
	 */
	public static function get_from_address(): string {
		$override = trim( (string) OptionController::get_option( 'email_deliverability_from_address' ) );
		if ( '' !== $override && is_email( $override ) ) {
			return $override;
		}
		return (string) get_bloginfo( 'admin_email' );
	}

	/**
	 * Whether the configured From address points at a Scanfully-managed domain
	 * (would cause a feedback loop if used as the From address).
	 *
	 * @return bool
	 */
	public static function is_from_address_loopable(): bool {
		return self::is_email_loopable( self::get_from_address() );
	}

	/**
	 * Whether the given email address is on a Scanfully-managed domain.
	 *
	 * @param string $email Email address to test.
	 *
	 * @return bool
	 */
	private static function is_email_loopable( string $email ): bool {
		if ( '' === $email ) {
			return false;
		}
		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return false;
		}
		$host = strtolower( trim( substr( $email, $at + 1 ) ) );
		$host = rtrim( $host, '.' );
		foreach ( self::LOOP_SUFFIXES as $suf ) {
			if ( $host === $suf || self::str_ends_with( $host, '.' . $suf ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * AS heartbeat freshness check. True when the last recorded run is older
	 * than 2x the current configured interval.
	 *
	 * @return bool
	 */
	public static function as_heartbeat_stale(): bool {
		$last = OptionController::get_option( 'email_deliverability_last_as_run_at' );
		if ( '' === $last ) {
			return false; // never run yet; not "stale", just "not started".
		}
		$ts = strtotime( $last );
		if ( false === $ts ) {
			return false;
		}
		$interval = self::current_interval_seconds();
		return ( time() - $ts ) > ( 2 * $interval );
	}

	/**
	 * Whether the email-deliverability sub-feature is enabled. Defaults to
	 * 'yes' on a freshly connected site (auto-enable per plan).
	 *
	 * @return string 'yes' | ''.
	 */
	private static function get_enabled_option(): string {
		$v = OptionController::get_option( 'email_deliverability_enabled' );
		if ( '' === $v ) {
			return 'yes';
		}
		return $v;
	}

	/**
	 * Whether we are running in WordPress's "local" environment.
	 *
	 * @return bool
	 */
	private static function is_local_environment(): bool {
		return function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type();
	}

	/**
	 * Fetch the current state from the API. Returns null on transport error
	 * or when no body is available.
	 *
	 * @return array|null
	 */
	private static function fetch_state(): ?array {
		$req = new EmailDeliverabilityStateRequest();
		$resp = $req->fetch();
		if ( null === $resp || $resp['status'] < 200 || $resp['status'] >= 300 || ! is_array( $resp['body'] ) ) {
			return null;
		}
		return $resp['body'];
	}

	/**
	 * Return the current UTC timestamp in RFC 3339 / ISO-8601 form.
	 *
	 * @return string
	 */
	private static function utc_now_iso(): string {
		try {
			$dt = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
			return $dt->format( 'Y-m-d\TH:i:s\Z' );
		} catch (\Exception $e) {
			return gmdate( 'Y-m-d\TH:i:s\Z' );
		}
	}

	/**
	 * PHP 7.4-compatible str_ends_with() shim.
	 *
	 * @param string $haystack The string to check.
	 * @param string $needle   The substring to test for.
	 *
	 * @return bool
	 */
	private static function str_ends_with( string $haystack, string $needle ): bool {
		if ( function_exists( 'str_ends_with' ) ) {
			return str_ends_with( $haystack, $needle );
		}
		$nl = strlen( $needle );
		if ( 0 === $nl ) {
			return true;
		}
		return substr( $haystack, -$nl ) === $needle;
	}

	/**
	 * Emit a warning to the WP debug log when WP_DEBUG and WP_DEBUG_LOG are
	 * both enabled. Silent in production.
	 *
	 * @param string $msg Message to log.
	 *
	 * @return void
	 */
	private static function log_warn( string $msg ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[scanfully:email-deliverability] ' . $msg );
		}
	}

	// --- Admin UI ------------------------------------------------------------

	/**
	 * Format a UTC ISO-8601 timestamp using the site's date/time format and
	 * timezone preferences, matching the style used in the Connect section.
	 *
	 * @param string $iso UTC timestamp (e.g. "2025-01-15T12:00:00Z").
	 *
	 * @return string Formatted string, or '-' on failure.
	 */
	private static function format_utc_date( string $iso ): string {
		if ( '' === $iso ) {
			return '-';
		}
		try {
			$dt = new \DateTime( $iso, new \DateTimeZone( 'UTC' ) );
			// Go's zero time.Time serialises as "0001-01-01T00:00:00Z".
			if ( (int) $dt->format( 'Y' ) < 2000 ) {
				return '-';
			}
			try {
				$dt->setTimezone( Util\Date::get_timezone() );
			} catch (\Exception $e) {
				// Unrecognised timezone; leave the DateTime in UTC.
				unset( $e );
			}
			return $dt->format( get_option( 'date_format' ) . ' @ ' . get_option( 'time_format' ) );
		} catch (\Exception $e) {
			return '-';
		}
	}

	/**
	 * Format a cadence in seconds as a human-readable string
	 * (e.g. 21600 -> "6 hours", 1800 -> "30 minutes").
	 *
	 * @param int $seconds Interval in seconds.
	 *
	 * @return string
	 */
	/**
	 * Format a latency value (milliseconds) into a human-readable string.
	 * Values under 1 000 ms show as e.g. "247 ms"; larger values as e.g. "4.3 s".
	 *
	 * @param int $ms Latency in milliseconds.
	 * @return string
	 */
	private static function format_latency( int $ms ): string {
		if ( $ms < 1000 ) {
			return $ms . ' ms';
		}
		$seconds = round( $ms / 1000, 1 );
		return $seconds . ' s';
	}

	private static function format_interval( int $seconds ): string {
		if ( $seconds >= 3600 && 0 === $seconds % 3600 ) {
			$hours = $seconds / 3600;
			// translators: %d is the number of hours.
			return sprintf( _n( '%d hour', '%d hours', $hours, 'scanfully' ), $hours );
		}
		if ( $seconds >= 60 && 0 === $seconds % 60 ) {
			$minutes = $seconds / 60;
			// translators: %d is the number of minutes.
			return sprintf( _n( '%d minute', '%d minutes', $minutes, 'scanfully' ), $minutes );
		}
		// translators: %d is the number of seconds.
		return sprintf( _n( '%d second', '%d seconds', $seconds, 'scanfully' ), $seconds );
	}

	/**
	 * Render the email-deliverability section inside the Scanfully settings
	 * page. Called directly from Connect\Page::render_page().
	 *
	 * @return void
	 */
	public static function render_admin_panel(): void {
		if ( 'yes' !== OptionController::get_option( 'is_connected' ) ) {
			return;
		}
		$state = self::fetch_state();

		$blob_class = 'scanfully-connect-blob';
		$badge_label = __( 'Unknown', 'scanfully' );
		if ( is_array( $state ) && isset( $state['state'] ) ) {
			switch ( (string) $state['state'] ) {
				case 'healthy':
					$blob_class .= ' scanfully-connect-blob-success';
					$badge_label = __( 'Healthy', 'scanfully' );
					break;
				case 'deliverability_broken':
					$blob_class .= ' scanfully-connect-blob-error';
					$badge_label = __( 'Broken', 'scanfully' );
					break;
				case 'plugin_unreachable':
					$blob_class .= ' scanfully-connect-blob-error';
					$badge_label = __( 'Plugin unreachable', 'scanfully' );
					break;
			}
		}

		$inbound = isset( $state['inbound_address'] ) ? (string) $state['inbound_address'] : '';
		$next = function_exists( 'as_next_scheduled_action' )
			? as_next_scheduled_action( CronController::ACTION_EMAIL_DELIVERABILITY_PING, [], 'scanfully' )
			: false;
		$nonce = wp_create_nonce( self::AJAX_RUN_NOW );
		$from_address = self::get_from_address();
		$from_override = (string) OptionController::get_option( 'email_deliverability_from_address' );
		$from_notice = isset( $_GET['scanfully_from_saved'] ) ? (string) sanitize_key( wp_unslash( $_GET['scanfully_from_saved'] ) ) : '';
		$from_placeholder = (string) get_bloginfo( 'admin_email' );
		?>
		<hr />
		<h2><?php esc_html_e( 'Email deliverability', 'scanfully' ); ?></h2>
		<p><?php esc_html_e( 'Monitors whether WordPress can deliver email to the outside world.', 'scanfully' ); ?></p>

		<?php if ( self::as_heartbeat_stale() ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( "Action Scheduler hasn't run recently; deliverability data may be stale.", 'scanfully' ); ?>
				</p>
			</div>
		<?php endif; ?>
		<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'DISABLE_WP_CRON is set; configure a real cron job to call wp-cron.php so the ping runs on schedule.', 'scanfully' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<ul class="scanfully-connect-details">
			<li>
				<div class="scanfully-connect-details-label"><?php esc_html_e( 'Status', 'scanfully' ); ?></div>
				<div class="scanfully-connect-details-value"><span
						class="<?php echo esc_attr( $blob_class ); ?>"><?php echo esc_html( $badge_label ); ?></span></div>
			</li>
			<?php
			$since_fmt = is_array( $state ) && ! empty( $state['since'] ) ? self::format_utc_date( (string) $state['since'] ) : '';
			$last_eval_fmt = is_array( $state ) && ! empty( $state['last_evaluated_at'] ) ? self::format_utc_date( (string) $state['last_evaluated_at'] ) : '';
			?>
			<?php if ( '-' !== $since_fmt && '' !== $since_fmt ) : ?>
				<li>
					<div class="scanfully-connect-details-label"><?php esc_html_e( 'Since', 'scanfully' ); ?></div>
					<div class="scanfully-connect-details-value"><span
							class="scanfully-connect-blob"><?php echo esc_html( $since_fmt ); ?></span></div>
				</li>
			<?php endif; ?>
			<?php if ( '-' !== $last_eval_fmt && '' !== $last_eval_fmt ) : ?>
				<li>
					<div class="scanfully-connect-details-label"><?php esc_html_e( 'Last evaluated', 'scanfully' ); ?></div>
					<div class="scanfully-connect-details-value"><span
							class="scanfully-connect-blob"><?php echo esc_html( $last_eval_fmt ); ?></span></div>
				</li>
			<?php endif; ?>
			<?php if ( is_array( $state ) && ! empty( $state['last_latency_ms'] ) ) : ?>
				<li>
					<div class="scanfully-connect-details-label"><?php esc_html_e( 'Last ping latency', 'scanfully' ); ?></div>
					<div class="scanfully-connect-details-value"><span
							class="scanfully-connect-blob"><?php echo esc_html( self::format_latency( (int) $state['last_latency_ms'] ) ); ?></span>
					</div>
				</li>
			<?php endif; ?>
			<?php if ( is_array( $state ) && ! empty( $state['transport_hint'] ) ) : ?>
				<li>
					<div class="scanfully-connect-details-label"><?php esc_html_e( 'Mail transport', 'scanfully' ); ?></div>
					<div class="scanfully-connect-details-value"><span
							class="scanfully-connect-blob"><?php echo esc_html( (string) $state['transport_hint'] ); ?></span></div>
				</li>
			<?php endif; ?>
			<li>
				<div class="scanfully-connect-details-label"><?php esc_html_e( 'Check interval', 'scanfully' ); ?></div>
				<div class="scanfully-connect-details-value"><span
						class="scanfully-connect-blob"><?php echo esc_html( self::format_interval( self::current_interval_seconds() ) ); ?></span>
				</div>
			</li>
			<?php if ( false !== $next ) : ?>
				<li>
					<div class="scanfully-connect-details-label"><?php esc_html_e( 'Next scheduled run', 'scanfully' ); ?></div>
					<div class="scanfully-connect-details-value"><span
							class="scanfully-connect-blob"><?php echo esc_html( self::format_utc_date( gmdate( 'Y-m-d\TH:i:s\Z', (int) $next ) ) ); ?></span>
					</div>
				</li>
			<?php endif; ?>

			<li>
				<div class="scanfully-connect-details-label"><?php esc_html_e( 'From address', 'scanfully' ); ?></div>
				<div class="scanfully-connect-details-value"><span
						class="scanfully-connect-blob"><?php echo esc_html( $from_address ); ?></span></div>
			</li>

		</ul>
		<div class="scanfully-connect-button-wrapper">
			<button type="button" class="button button-secondary" id="scanfully-run-now"
				data-nonce="<?php echo esc_attr( $nonce ); ?>" data-action="<?php echo esc_attr( self::AJAX_RUN_NOW ); ?>"
				data-ajaxurl="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
				<?php esc_html_e( 'Run check now', 'scanfully' ); ?>
			</button>
			<span class="description"
				style="display:block;margin-top:4px;"><?php esc_html_e( 'Sends a test ping. Results appear within ~30 minutes.', 'scanfully' ); ?></span>
		</div>

		<h3><?php esc_html_e( 'Sender address', 'scanfully' ); ?></h3>
		<p class="description" style="max-width:640px;">
			<?php esc_html_e( "By default the site's administration email address is used as the From address for deliverability pings. Some mail providers (e.g. SendGrid, Mailgun) reject messages whose From address is on a different domain than the website. If that applies to your setup, override the From address with one on a domain that your mail provider accepts.", 'scanfully' ); ?>
		</p>
		<?php if ( 'ok' === $from_notice ) : ?>
			<div class="notice notice-success inline">
				<p><?php esc_html_e( 'From address saved.', 'scanfully' ); ?></p>
			</div>
		<?php elseif ( 'invalid' === $from_notice ) : ?>
			<div class="notice notice-error inline">
				<p><?php esc_html_e( 'Please enter a valid email address.', 'scanfully' ); ?></p>
			</div>
		<?php elseif ( 'loop' === $from_notice ) : ?>
			<div class="notice notice-error inline">
				<p><?php esc_html_e( 'That domain is reserved by Scanfully and cannot be used as the From address.', 'scanfully' ); ?>
				</p>
			</div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			style="margin-top:8px;max-width:640px;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ADMIN_POST_SAVE_FROM ); ?>" />
			<?php wp_nonce_field( self::NONCE_SAVE_FROM ); ?>
			<p>
				<label for="scanfully-email-from-address"
					style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'From address override', 'scanfully' ); ?></label>
				<input type="email" id="scanfully-email-from-address" name="scanfully_from_address"
					value="<?php echo esc_attr( $from_override ); ?>"
					placeholder="<?php echo esc_attr( $from_placeholder ); ?>" class="regular-text" />
				<span class="description" style="display:block;margin-top:4px;">
					<?php
					/* translators: %s: site's admin email address. */
					echo esc_html( sprintf( __( 'Leave empty to use the site administration email (%s).', 'scanfully' ), (string) get_bloginfo( 'admin_email' ) ) );
					?>
				</span>
			</p>
			<p>
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Save From address', 'scanfully' ); ?>
				</button>
			</p>
		</form>
		<script>
			(function () {
				var btn = document.getElementById('scanfully-run-now');
				if (!btn) return;
				btn.addEventListener('click', function () {
					btn.disabled = true;
					var fd = new FormData();
					fd.append('action', btn.dataset.action);
					fd.append('_wpnonce', btn.dataset.nonce);
					fetch(btn.dataset.ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
						.then(function (r) { return r.json().catch(function () { return { success: false, data: { message: 'Bad response' } }; }); })
						.then(function (j) {
							var msg = (j && j.data && j.data.message) ? j.data.message : (j && j.success ? 'Scheduled' : 'Failed');
							alert(msg);
						})
						.finally(function () { setTimeout(function () { btn.disabled = false; }, 2000); });
				});
			})();
		</script>
		<?php
	}

	/**
	 * AJAX handler for "Run check now". Schedules a single AS action with
	 * source=manual, gated by a 60s server-side rate limit.
	 *
	 * @return void
	 */
	public static function handle_ajax_run_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized', 'scanfully' ) ], 403 );
		}
		check_ajax_referer( self::AJAX_RUN_NOW );

		$lock = get_transient( self::RUN_NOW_LOCK_KEY );
		if ( false !== $lock ) {
			wp_send_json_error(
				[
					'message' => __( 'Please wait a moment before running another check.', 'scanfully' ),
					'retry_after' => self::RUN_NOW_LOCK_SECONDS,
				],
				429
			);
		}
		set_transient( self::RUN_NOW_LOCK_KEY, 1, self::RUN_NOW_LOCK_SECONDS );

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			wp_send_json_error( [ 'message' => __( 'Action Scheduler is not available.', 'scanfully' ) ], 500 );
		}

		as_schedule_single_action(
			time(),
			CronController::ACTION_EMAIL_DELIVERABILITY_PING,
			[ 'source' => 'manual' ],
			'scanfully'
		);

		wp_send_json_success(
			[
				'message' => __( 'Check scheduled. Results appear in the Activity log within ~30 minutes.', 'scanfully' ),
			]
		);
	}

	/**
	 * admin-post handler for saving the From-address override. Empty input
	 * clears the override, restoring the admin_email fallback.
	 *
	 * @return void
	 */
	public static function handle_save_from_address(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'scanfully' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_SAVE_FROM );

		$raw = isset( $_POST['scanfully_from_address'] ) ? wp_unslash( $_POST['scanfully_from_address'] ) : '';
		$value = trim( sanitize_email( (string) $raw ) );

		$status = 'ok';
		if ( '' === trim( (string) $raw ) ) {
			// Empty input -> clear the override.
			OptionController::set_option( 'email_deliverability_from_address', '', false );
		} elseif ( '' === $value || ! is_email( $value ) ) {
			$status = 'invalid';
		} elseif ( self::is_email_loopable( $value ) ) {
			$status = 'loop';
		} else {
			OptionController::set_option( 'email_deliverability_from_address', $value, false );
		}

		$redirect = add_query_arg(
			[
				'page' => 'scanfully',
				'scanfully_from_saved' => $status,
			],
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}
}
