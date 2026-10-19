<?php
/**
 * The failed logins request class file.
 *
 * @package Scanfully
 */

namespace Scanfully\API;

use Scanfully\Main;
use Scanfully\Options\Controller as OptionsController;

/**
 * Sends the hourly counts of failed logins to the Scanfully API.
 */
class FailedLoginsRequest extends Request {

	/**
	 * Send the request to the API.
	 *
	 * @param  array $data The data to send with the request.
	 *
	 * @return int|null The HTTP status, or null when the request itself failed.
	 */
	public function send( array $data ): ?int {
		return parent::do_request( '', $data );
	}

	/**
	 * Get the url for the request.
	 *
	 * @param  string $endpoint The endpoint to send the request to.
	 *
	 * @return string
	 */
	public function get_url( string $endpoint ): string {
		return sprintf( Main::get_api_url() . '/sites/%s/logins/failed', rawurlencode( OptionsController::get_option( 'site_id' ) ) );
	}

	/**
	 * Get the body for the request.
	 *
	 * @param  array $data The data to send with the request.
	 *
	 * @return array
	 */
	public function get_body( array $data ): array {
		return $data;
	}
}
