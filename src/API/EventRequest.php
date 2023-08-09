<?php

namespace Scanfully\API;

class EventRequest extends Request {

	protected function get_auth_token(): string {
		return '123';
	}

	protected function get_url( string $endpoint ): string {
		return 'https://events.scanfully.com/v1/' . $endpoint;
	}

	protected function get_body( array $data ): array {
		// todo add env details that should be included in every event log
		return array_merge( $data, [
			'extra' => 'stuff'
		] );
	}
}