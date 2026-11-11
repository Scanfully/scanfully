<?php
/**
 * The plugin connection request class file.
 *
 * @package Scanfully
 */

namespace Scanfully\API;

use Scanfully\Main;

/**
 * The plugin's view of its own connection, with the site token:
 * GET /v1/sites/{site_id}/plugin-connection answers what Scanfully may do on
 * the site now, as the grant in the Scanfully dashboard holds it.
 */
class PluginConnectionRequest extends Request {

	/**
	 * Seconds the request may take: it runs in Action Scheduler, never in a
	 * visitor's request.
	 */
	private const TIMEOUT = 15;

	/**
	 * Read the connection.
	 *
	 * @return array|null { status: int, body: mixed } or null on transport error.
	 */
	public function read(): ?array {
		return $this->do_get_request( '', [], self::TIMEOUT );
	}

	/**
	 * The URL of the site's connection.
	 *
	 * @param string $endpoint Not used.
	 *
	 * @return string
	 */
	public function get_url( string $endpoint ): string {
		return sprintf(
			Main::get_api_url() . '/sites/%s/plugin-connection%s',
			rawurlencode( $this->site_id() ),
			$endpoint
		);
	}

	/**
	 * The body: none.
	 *
	 * @param array $data The data.
	 *
	 * @return array
	 */
	public function get_body( array $data ): array {
		return $data;
	}
}
