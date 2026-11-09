<?php
/**
 * The Manage job token class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

use Scanfully\Main;
use Scanfully\Sync\SignedRequest;

/**
 * Checks the token Scanfully signs for every job it hands out (job_token):
 * an RS256 JWT from the same key pair as the pokes that names Scanfully
 * (iss), this site (aud), the action (sub), the job (jti), the attempt, the
 * SHA-256 of the job's parameters (params_hash) and the end of the lease
 * (exp). Next to it the pull hands out the job's queue token (queue_token),
 * which Scanfully signed when the job was queued, in the same transaction
 * as the job row: it names Scanfully (iss), this site (aud), the purpose
 * (sub queued), the job (jti), the action and params_hash, and lives for the
 * delivery window and the action's longest run (exp). The plugin runs a job
 * only when both check out and agree on the job, the action and the
 * parameters: the job token says Scanfully hands this attempt to this site
 * now (a forged pull answer or a changed ledger row does not verify); the
 * queue token says Scanfully queued this action with these parameters for
 * this site (whoever can write a job row at Scanfully, but cannot sign,
 * cannot make a site run anything, because the pull signs a job token from
 * whatever the row holds).
 *
 * Neither token is burned like a poke's jti: the ledger already keeps a job
 * id to one run, and a job whose first heartbeat got no yes is checked again
 * when it is retried.
 */
final class JobToken {

	/**
	 * The token checks out.
	 */
	public const VALID = 'valid';

	/**
	 * The token is Scanfully's and names this job, but its lease has passed:
	 * the next pull brings the job again with a new attempt and token.
	 */
	public const EXPIRED = 'expired';

	/**
	 * The token could not be checked: Scanfully's key set could not be
	 * fetched, or the set fetched within the last few minutes does not have
	 * the token's key id (during a rotation it can come from a server that
	 * does not publish the new key yet). The job waits for the next pull, as
	 * an expired one does, so a key rotation does not use up its one
	 * attempt.
	 */
	public const UNVERIFIED = 'unverified';

	/**
	 * No token, or one that does not check out: the job never runs.
	 */
	public const INVALID = 'invalid';

	/**
	 * The purpose a queue token names (sub).
	 */
	public const QUEUED_SUB = 'queued';

	/**
	 * Seconds of clock difference a queue token's exp may have.
	 */
	public const QUEUE_SKEW = 30;

	/**
	 * Check a job's tokens: the per-attempt job token first (a lease that
	 * passed answers EXPIRED, so the job waits for the next pull), then the
	 * queue token, which must name the same job, action and parameters.
	 *
	 * @param array{id: string, action: string, params: array, attempt?: int, job_token?: string, queue_token?: string, params_json?: string} $job     The job as pulled.
	 * @param string                                                                                                                          $site_id The site's Scanfully id.
	 * @param int|null                                                                                                                        $now     The time.
	 *
	 * @return string One of VALID, EXPIRED, UNVERIFIED, INVALID.
	 */
	public static function check( array $job, string $site_id, ?int $now = null ): string {
		$now   = $now ?? time();
		$token = isset( $job['job_token'] ) && is_string( $job['job_token'] ) ? $job['job_token'] : '';
		if ( '' === $token || '' === $site_id || ! SignedRequest::looks_signed( $token ) ) {
			return self::INVALID;
		}
		$claims = SignedRequest::claims( $token );
		if ( null === $claims ) {
			return SignedRequest::keys_unavailable() || SignedRequest::key_unknown() ? self::UNVERIFIED : self::INVALID;
		}

		// The parameters the action gets, hashed: their canonical JSON as
		// pulled when the job keeps it, else computed from them.
		$hash = isset( $job['params_json'] ) && is_string( $job['params_json'] )
			? hash( 'sha256', $job['params_json'] )
			: self::params_hash( $job['params'] );
		if ( ( $claims['iss'] ?? '' ) !== Main::get_oauth_url()
			|| ! self::names_only( $claims['aud'] ?? null, $site_id )
			|| ( $claims['sub'] ?? '' ) !== $job['action']
			|| ( $claims['jti'] ?? '' ) !== $job['id']
			|| ! isset( $claims['attempt'] ) || ! is_int( $claims['attempt'] ) || $claims['attempt'] !== (int) ( $job['attempt'] ?? 0 )
			|| ! isset( $claims['params_hash'] ) || ! is_string( $claims['params_hash'] ) || ! hash_equals( $hash, strtolower( $claims['params_hash'] ) )
			|| ! isset( $claims['exp'] ) || ! is_int( $claims['exp'] )
			|| ( isset( $claims['nbf'] ) && is_numeric( $claims['nbf'] ) && (int) $claims['nbf'] > $now + SignedRequest::LEEWAY ) ) {
			return self::INVALID;
		}
		if ( $claims['exp'] < $now - SignedRequest::LEEWAY ) {
			return self::EXPIRED;
		}

		return self::check_queued( $job, $site_id, $claims, $hash, $now );
	}

