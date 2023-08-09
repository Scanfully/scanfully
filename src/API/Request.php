<?php

namespace Scanfully\API;

abstract class Request {

	public function send( string $endpoint, array $data ): void {

		// headers for the requests
		$headers = [
			'Content-Type' => 'application/json',
		];

		// add auth key if it exists
		if ( $this->get_auth_token() !== "" ) {
			$headers['Authorization'] = 'BEARER ' . $this->get_auth_token();
		}

		// request arguments for the requests
		$request_args = [
			'headers'     => $headers,
			'timeout'     => 60,
			'blocking'    => false,
			'httpversion' => '1.0',
			'sslverify'   => false,
		];

		// add body to request if there's any
		$request_body = $this->get_body( $data );
		if ( ! empty( $request_body ) ) {
			$request_args['body'] = json_encode( $request_body );
		}

		$response = wp_remote_post( $this->get_url( $endpoint ), $request_args );

		if ( is_wp_error( $response ) ) {
			// TODO: handle error
			error_log("Error sending request: " . $response->get_error_message() );
		}

//		error_log( "response: " . print_r( $response, true ) );

		// TODO: handle response
	}

	abstract protected function get_auth_token(): string;

	abstract protected function get_url( string $endpoint ): string;

	abstract protected function get_body( array $data ): array;

}