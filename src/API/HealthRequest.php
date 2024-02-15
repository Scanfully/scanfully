<?php
/**
 * The health request class file.
 *
 * @package Scanfully
 */

namespace Scanfully\API;

use Scanfully\Main;
use Scanfully\Options\Controller as OptionsController;

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
	 * Get the url for the request.
	 *
	 * @param  string $endpoint The endpoint to send the request to.
	 *
	 * @return string
	 */
	public function get_url( string $endpoint ): string {
		return sprintf( Main::API_URL . '/sites/%s/health', OptionsController::get_option( 'site_id' ) );
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
