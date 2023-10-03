<?php

namespace Scanfully\Health;

use Scanfully\API\HealthRequest;

class Controller {

	private static function get_server_arch(): string {
		if ( function_exists( 'php_uname' ) ) {
			return sprintf( '%s %s %s', php_uname( 's' ), php_uname( 'r' ), php_uname( 'm' ) );
		}

		return 'unknown';
	}

	private static function get_php_version(): string {
		return sprintf(
			'%s %s',
			PHP_VERSION,
			( ( PHP_INT_SIZE * 8 === 64 ) ? __( 'x64' ) : __( 'x86' ) )
		);
	}

	private static function get_curl_version(): string {
		if ( function_exists( 'curl_version' ) ) {
			$curl = curl_version();

			return sprintf( '%s %s', $curl['version'], $curl['ssl_version'] );
		}

		return 'unknown';
	}

	private static function get_php_sapi(): string {
		if ( function_exists( 'php_sapi_name' ) ) {
			return php_sapi_name();
		}

		return 'unknown';
	}

	public static function send_health_request(): void {
		$request = new HealthRequest();


		$request->send_event( [
			"wp_version"           => get_bloginfo( 'version' ),
			"wp_multisite"         => is_multisite(),
			"wp_user_registration" => (bool) get_option( 'users_can_register' ),
			"wp_blog_public"       => (bool) get_option( 'blog_public' ),
			"wp_size"              => recurse_dirsize( ABSPATH, null, 30 ),
			"https"                => is_ssl(),
			"server_arch"          => self::get_server_arch(),
			"web_server"           => $_SERVER['SERVER_SOFTWARE'] ?? __( 'Unable to determine what web server software is used',
					"scanfully" ),
			"curl_version"         => self::get_curl_version(),
			"php_version"          => self::get_php_version(),
			"php_sapi"             => self::get_php_sapi(),
		] );
	}
}