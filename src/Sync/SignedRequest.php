<?php
/**
 * The signed request class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Sync;

use Scanfully\Main;

/**
 * Checks a request Scanfully signed for this site: a short JWT (RS256) that
 * names Scanfully (iss), this site (aud), the action (sub) and the scopes of
 * the site's connection, checked against the public keys Scanfully publishes.
 * Neither side keeps a shared secret: the plugin needs no token of its own to
 * tell Scanfully's requests from anyone else's.
 */
class SignedRequest {

	/**
	 * Where Scanfully publishes its public keys, on the OAuth server's host.
	 */
	public const KEYS_PATH = '/.well-known/scanfully-plugin-keys.json';

	/**
	 * Transient caching the public keys, by key id, as PEM.
	 */
	private const KEYS_TRANSIENT = 'scanfully_plugin_keys';

	/**
	 * Transient set when the keys are fetched for a key id the cache does
	 * not have, before the fetch and whether it works or not, so an unknown
	 * id or an unreachable key set costs one fetch per few minutes at most.
	 */
	private const REFETCH_TRANSIENT = 'scanfully_plugin_keys_refetched';

	/**
	 * Seconds between two fetches of the key set.
	 */
	private const REFETCH_WAIT = 5 * MINUTE_IN_SECONDS;

	/**
	 * Seconds of clock difference the times of a request may have.
	 */
	public const LEEWAY = 5 * MINUTE_IN_SECONDS;

	/**
	 * Whether a bearer token has the form of a signed request: three
	 * base64url parts, the first a JSON object.
	 *
	 * @param string $token The bearer token.
	 *
	 * @return bool
	 */
	public static function looks_signed( string $token ): bool {
		return 0 === strpos( $token, 'eyJ' ) && 2 === substr_count( $token, '.' );
	}

	/**
	 * Check a signed request for this site and action.
	 *
	 * @param string   $token   The bearer token.
	 * @param string   $site_id The site's Scanfully id.
	 * @param string   $action  The action the route takes, such as sync.
	 * @param string   $scope   The scope the action needs.
	 * @param int|null $now     The time to check against.
	 *
	 * @return bool
	 */
	public static function verify( string $token, string $site_id, string $action, string $scope, ?int $now = null ): bool {
		$now    = $now ?? time();
		$claims = '' === $site_id ? null : self::claims( $token );
		if ( null === $claims ) {
			return false;
		}

		$audience = $claims['aud'] ?? null;
		$audience = is_string( $audience ) ? [ $audience ] : ( is_array( $audience ) ? $audience : [] );
		$scopes   = isset( $claims['scope'] ) && is_string( $claims['scope'] ) ? explode( ' ', $claims['scope'] ) : [];
		if ( ( $claims['iss'] ?? '' ) !== Main::get_oauth_url()
			|| ! in_array( $site_id, $audience, true )
			|| ( $claims['sub'] ?? '' ) !== $action
			|| ! in_array( $scope, $scopes, true )
			|| ! isset( $claims['exp'] ) || ! is_numeric( $claims['exp'] ) || (int) $claims['exp'] < $now - self::LEEWAY
			|| ( isset( $claims['nbf'] ) && is_numeric( $claims['nbf'] ) && (int) $claims['nbf'] > $now + self::LEEWAY )
			|| ! isset( $claims['jti'] ) || ! is_string( $claims['jti'] ) || '' === $claims['jti'] ) {
			return false;
		}

		// each request once: a copy of it, seen in a log, does nothing.
		$seen = 'scanfully_jti_' . md5( $claims['jti'] );
		if ( false !== get_transient( $seen ) ) {
			return false;
		}
		set_transient( $seen, 1, max( 60, (int) $claims['exp'] - $now + self::LEEWAY ) );

		return true;
	}

