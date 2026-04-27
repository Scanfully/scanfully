<?php
/**
 * Email deliverability attempt request.
 *
 * @package Scanfully
 */

namespace Scanfully\API;

use Scanfully\Main;
use Scanfully\Options\Controller as OptionsController;

/**
 * POST /v1/sites/{site_id}/email-health/deliverability/attempt.
 *
 * Body: {nonce, timestamp, token, transport_hint, source}.
 * Returns 202 on success, 401 on bad token, 409 on not provisioned.
 */
class EmailDeliverabilityAttemptRequest extends Request {

	/**
	 * Log a new deliverability attempt server-side.
	 *
	 * @param array $data Body: nonce, timestamp, token, transport_hint, source.
	 *
	 * @return array|null { status: int, body: mixed } or null on transport error.
	 */
	public function send( array $data ): ?array {
		return parent::do_request_with_response( '', $data );
	}

	/**
	 * Build the request URL.
	 *
	 * @param string $endpoint Unused; kept for parent compatibility.
	 *
	 * @return string
	 */
	public function get_url( string $endpoint ): string {
		return sprintf(
			Main::get_api_url() . '/sites/%s/email-health/deliverability/attempt',
			OptionsController::get_option( 'site_id' )
		);
	}

	/**
	 * Pass the caller-supplied data through unchanged.
	 *
	 * @param array $data Body data.
	 *
	 * @return array
	 */
	public function get_body( array $data ): array {
		return $data;
	}
}
