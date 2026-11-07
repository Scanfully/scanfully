<?php
/**
 * The OAuth client class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Connect;

use Scanfully\Main;

/**
 * The plugin as a client of Scanfully's OAuth server: the site's admin
 * approves on the Scanfully dashboard what Scanfully may do on the site, the
 * plugin gets an access and a refresh token bound to the site, and refreshes
 * them at the token endpoint.
 *
 * The client is public (the plugin is open source, so a secret could not be
 * kept): what makes a connection the site owner's is their approval in
 * Scanfully, the code going back to this site only, and PKCE.
 */
class OAuth {

	/**
	 * The plugin's client id on Scanfully's OAuth server.
	 */
	public const CLIENT_ID = 'wordpress-plugin';

	/**
	 * What every connection allows: the plugin reports site data.
	 */
	public const SCOPE_READ = 'wordpress:read';

	/**
	 * Scanfully Manage: Scanfully may update, install, activate, deactivate and remove
	 * plugins and themes, and update WordPress and translations. A connection gains it from
	 * the dashboard; the plugin does not ask for it.
	 */
	public const SCOPE_MANAGE = 'wordpress:manage';

	/**
	 * Scanfully Manage: Scanfully may sign a Scanfully user in to WP admin. A connection
	 * gains it from the dashboard; the plugin does not ask for it.
	 */
	public const SCOPE_LOGIN = 'wordpress:login';

	/**
	 * The scopes the plugin asks for. More come with the features that use them.
	 */
	public const SCOPES = [ self::SCOPE_READ ];

	/**
	 * The query parameter that marks the OAuth server's answer on the settings page.
	 */
	public const CALLBACK_PARAM = 'scanfully-oauth';

	/**
	 * The address the OAuth server sends the admin back to: the plugin's
	 * settings page, marked as the answer of an authorization request. The
	 * token exchange repeats it exactly.
	 *
	 * @return string
	 */
	public static function redirect_uri(): string {
		return add_query_arg( self::CALLBACK_PARAM, '1', Page::get_page_url() );
	}

	/**
	 * The address of the authorization request that starts a connection: a
	 * new state and PKCE verifier are stored for the current user.
	 *
	 * @return string
	 */
	public static function authorize_url(): string {
		$params = [
			'client_id'             => self::CLIENT_ID,
			'redirect_uri'          => self::redirect_uri(),
			'response_type'         => 'code',
			'state'                 => Controller::generate_state(),
			'code_challenge'        => Controller::code_challenge( Controller::generate_code_verifier() ),
			'code_challenge_method' => 'S256',
			'resource'              => Main::get_site_resource(),
			'scope'                 => implode( ' ', self::SCOPES ),
			// home_url() is the public-facing address (Bedrock keeps WordPress in a subdirectory).
			'site'                  => home_url(),
		];

		return Main::get_oauth_url() . '/oauth/authorize?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Exchange an authorization code for the site's tokens.
	 *
	 * @param string $code     The code from the OAuth server's answer.
	 * @param string $verifier The PKCE verifier of the request.
	 *
	 * @return array<string, mixed> The tokens (see normalize()), or an empty array on failure.
	 */
	public static function exchange_code( string $code, string $verifier ): array {
		$answer = self::post_token(
			[
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'code_verifier' => $verifier,
				'redirect_uri'  => self::redirect_uri(),
				'client_id'     => self::CLIENT_ID,
			],
			60
		);

		return null === $answer ? [] : self::normalize( $answer );
	}

	/**
	 * Refresh the site's tokens at the token endpoint. A refresh token of the
	 * legacy routes (from before this version) works there too.
	 *
	 * @param string $refresh_token The refresh token.
	 * @param string $site_id       The Scanfully site id, which keys the API's limit on failed attempts.
	 *
	 * @return array<string, mixed>|null The tokens (see normalize()), an empty array on
	 *                                   failure, or null when the API does not know the
	 *                                   plugin's client (an older API): refresh on the
	 *                                   legacy route then.
	 */
	public static function refresh( string $refresh_token, string $site_id ): ?array {
		$unknown_client = false;
		$answer         = self::post_token(
			[
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
				'client_id'     => self::CLIENT_ID,
				'site_id'       => $site_id,
			],
			15,
			$unknown_client
		);
		if ( $unknown_client ) {
			return null;
		}

		return null === $answer ? [] : self::normalize( $answer );
	}

	/**
	 * The tokens of a token answer, in the shape the plugin stores: the
	 * access and refresh token, when the access token expires (a date), the
	 * site id and the granted scopes. Missing parts make it an empty array.
	 *
	 * @param array<string, mixed> $answer The decoded token answer.
	 *
	 * @return array<string, mixed>
	 */
	public static function normalize( array $answer ): array {
		foreach ( [ 'access_token', 'refresh_token' ] as $key ) {
			if ( ! isset( $answer[ $key ] ) || ! is_string( $answer[ $key ] ) || '' === $answer[ $key ] ) {
				return [];
			}
		}
		if ( ! isset( $answer['expires_in'] ) || ! is_numeric( $answer['expires_in'] ) || (int) $answer['expires_in'] <= 0 ) {
			return [];
		}
		$scopes = isset( $answer['scope'] ) && is_string( $answer['scope'] ) ? array_values( array_filter( explode( ' ', $answer['scope'] ) ) ) : [];

		return [
			'access_token'  => $answer['access_token'],
			'refresh_token' => $answer['refresh_token'],
			'expires'       => gmdate( 'c', time() + (int) $answer['expires_in'] ),
			'site_id'       => isset( $answer['site_id'] ) && is_string( $answer['site_id'] ) ? $answer['site_id'] : '',
			'scopes'        => $scopes,
		];
	}

	/**
	 * Post a form to the token endpoint.
	 *
	 * @param array<string, string> $form           The form.
	 * @param int                   $timeout        The timeout in seconds.
	 * @param bool                  $unknown_client Set when the API does not know the plugin's client.
	 *
	 * @return array<string, mixed>|null The decoded answer of a 200, or null.
	 */
	private static function post_token( array $form, int $timeout, bool &$unknown_client = false ): ?array {
		$resp = wp_remote_post(
			Main::get_oauth_url() . '/oauth/token',
			[
				'headers'     => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
				'body'        => $form,
				'timeout'     => $timeout,
				'blocking'    => true,
				'httpversion' => '1.0',
				'sslverify'   => Main::get_sslverify(),
			]
		);
		if ( is_wp_error( $resp ) ) {
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $resp );
		$body   = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $status ) {
			// an API from before the plugin's client: no OAuth server, or one
			// that does not connect sites.
			$unknown_client = 404 === $status || ( is_array( $body ) && isset( $body['error'] ) && 'invalid_client' === $body['error'] );
			return null;
		}

		return is_array( $body ) ? $body : null;
	}
}
