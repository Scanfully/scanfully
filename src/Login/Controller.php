<?php
/**
 * The login controller class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Login;

use Scanfully\Connect;
use Scanfully\Main;
use Scanfully\Options\Controller as OptionsController;
use Scanfully\Sync\SignedRequest;

/**
 * One-click WP admin access: a Scanfully user opens a sign-in link,
 * https://site/?scanfully-login=<jwt>, and lands in wp-admin as the
 * administrator the site's settings in Scanfully name.
 *
 * The link's token is a signed request like every other one Scanfully
 * sends: an RS256 JWT checked against Scanfully's published keys by the
 * same code that checks the pokes and the job tokens (SignedRequest), with
 * no shared secret and no other way in. It names Scanfully (iss), this site
 * (aud), the address the link was built for (host), the purpose (sub
 * login), one use (jti), a life of 60 seconds (iat, exp), the Scanfully user
 * (uid, name), how they asked (via) and the WordPress user (wp_user). The
 * plugin checks, in this order, and stops at the first that fails:
 *
 * 1. the request is a GET (a HEAD from a link previewer neither burns the
 *    link, counts against the limit nor writes the log);
 * 2. the request came over HTTPS;
 * 3. the token has the form of a JWT and is not too long;
 * 4. the signature checks out against Scanfully's key with the header's
 *    kid (RS256);
 * 5. iss is Scanfully;
 * 6. aud names this site and nothing else, and this is the site it was
 *    connected at (not a copy);
 * 7. host is this site's address now (Connect\Address::current(), the form
 *    Connect\Address::normalize() gives), so a link Scanfully sent to
 *    another address (a domain changed in Scanfully) is worthless here;
 * 8. fewer than LIMIT links that passed 7 were opened on the site this
 *    minute (beyond it, nothing below runs and a burst is logged once). A
 *    link refused at 2 to 7 is not Scanfully's, cannot be told to be, or is
 *    not meant for this site at this address (a token minted for another
 *    site is signed too): it never counts against this limit, so nobody
 *    can keep this site's real links out, and it has a counter of its own
 *    that only limits the log, LIMIT lines a minute and one for a burst
 *    beyond them. Checks 2 to 7 read the request, the key set and a few
 *    options, and touch no burn list;
 * 9. sub is login, so no other signed request works here;
 * 10. exp has not passed, with SKEW seconds of clock skew, iat is not in
 *     the future, and the token lives no longer than LIFETIME seconds;
 * 11. the claims a login needs are there: jti, uid, wp_user, name and via;
 * 12. the jti was not seen before: it is burned now, on first sight, so a
 *     token refused below cannot be tried again either;
 * 13. the plugin's own copy of the grant holds wordpress:login;
 * 14. wp_user exists and is an administrator (a super admin on a network).
 *
 * Then it sets the WordPress auth cookie for that user (not remember me),
 * fires scanfully_login_signed_in (the ScanfullyLogin activity event) and
 * redirects 302 to wp-admin, so the token leaves the address bar. Any
 * failure ends in the same plain 403 page, whatever the reason; the reason
 * goes to the plugin's log (Log). No response, log line or header ever
 * carries the token, and nothing of a password, application password,
 * cookie or session leaves the site.
 */
final class Controller {

	/**
	 * The query parameter of a sign-in link.
	 */
	public const PARAM = 'scanfully-login';

	/**
	 * The purpose a login token names (sub).
	 */
	public const SUB = 'login';

	/**
	 * Seconds of clock difference a login token's times may have.
	 */
	public const SKEW = 30;

	/**
	 * The longest a login token may live, from iat to exp.
	 */
	public const LIFETIME = 60;

	/**
	 * The longest token the plugin looks at.
	 */
	public const MAX_TOKEN_LENGTH = 4096;

	/**
	 * The longest jti the plugin accepts.
	 */
	public const MAX_JTI_LENGTH = 128;

	/**
	 * The longest name of the Scanfully user the token may carry.
	 */
	public const MAX_NAME_LENGTH = 120;

	/**
	 * How the Scanfully user asked for the link.
	 */
	public const VIA = [ 'dashboard', 'api_token', 'mcp' ];

