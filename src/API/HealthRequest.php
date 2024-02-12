<?php
/**
 * The health request class file.
 *
 * @package Scanfully
 */

namespace Scanfully\API;

use Scanfully\Main;
use Scanfully\Options\Options;

/**
 * This class is used to send health data to the Scanfully API.
 */
class HealthRequest extends Request {

	/**
	 * Send the request to the API.
	 *
	 * @param  array $data The data to send with the request.
	 *
	 * @return void
	 */
	public function send_event( array $data ): void {
		parent::send( '', $data );
	}

	/**
	 * Get the auth headers for the request.
	 *
	 * @return array
	 */
	public function get_auth_headers(): array {
		$headers                        = [];
		$headers['X-Scanfully-Site-Id'] = Options::get_option( 'site_id' );
		$headers['X-Scanfully-Public']  = Options::get_option( 'public_key' );
		$headers['X-Scanfully-Secret']  = Options::get_option( 'secret_key' );

		return $headers;
	}

	/**
	 * Get the url for the request.
	 *
	 * @param  string $endpoint The endpoint to send the request to.
	 *
	 * @return string
	 */
	public function get_url( string $endpoint ): string {
		return sprintf( Main::API_URL . '/sites/%s/health', Options::get_option( 'site_id' ) );
	}

	/**
	 * Get the body for the request.
	 *
	 * @param  array $data The data to send with the request.
	 *
	 * @return array
	 */
	public function get_body( array $data ): array {
		return array_merge( $data, [] );
	}
}