	/**
	 * Check a job's queue token, after its job token checked out: through the
	 * same verifier, Scanfully's (iss), for this site only (aud), sub queued,
	 * jti the job's and the job token's, action and params_hash the job
	 * token's and the job's, iat when it was queued, exp not passed
	 * (QUEUE_SKEW seconds of skew). A job without one is not run.
	 *
	 * @param array                $job        The job as pulled.
	 * @param string               $site_id    The site's Scanfully id.
	 * @param array<string, mixed> $job_claims The job token's claims.
	 * @param string               $hash       The SHA-256 of the job's parameters.
	 * @param int                  $now        The time.
	 *
	 * @return string One of VALID, UNVERIFIED, INVALID.
	 */
	private static function check_queued( array $job, string $site_id, array $job_claims, string $hash, int $now ): string {
		$token = isset( $job['queue_token'] ) && is_string( $job['queue_token'] ) ? $job['queue_token'] : '';
		if ( '' === $token || ! SignedRequest::looks_signed( $token ) ) {
			return self::INVALID;
		}
		$claims = SignedRequest::claims( $token );
		if ( null === $claims ) {
			return SignedRequest::keys_unavailable() || SignedRequest::key_unknown() ? self::UNVERIFIED : self::INVALID;
		}
		$params_hash = isset( $claims['params_hash'] ) && is_string( $claims['params_hash'] ) ? strtolower( $claims['params_hash'] ) : '';
		if ( ( $claims['iss'] ?? '' ) !== Main::get_oauth_url()
			|| ! self::names_only( $claims['aud'] ?? null, $site_id )
			|| ( $claims['sub'] ?? '' ) !== self::QUEUED_SUB
			|| ( $claims['jti'] ?? '' ) !== $job['id'] || ( $claims['jti'] ?? '' ) !== ( $job_claims['jti'] ?? null )
			|| ( $claims['action'] ?? '' ) !== $job['action'] || ( $claims['action'] ?? '' ) !== ( $job_claims['sub'] ?? null )
			|| '' === $params_hash || ! hash_equals( $hash, $params_hash ) || ! hash_equals( strtolower( (string) $job_claims['params_hash'] ), $params_hash )
			|| ! isset( $claims['iat'] ) || ! is_int( $claims['iat'] )
			|| ! isset( $claims['exp'] ) || ! is_int( $claims['exp'] ) || $claims['exp'] < $now - self::QUEUE_SKEW
			|| ( isset( $claims['nbf'] ) && ( ! is_int( $claims['nbf'] ) || $claims['nbf'] > $now + self::QUEUE_SKEW ) ) ) {
			return self::INVALID;
		}

		return self::VALID;
	}

	/**
	 * The exp of a job's token, read without checking it (check() did): the
	 * end of the job's lease.
	 *
	 * @param array $job The job.
	 *
	 * @return int|null
	 */
	public static function expiry( array $job ): ?int {
		$parts = explode( '.', isset( $job['job_token'] ) && is_string( $job['job_token'] ) ? $job['job_token'] : '' );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		$claims = json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ), true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWTs are base64url.

		return is_array( $claims ) && isset( $claims['exp'] ) && is_int( $claims['exp'] ) ? $claims['exp'] : null;
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
		if ( is_string( $audience ) ) {
			return $audience === $site_id;
		}

		return is_array( $audience ) && [ $site_id ] === array_values( $audience );
	}

	/**
	 * The SHA-256, lowercase hex, of the canonical JSON of a job's
	 * parameters.
	 *
	 * @param mixed $params The parameters: an array as decoded, or the objects of the raw answer.
	 *
	 * @return string
	 */
	public static function params_hash( $params ): string {
		return hash( 'sha256', self::canonical_json( $params, true ) );
	}

	/**
	 * The canonical JSON of a value, as Scanfully hashes it: object keys
	 * sorted by their bytes at every level, lists in their order, no
	 * whitespace, UTF-8 and slashes not escaped. The parameters themselves
	 * are always an object, so an empty top level is {}; below it, an empty
	 * array decoded without objects reads as a list ([]), while the objects
	 * of the raw answer keep {} apart.
	 *
	 * @param mixed $value The value.
	 * @param bool  $top   Whether this is the top level.
	 *
	 * @return string
	 */
	public static function canonical_json( $value, bool $top = false ): string {
		if ( $value instanceof \stdClass ) {
			$value = (array) $value;
			if ( [] === $value ) {
				return '{}';
			}
			return self::canonical_object( $value );
		}
		if ( is_array( $value ) ) {
			if ( [] === $value ) {
				return $top ? '{}' : '[]';
			}
			if ( ! $top && array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
				return '[' . implode( ',', array_map( [ self::class, 'canonical_json' ], $value ) ) . ']';
			}
			return self::canonical_object( $value );
		}

		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * The canonical JSON of an object.
	 *
	 * @param array<int|string, mixed> $value The members.
	 *
	 * @return string
	 */
	private static function canonical_object( array $value ): string {
		$members = [];
		foreach ( $value as $key => $item ) {
			$members[ (string) $key ] = $item;
		}
		ksort( $members, SORT_STRING );
		$out = [];
		foreach ( $members as $key => $item ) {
			$out[] = wp_json_encode( (string) $key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ':' . self::canonical_json( $item );
		}

		return '{' . implode( ',', $out ) . '}';
	}
}