	/**
	 * The claims of a token Scanfully signed: an RS256 JWT whose signature
	 * checks out against one of Scanfully's published keys. Only the
	 * signature is checked here; what the claims must say is the caller's.
	 *
	 * @param string $token The token.
	 *
	 * @return array<string, mixed>|null The claims, or null when the token is not one Scanfully signed.
	 */
	public static function claims( string $token ): ?array {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) || ! function_exists( 'openssl_verify' ) ) {
			return null;
		}
		$header    = json_decode( self::b64url_decode( $parts[0] ), true );
		$claims    = json_decode( self::b64url_decode( $parts[1] ), true );
		$signature = self::b64url_decode( $parts[2] );
		if ( ! is_array( $header ) || ! is_array( $claims ) || '' === $signature ) {
			return null;
		}
		if ( ( $header['alg'] ?? '' ) !== 'RS256' || ! isset( $header['kid'] ) || ! is_string( $header['kid'] ) ) {
			return null;
		}
		$pem = self::key( $header['kid'] );
		if ( null === $pem || 1 !== openssl_verify( $parts[0] . '.' . $parts[1], $signature, $pem, OPENSSL_ALGO_SHA256 ) ) {
			return null;
		}

		return $claims;
	}

	/**
	 * The public key with the id, as PEM, from the cached key set, or from a
	 * fresh one when the cache does not have it.
	 *
	 * @param string $kid The key id.
	 *
	 * @return string|null
	 */
	private static function key( string $kid ): ?string {
		$keys = get_transient( self::KEYS_TRANSIENT );
		if ( is_array( $keys ) && isset( $keys[ $kid ] ) ) {
			return $keys[ $kid ];
		}
		// One fetch per few minutes at most, whether it works or not: the
		// marker is set before the fetch, so a stream of requests with
		// unknown key ids (or an unreachable key set) costs no outbound
		// request each.
		if ( false !== get_transient( self::REFETCH_TRANSIENT ) ) {
			return null;
		}
		set_transient( self::REFETCH_TRANSIENT, 1, self::REFETCH_WAIT );
		$keys = self::fetch_keys();
		if ( null === $keys ) {
			return null;
		}
		set_transient( self::KEYS_TRANSIENT, $keys, DAY_IN_SECONDS );

		return $keys[ $kid ] ?? null;
	}

	/**
	 * Fetch Scanfully's public keys.
	 *
	 * @return array<string, string>|null The keys by id, as PEM, or null when they could not be read.
	 */
	private static function fetch_keys(): ?array {
		$resp = wp_remote_get(
			Main::get_oauth_url() . self::KEYS_PATH,
			[
				'timeout'   => 5,
				'sslverify' => Main::get_sslverify(),
			]
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}

		return self::parse_keys( (string) wp_remote_retrieve_body( $resp ) );
	}

	/**
	 * The RSA signing keys of a JWK set, by id, as PEM.
	 *
	 * @param string $json The key set.
	 *
	 * @return array<string, string>|null
	 */
	public static function parse_keys( string $json ): ?array {
		$set = json_decode( $json, true );
		if ( ! is_array( $set ) || ! isset( $set['keys'] ) || ! is_array( $set['keys'] ) ) {
			return null;
		}
		$keys = [];
		foreach ( $set['keys'] as $jwk ) {
			if ( ! is_array( $jwk ) || ( $jwk['kty'] ?? '' ) !== 'RSA' || ! isset( $jwk['kid'], $jwk['n'], $jwk['e'] )
				|| ! is_string( $jwk['kid'] ) || ! is_string( $jwk['n'] ) || ! is_string( $jwk['e'] ) ) {
				continue;
			}
			$keys[ $jwk['kid'] ] = self::rsa_pem( self::b64url_decode( $jwk['n'] ), self::b64url_decode( $jwk['e'] ) );
		}

		return $keys;
	}

	/**
	 * A public key as PEM from its modulus and exponent (a SubjectPublicKeyInfo in DER).
	 *
	 * @param string $modulus  The modulus, big-endian bytes.
	 * @param string $exponent The exponent, big-endian bytes.
	 *
	 * @return string
	 */
	public static function rsa_pem( string $modulus, string $exponent ): string {
		$rsa       = self::der( 0x30, self::der_integer( $modulus ) . self::der_integer( $exponent ) );
		$algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"; // rsaEncryption, no parameters.
		$spki      = self::der( 0x30, $algorithm . self::der( 0x03, "\x00" . $rsa ) );

		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PEM is base64.
	}

	/**
	 * A DER integer: positive, so a leading 0 when the high bit is set.
	 *
	 * @param string $bytes Big-endian bytes.
	 *
	 * @return string
	 */
	private static function der_integer( string $bytes ): string {
		$bytes = ltrim( $bytes, "\x00" );
		if ( '' === $bytes || ord( $bytes[0] ) > 0x7f ) {
			$bytes = "\x00" . $bytes;
		}

		return self::der( 0x02, $bytes );
	}

	/**
	 * A DER element of a tag and its content.
	 *
	 * @param int    $tag     The tag.
	 * @param string $content The content.
	 *
	 * @return string
	 */
	private static function der( int $tag, string $content ): string {
		$length = strlen( $content );
		if ( $length < 0x80 ) {
			return chr( $tag ) . chr( $length ) . $content;
		}
		$encoded = ltrim( pack( 'N', $length ), "\x00" );

		return chr( $tag ) . chr( 0x80 | strlen( $encoded ) ) . $encoded . $content;
	}

	/**
	 * Decode base64url without padding.
	 *
	 * @param string $value The encoded value.
	 *
	 * @return string The bytes, or an empty string when it is not base64url.
	 */
	private static function b64url_decode( string $value ): string {
		$decoded = base64_decode( strtr( $value, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWTs are base64url.

		return false === $decoded ? '' : $decoded;
	}
}
