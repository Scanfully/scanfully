<?php

namespace Scanfully\API;

use Scanfully\Options\Options;

class EventRequest extends Request {

	public function send_event( array $data ): void {
		parent::send( '', $data );
	}

	public function get_auth_headers(): array {
		$headers                        = [];
		$headers['X-Scanfully-Site-Id'] = Options::get_option( 'site_id' );
		$headers['X-Scanfully-Public']  = Options::get_option( 'public_key' );
		$headers['X-Scanfully-Secret']  = Options::get_option( 'secret_key' );

		return $headers;
	}

	public function get_url( string $endpoint ): string {
		return 'http://localhost:8888/v1/events';
		// return 'https://api.scanfully.com/v1/events';
	}

	public function get_body( array $data ): array {
		return array_merge( $data, [] );
	}
}
