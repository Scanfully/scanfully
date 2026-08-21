<?php
/**
 * The WooCheckout config request class.
 *
 * @package Scanfully
 */

namespace Scanfully\API;

use Scanfully\Main;
use Scanfully\Options\Controller as OptionsController;

/**
 * Sends WooCommerce checkout configuration to the Scanfully API so the
 * backend can drive automated checkout probe scans.
 */
class WooCheckoutConfigRequest extends Request {

	/**
	 * Send the request to the API and return the parsed response.
	 *
	 * @param array $data The payload to send.
	 *
	 * @return array|null ['status' => int, 'body' => mixed] or null on transport error.
	 */
	public function send( array $data ): ?array {
		return parent::do_request_with_response( '', $data );
	}

	/**
	 * Get the url for the request.
	 *
	 * @param string $endpoint Unused; endpoint is fixed.
	 *
	 * @return string
	 */
	public function get_url( string $endpoint ): string {
		return sprintf(
			Main::get_api_url() . '/sites/%s/woocheckout/config',
			OptionsController::get_option( 'site_id' )
		);
	}

	/**
	 * Get the body for the request.
	 *
	 * @param array $data The data to send with the request.
	 *
	 * @return array
	 */
	public function get_body( array $data ): array {
		return $data;
	}
}
