<?php
/**
 * The failed login counter class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\FailedLogins;

use Scanfully\Events\UserEvent;
use Scanfully\Options;

/**
 * Class Counter
 *
 * Counts failed logins instead of reporting each one: bots try thousands of
 * logins a day on an ordinary site. A failure adds 1 to the hour's counters
 * (Store): the total, the reason, the way in, and for a wrong password on an
 * account with elevated rights, that account. The hourly job (Reporter) sends
 * the counts.
 *
 * Runs while a login fails, so it only classifies what WordPress has in
 * memory and writes the counters with one query. Usernames that don't exist
 * and IP addresses are never kept.
 *
 * @package Scanfully\Events\FailedLogins
 */
final class Counter {

	/**
	 * Why a login failed, by WordPress's error code. Any other code is a
	 * security plugin (or another plugin) refusing the login, or application
	 * passwords being off: `blocked`. WordPress doesn't report a login with
	 * an empty username or password as failed.
	 */
	private const REASONS = [
		'incorrect_password' => 'wrong_password',
		'invalid_username'   => 'unknown_account',
		'invalid_email'      => 'unknown_account',
	];

	/**
	 * The username or email address WordPress is checking in this request,
	 * kept in memory only, for a wrong application password (whose error
	 * doesn't name the account).
	 *
	 * @var string
	 */
	private static string $attempted = '';

	/**
	 * Whether this request already counted a failure. A login attempt is one
	 * count, also when two hooks report it (a wrong application password over
	 * XML-RPC); WordPress stops an XML-RPC multicall after its first failed
	 * login anyway.
	 *
	 * @var bool
	 */
	private static bool $counted = false;

	/**
	 * Add the hooks.
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_filter( 'authenticate', [ self::class, 'note_attempt' ], 0, 2 );
		add_action( 'wp_login_failed', [ self::class, 'note_failure' ], 10, 2 );
		add_action( 'application_password_failed_authentication', [ self::class, 'note_application_password_failure' ], 10, 1 );
		add_action( 'scanfully_options_cleared', [ Store::class, 'delete_all' ] );
	}

	/**
	 * Forget that this request counted a failure (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$counted   = false;
		self::$attempted = '';
	}

	/**
	 * `authenticate`, before WordPress checks anything: note which account
	 * is being tried. Never the password.
	 *
	 * @param mixed $user     The authentication result so far, unchanged.
	 * @param mixed $username The username or email address.
	 *
	 * @return mixed
	 */
	public static function note_attempt( $user, $username = '' ) {
		self::$attempted = is_string( $username ) ? substr( $username, 0, 200 ) : '';

		return $user;
	}

	/**
	 * `wp_login_failed`: a login failed.
	 *
	 * @param mixed $username The username or email address that was tried.
	 * @param mixed $error    Why it failed (WordPress 5.4 and later).
	 *
	 * @return void
	 */
	public static function note_failure( $username, $error = null ): void {
		self::count( (string) $username, self::error_code( $error ), self::channel() );
	}

	/**
	 * `application_password_failed_authentication`: a wrong application
	 * password (REST or XML-RPC).
	 *
	 * @param mixed $error Why it failed.
	 *
	 * @return void
	 */
	public static function note_application_password_failure( $error ): void {
		// Over the REST API the account comes from the Authorization header;
		// over XML-RPC, from the login WordPress is checking.
		$username = isset( $_SERVER['PHP_AUTH_USER'] ) && is_string( $_SERVER['PHP_AUTH_USER'] ) ? substr( wp_unslash( $_SERVER['PHP_AUTH_USER'] ), 0, 200 ) : self::$attempted; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared with stored logins and emails, never stored or sent.
		self::count( $username, self::error_code( $error ), 'app_password' );
	}

	/**
	 * Count a failure: the total, the reason, the way in, and the account
	 * for a wrong password on an account with elevated rights.
	 *
	 * @param string $username The username or email address that was tried.
	 * @param string $code     WordPress's error code.
	 * @param string $channel  The way in.
	 *
	 * @return void
	 */
	private static function count( string $username, string $code, string $channel ): void {
		if ( self::$counted || ! Options\Controller::get_options()->is_connected ) {
			return;
		}
		self::$counted = true;

		$reason   = self::reason( $code );
		$counters = [ 'total', 'r_' . $reason, 'c_' . $channel ];
		if ( 'wrong_password' === $reason ) {
			$user_id = self::elevated_account( $username );
			if ( $user_id > 0 ) {
				$counters[] = 'u_' . $user_id;
			}
		}

		Store::increment( $counters, Store::hour() );
	}

	/**
	 * The reason a login failed, from WordPress's error code.
	 *
	 * @param string $code The error code.
	 *
	 * @return string
	 */
	public static function reason( string $code ): string {
		return self::REASONS[ $code ] ?? 'blocked';
	}

	/**
	 * The way the login came in: the login page, XML-RPC, or another form
	 * (such as WooCommerce's My Account).
	 *
	 * @return string
	 */
	public static function channel(): string {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}

		return isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ? 'login_page' : 'other';
	}

	/**
	 * The code of a login error; '' without one.
	 *
	 * @param mixed $error The error.
	 *
	 * @return string
	 */
	private static function error_code( $error ): string {
		return $error instanceof \WP_Error ? (string) $error->get_error_code() : '';
	}

	/**
	 * The ID of the account a wrong password was tried on, when it has
	 * elevated rights; 0 otherwise. WordPress has just looked the account up
	 * to check the password, so this comes from the user cache.
	 *
	 * @param string $username The username or email address that was tried.
	 *
	 * @return int
	 */
	private static function elevated_account( string $username ): int {
		if ( '' === $username ) {
			return 0;
		}

		$user = get_user_by( is_email( $username ) ? 'email' : 'login', $username );

		return $user instanceof \WP_User && UserEvent::is_elevated( $user ) ? (int) $user->ID : 0;
	}
}
