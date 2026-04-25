<?php
/**
 * Email deliverability provision request.
 *
 * @package Scanfully
 */

namespace Scanfully\API;

use Scanfully\Main;
use Scanfully\Options\Controller as OptionsController;

/**
 * POST /v1/sites/{site_id}/email-health/deliverability/provision.
 *
 * Always returns 200 with {secret, interval_seconds, inbound_address} and
 * rotates the per-site secret on every call. Idempotent from the plugin's
 * perspective: call it whenever the local secret is missing or whenever
 * /attempt rejects the current token (401 / 409).
 */
class EmailDeliverabilityProvisionRequest extends Request {

	/**
	 * Send the provision request.
	 *
	 * @return array|null { status: int, body: mixed } or null on transport error.
	 */
	public function send(): ?array {
		return parent::do_request_with_response( '', [] );
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
			Main::get_api_url() . '/sites/%s/email-health/deliverability/provision',
			OptionsController::get_option( 'site_id' )
		);
	}

	/**
	 * Build the request body. Provisioning takes no parameters.
	 *
	 * @param array $data Unused.
	 *
	 * @return array
	 */
	public function get_body( array $data ): array {
		return [];
	}
}