	/**
	 * Hook: a Scanfully user was signed in, with the WP_User and the login
	 * (name, via).
	 */
	public const ACTION_SIGNED_IN = 'scanfully_login_signed_in';

	/**
	 * The init priority: the first after Action Scheduler is ready (1), so
	 * the activity event can be queued; WordPress has set up the users and
	 * the auth cookie functions, and the theme has sent nothing yet.
	 */
	public const PRIORITY = 2;

	/**
	 * The number of the last signed link in its minute; 0 when the last
	 * check stopped before it was counted.
	 *
	 * @var int
	 */
	private static int $attempt = 0;

	// The reasons a link is refused, for the log; the page never says which.
	public const REASON_METHOD           = 'not_get';
	public const REASON_NOT_HTTPS        = 'not_https';
	public const REASON_RATE_LIMITED     = 'rate_limited';
	public const REASON_MALFORMED        = 'malformed';
	public const REASON_KEYS_UNAVAILABLE = 'keys_unavailable';
	public const REASON_SIGNATURE        = 'bad_signature';
	public const REASON_ISSUER           = 'wrong_issuer';
	public const REASON_AUDIENCE         = 'wrong_site';
	public const REASON_SITE_MOVED       = 'site_moved';
	public const REASON_ADDRESS          = 'wrong_address';
	public const REASON_PURPOSE          = 'not_a_login_token';
	public const REASON_EXPIRED          = 'expired';
	public const REASON_NOT_YET_VALID    = 'not_yet_valid';
	public const REASON_LIFETIME         = 'lives_too_long';
	public const REASON_CLAIMS           = 'claims_missing';
	public const REASON_REPLAYED         = 'replayed';
	public const REASON_BURN_LIST_FULL   = 'burn_list_full';
	public const REASON_BURN_LIST_BUSY   = 'burn_list_busy';
	public const REASON_SCOPE            = 'scope_missing';
	public const REASON_USER             = 'not_an_administrator';

	/**
	 * Logged once a minute, in place of the refusals beyond the LIMIT of
	 * links that were not Scanfully's, could not be told to be, or were not
	 * meant for this site at this address.
	 */
	public const REASON_UNSIGNED_BURST = 'unsigned_burst';

	/**
	 * The reasons of a link refused before the rate limit: not Scanfully's,
	 * or not meant for this site at this address. They count only against
	 * the log's own limit, never against the rate limit.
	 */
	private const UNCOUNTED_REASONS = [
		self::REASON_NOT_HTTPS,
		self::REASON_MALFORMED,
		self::REASON_KEYS_UNAVAILABLE,
		self::REASON_SIGNATURE,
		self::REASON_ISSUER,
		self::REASON_AUDIENCE,
		self::REASON_SITE_MOVED,
		self::REASON_ADDRESS,
	];

	/**
	 * Hook the link.
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_action( 'init', [ self::class, 'maybe_handle' ], self::PRIORITY );
	}

	/**
	 * Whether the site can sign a Scanfully user in, as the capabilities
	 * report says (the login action): it is served over HTTPS (its home and
	 * site address) and the plugin's copy of the grant holds
	 * wordpress:login.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return wp_is_using_https() && self::granted();
	}

	/**
	 * Whether the plugin's own copy of the grant holds wordpress:login, on a
	 * connection that lives on Scanfully's OAuth server.
	 *
	 * @return bool
	 */
	private static function granted(): bool {
		return Connect\Scopes::applies() && in_array( Connect\OAuth::SCOPE_LOGIN, Connect\Controller::granted_scopes(), true );
	}

	/**
	 * Handle a request that carries a sign-in link, and end it; leave every
	 * other request alone.
	 *
	 * @return void
	 */
	public static function maybe_handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A signed token, checked below; not a form.
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked against the JWT form in attempt(), never output.
		$raw    = wp_unslash( $_GET[ self::PARAM ] );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		$result = self::attempt( is_string( $raw ) ? $raw : '', $method, is_ssl(), time() );

