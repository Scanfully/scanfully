<?php
/**
 * The Manage jobs request class file.
 *
 * @package Scanfully
 */

namespace Scanfully\API;

use Scanfully\Main;
use Scanfully\Options\Controller as OptionsController;

/**
 * The plugin's side of Scanfully Manage's command channel, with the site
 * token (scope wordpress:manage):
 * GET /v1/sites/{site_id}/manage/jobs pulls the site's jobs,
 * POST .../manage/jobs/{job_id}/heartbeat says a long job still runs, and
 * POST .../manage/jobs/{job_id}/result reports how a job went.
 */
class ManageJobsRequest extends Request {

	/**
	 * Seconds a request may take: the pull and the result run in Action
	 * Scheduler actions, never in a visitor's request.
	 */
	private const TIMEOUT = 20;

	/**
	 * Pull the site's jobs.
	 *
	 * @return array|null { status: int, body: mixed } or null on transport error.
	 */
	public function pull(): ?array {
		return $this->do_get_request( '', [], self::TIMEOUT );
	}

	/**
	 * Send a heartbeat of a job, with its attempt: the API refuses one of an
	 * earlier attempt (409 attempt_conflict).
	 *
	 * @param string $job_id  The job id.
	 * @param int    $attempt The job's attempt.
	 *
	 * @return array|null { status: int, body: mixed } or null on transport error.
	 */
	public function heartbeat( string $job_id, int $attempt = 0 ): ?array {
		return $this->do_request_with_response( '/' . rawurlencode( $job_id ) . '/heartbeat', [ 'attempt' => $attempt ], self::TIMEOUT );
	}

	/**
	 * Report a job's result.
	 *
	 * @param string $job_id The job id.
	 * @param array  $result The result, as Manage\Runner::run() made it.
	 *
	 * @return array|null { status: int, body: mixed } or null on transport error.
	 */
	public function result( string $job_id, array $result ): ?array {
		return $this->do_request_with_response( '/' . rawurlencode( $job_id ) . '/result', $result, self::TIMEOUT );
	}

	/**
	 * The URL of the jobs, with the path of one job's route after it.
	 *
	 * @param string $endpoint The path after /manage/jobs, or ''.
	 *
	 * @return string
	 */
	public function get_url( string $endpoint ): string {
		return sprintf(
			Main::get_api_url() . '/sites/%s/manage/jobs%s',
			rawurlencode( OptionsController::get_option( 'site_id' ) ),
			$endpoint
		);
	}

	/**
	 * The body: the result as given, nothing for a pull or a heartbeat.
	 *
	 * @param array $data The data.
	 *
	 * @return array
	 */
	public function get_body( array $data ): array {
		return $data;
	}
}
