<?php
/**
 * Email deliverability attempt-result request.
 *
 * @package Scanfully
 */

namespace Scanfully\API;

use Scanfully\Main;
use Scanfully\Options\Controller as OptionsController;

/**
 * POST /v1/sites/{site_id}/email-health/deliverability/attempt-result.
 *
 * Body: {nonce, wp_mail_returned, wp_mail_error?}. Idempotent on
 * (site_id, nonce). Returns 200 on success, 404 if no attempt row exists.
 */
class EmailDeliverabilityAttemptResultRequest extends Request {

	/**
	 * Report the wp_mail() return value (and optional error message) for a
	 * previously logged attempt.
	 *
	 * @param array $data Body: nonce, wp_mail_returned (bool), wp_mail_error (optional string).
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
			Main::get_api_url() . '/sites/%s/email-health/deliverability/attempt-result',
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
