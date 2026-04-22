<?php
/**
 * Email deliverability state read.
 *
 * @package Scanfully
 */

namespace Scanfully\API;

use Scanfully\Main;
use Scanfully\Options\Controller as OptionsController;

/**
 * GET /v1/sites/{site_id}/email-health/deliverability/state.
 *
 * Returns the current per-site state view used by the admin panel.
 */
class EmailDeliverabilityStateRequest extends Request {

	/**
	 * Fetch the current state.
	 *
	 * @return array|null { status: int, body: mixed } or null on transport error.
	 */
	public function fetch(): ?array {
		return parent::do_get_request( '' );
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
			Main::get_api_url() . '/sites/%s/email-health/deliverability/state',
			OptionsController::get_option( 'site_id' )
		);
	}

	/**
	 * GET requests have no body.
	 *
	 * @param array $data Unused.
	 *
	 * @return array
	 */
	public function get_body( array $data ): array {
		return [];
	}
}
