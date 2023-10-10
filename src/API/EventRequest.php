<?php

namespace Scanfully\API;

use Scanfully\Options\Options;

/**
 * This class is used to send events to the Scanfully API.
 */
class EventRequest extends Request {

	/**
	 * Send the request to the API.
	 *
	 * @param  array $data
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
		return 'https://api.scanfully.com/v1/events';
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