		if ( $result['user'] instanceof \WP_User ) {
			self::sign_in( $result['user'], $result['login'] );
		}
		self::refuse();
	}

	/**
	 * Check a sign-in link, in the order the class describes, and log a
	 * refusal.
	 *
	 * @param string $token  The token from the link.
	 * @param string $method The request method.
	 * @param bool   $https  Whether the request came over HTTPS.
	 * @param int    $now    The time.
	 *
	 * @return array{reason: string, user: \WP_User|null, login: array{name: string, via: string}} The user to sign in, or the reason the link is refused.
	 */
	public static function attempt( string $token, string $method, bool $https, int $now ): array {
		$login  = [
			'name' => '',
			'via'  => '',
		];
		$reason = self::check( $token, $method, $https, $now, $login, $user );
		if ( '' !== $reason ) {
			self::log( $reason, $now );

			return [
				'reason' => $reason,
				'user'   => null,
				'login'  => $login,
			];
		}

		return [
			'reason' => '',
			'user'   => $user,
			'login'  => $login,
		];
	}

	/**
	 * Log a refusal, within the limits: a HEAD or POST (a link previewer) is
	 * not logged; a burst of signed links beyond the limit logs once, at its
	 * first refused link; links refused before the rate limit (not
	 * Scanfully's, or not meant for this site at this address) log LIMIT
	 * lines a minute, then one line for the rest of the burst. No request
	 * writes the log beyond that.
	 *
	 * @param string $reason The reason.
	 * @param int    $now    The time.
	 *
	 * @return void
	 */
	private static function log( string $reason, int $now ): void {
		if ( self::REASON_METHOD === $reason ) {
			return;
		}
		if ( self::REASON_RATE_LIMITED === $reason ) {
			if ( Attempts::LIMIT + 1 === self::$attempt ) {
				Log::refused( $reason, $now );
			}
			return;
		}
		if ( in_array( $reason, self::UNCOUNTED_REASONS, true ) ) {
			$unsigned = Attempts::take( $now, Attempts::UNSIGNED_TRANSIENT );
			if ( $unsigned <= Attempts::LIMIT ) {
				Log::refused( $reason, $now );
			} elseif ( Attempts::LIMIT + 1 === $unsigned ) {
				Log::refused( self::REASON_UNSIGNED_BURST, $now );
			}
			return;
		}
		Log::refused( $reason, $now );
	}

	/**
	 * The checks.
	 *
	 * @param string                           $token  The token.
	 * @param string                           $method The request method.
	 * @param bool                             $https  Whether the request came over HTTPS.
	 * @param int                              $now    The time.
	 * @param array{name: string, via: string} $login  Set to the login's name and via.
	 * @param \WP_User|null                    $user   Set to the user to sign in.
	 *
	 * @return string The reason the link is refused, or '' when it checks out.
	 */
	private static function check( string $token, string $method, bool $https, int $now, array &$login, ?\WP_User &$user = null ): string {
		$user          = null;
		self::$attempt = 0;
		if ( 'GET' !== $method ) {
			return self::REASON_METHOD;
		}
		if ( ! $https ) {
			return self::REASON_NOT_HTTPS;
		}
		if ( strlen( $token ) > self::MAX_TOKEN_LENGTH || 1 !== preg_match( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $token ) || ! SignedRequest::looks_signed( $token ) ) {
			return self::REASON_MALFORMED;
		}

		// The one verification path of every signed request: RS256, the kid
		// in Scanfully's published key set, the signature.
		$claims = SignedRequest::claims( $token );
		if ( null === $claims ) {
			return SignedRequest::keys_unavailable() ? self::REASON_KEYS_UNAVAILABLE : self::REASON_SIGNATURE;
		}
		if ( ( $claims['iss'] ?? '' ) !== Main::get_oauth_url() ) {
			return self::REASON_ISSUER;
		}
		if ( ! self::names_only( $claims['aud'] ?? null, (string) OptionsController::get_options()->site_id ) ) {
			return self::REASON_AUDIENCE;
		}
		if ( Connect\Address::moved() ) {
			return self::REASON_SITE_MOVED;
		}
		if ( ! self::names_this_address( $claims['host'] ?? null ) ) {
			return self::REASON_ADDRESS;
		}
		// Only a link Scanfully signed for this site at this address counts
		// against the limit; beyond it nothing below runs (no further claim
		// read, no burn list touched).
		self::$attempt = Attempts::take( $now );
		if ( self::$attempt > Attempts::LIMIT ) {
			return self::REASON_RATE_LIMITED;
		}
		if ( ( $claims['sub'] ?? '' ) !== self::SUB ) {
			return self::REASON_PURPOSE;
		}

		$times = self::check_times( $claims, $now );
		if ( '' !== $times ) {
			return $times;
		}
		$token_login = self::login_claims( $claims );
		if ( null === $token_login ) {
			return self::REASON_CLAIMS;
		}

		$burn = Burns::burn( $token_login['jti'], $token_login['exp'], $now );
		if ( Burns::BURNED !== $burn ) {
			return Burns::SEEN === $burn ? self::REASON_REPLAYED : ( Burns::FULL === $burn ? self::REASON_BURN_LIST_FULL : self::REASON_BURN_LIST_BUSY );
		}
		if ( ! self::granted() ) {
			return self::REASON_SCOPE;
		}

		$candidate = get_userdata( $token_login['wp_user'] );
		if ( ! $candidate instanceof \WP_User || ! Administrators::is_administrator( $candidate ) ) {
			return self::REASON_USER;
		}

		$user  = $candidate;
		$login = [
			'name' => $token_login['name'],
			'via'  => $token_login['via'],
		];

		return '';
	}

	/**
	 * Check a token's times: exp has not passed (with the skew), iat is not
	 * in the future (with the skew), nbf if present has come, and the token
	 * lives no longer than LIFETIME.
	 *
	 * @param array<string, mixed> $claims The claims.
	 * @param int                  $now    The time.
	 *
	 * @return string The reason, or ''.
	 */
	private static function check_times( array $claims, int $now ): string {
		$exp = $claims['exp'] ?? null;
		$iat = $claims['iat'] ?? null;
		if ( ! is_int( $exp ) || ! is_int( $iat ) ) {
			return self::REASON_CLAIMS;
		}
		if ( $exp < $now - self::SKEW ) {
			return self::REASON_EXPIRED;
		}
		if ( $iat > $now + self::SKEW || ( isset( $claims['nbf'] ) && ( ! is_int( $claims['nbf'] ) || $claims['nbf'] > $now + self::SKEW ) ) ) {
			return self::REASON_NOT_YET_VALID;
		}
		if ( $exp < $iat || $exp - $iat > self::LIFETIME ) {
			return self::REASON_LIFETIME;
		}

		return '';
	}

	/**
	 * What a login token carries for a login, when it is all there: a jti,
	 * the Scanfully user (uid, and a name of at most MAX_NAME_LENGTH
	 * characters), how they asked (via) and the WordPress user (wp_user, an
	 * id).
	 *
	 * @param array<string, mixed> $claims The claims.
	 *
	 * @return array{jti: string, exp: int, wp_user: int, name: string, via: string}|null
	 */
	private static function login_claims( array $claims ): ?array {
		$jti     = $claims['jti'] ?? null;
		$exp     = $claims['exp'] ?? null;
		$uid     = $claims['uid'] ?? null;
		$wp_user = $claims['wp_user'] ?? null;
		$name    = $claims['name'] ?? null;
		$via     = $claims['via'] ?? null;
		if ( ! is_string( $jti ) || '' === $jti || strlen( $jti ) > self::MAX_JTI_LENGTH
			|| ! is_int( $exp )
			|| ! ( ( is_string( $uid ) && '' !== $uid ) || ( is_int( $uid ) && $uid > 0 ) )
			|| ! is_int( $wp_user ) || $wp_user < 1
			|| ! is_string( $name ) || '' === trim( $name ) || mb_strlen( $name ) > self::MAX_NAME_LENGTH
			|| ! is_string( $via ) || ! in_array( $via, self::VIA, true ) ) {
			return null;
		}

		return [
			'jti'     => $jti,
			'exp'     => $exp,
			'wp_user' => $wp_user,
			'name'    => $name,
			'via'     => $via,
		];
	}

	/**
	 * Whether an audience names this site and nothing else: the site id as a
	 * string, or a list holding only it.
	 *
	 * @param mixed  $audience The aud claim.
	 * @param string $site_id  The site's Scanfully id.
	 *
	 * @return bool
	 */
	private static function names_only( $audience, string $site_id ): bool {
		if ( '' === $site_id ) {
			return false;
		}
		if ( is_string( $audience ) ) {
			return $audience === $site_id;
		}

		return is_array( $audience ) && [ $site_id ] === array_values( $audience );
	}

	/**
	 * Whether a host claim is this site's address now: a non-empty string
	 * equal to Connect\Address::current(). Scanfully puts the address it
	 * built the link for into the token, in the same normalized form; a link
	 * sent to another host and replayed here does not match.
	 *
	 * @param mixed $host The host claim.
	 *
	 * @return bool
	 */
	private static function names_this_address( $host ): bool {
		$current = Connect\Address::current();

		return is_string( $host ) && '' !== $host && '' !== $current && hash_equals( $current, $host );
	}

	/**
	 * The headers of every answer to a sign-in link: never cached, never
	 * passed on as a referrer, never indexed.
	 *
	 * @return array<string, string>
	 */
	public static function headers(): array {
		return [
			'Cache-Control'   => 'no-store, no-cache, must-revalidate, max-age=0, private',
			'Referrer-Policy' => 'no-referrer',
			'X-Robots-Tag'    => 'noindex, nofollow',
		];
	}

	/**
	 * Sign the user in and send them to wp-admin.
	 *
	 * @param \WP_User                         $user  The user.
	 * @param array{name: string, via: string} $login The login.
	 *
	 * @return void
	 */
	private static function sign_in( \WP_User $user, array $login ): void {
		wp_set_auth_cookie( (int) $user->ID, false );
		wp_set_current_user( (int) $user->ID );

		/**
		 * Fires when a Scanfully user was signed in from a sign-in link.
		 *
		 * @param \WP_User                         $user  The WordPress user signed in.
		 * @param array{name: string, via: string} $login The Scanfully user's name and how they asked.
		 */
		do_action( self::ACTION_SIGNED_IN, $user, $login );

		self::send_headers();
		wp_safe_redirect( admin_url(), 302 );
		exit;
	}

	/**
	 * Answer the plain refusal.
	 *
	 * @return void
	 */
	private static function refuse(): void {
		status_header( 403 );
		self::send_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo self::refusal_page(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
		exit;
	}

	/**
	 * Send the headers of every answer.
	 *
	 * @return void
	 */
	private static function send_headers(): void {
		nocache_headers();
		foreach ( self::headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}

	/**
	 * The page of a refused link: the same for every reason, with a link to
	 * the front page and nothing of the token or the reason. A small script
	 * replaces the address with the bare path, so the token leaves the
	 * address bar and the history.
	 *
	 * @return string
	 */
	public static function refusal_page(): string {
		$title = esc_html__( 'This sign-in link is not valid', 'scanfully' );

		return '<!DOCTYPE html>'
			. '<html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head>'
			. '<meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex, nofollow">'
			. '<meta name="referrer" content="no-referrer">'
			. '<title>' . $title . '</title>'
			// Take the link out of the address bar and the history: a link
			// refused before its burn is still valid for its minute. Without
			// JavaScript the page works the same.
			. '<script>try{history.replaceState(null,"",location.pathname)}catch(e){}</script>'
			. '<style>body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f6f7f7;color:#1d2327}main{max-width:32rem;margin:15vh auto;padding:0 1rem}h1{font-size:1.5rem;font-weight:600}a{color:#2271b1}</style>'
			. '</head><body><main>'
			. '<h1>' . $title . '</h1>'
			. '<p>' . esc_html__( 'Open WP admin again from Scanfully for a new link.', 'scanfully' ) . '</p>'
			. '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Go to the front page', 'scanfully' ) . '</a></p>'
			. '</main></body></html>';
	}
}
