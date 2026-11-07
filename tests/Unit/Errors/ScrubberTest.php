<?php
/**
 * PHP error scrubber unit tests.
 *
 * @package Scanfully\Tests\Unit\Errors
 */

namespace Scanfully\Tests\Unit\Errors;

use Scanfully\Errors\Scrubber;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Errors\Scrubber
 */
final class ScrubberTest extends TestCase {

	/**
	 * @dataProvider secrets
	 */
	public function test_secrets_are_replaced( string $text, string $expected ): void {
		$this->assertSame( $expected, Scrubber::scrub( $text ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function secrets(): array {
		return [
			'email'           => [ 'Could not send to jane.doe+shop@scanfully.com', 'Could not send to [email]' ],
			'password'        => [ 'Login failed: password=hunter2 for admin', 'Login failed: password=[redacted] for admin' ],
			'password quoted' => [ "Array ( 'pass' => 'hunter2', 'user' => 'bob' )", "Array ( 'pass' => [redacted], 'user' => 'bob' )" ],
			'api key'         => [ 'api_key: abcdef123456 rejected', 'api_key: [redacted] rejected' ],
			'bearer'          => [ 'Header Authorization: Bearer eyJhbGciOi.eyJzdWIi.SflKxw rejected', 'Header Authorization: [redacted] rejected' ],
			// Made up at run time, so no key-shaped text is in the repository.
			'stripe key'      => [ 'Invalid key sk_' . 'live_' . str_repeat( 'Zx9', 8 ) . ' given', 'Invalid key [key] given' ],
			'aws key'         => [ 'AK' . 'IA' . str_repeat( 'Q7', 8 ) . ' denied', '[key] denied' ],
			'hash'            => [ 'Token 9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08 expired', 'Token [key] expired' ],
			'long key'        => [ 'Key AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdefghij invalid', 'Key [key] invalid' ],
			'query string'    => [ 'file_get_contents(https://api.scanfully.com/v1/x?token=abc&user=1): Failed', 'file_get_contents(https://api.scanfully.com/v1/x?[query]): Failed' ],
			'path query'      => [ 'Bad request /wp-json/wc/v3/orders?consumer_key=ck_123', 'Bad request /wp-json/wc/v3/orders?[query]' ],
		];
	}

	public function test_ordinary_messages_are_left_alone(): void {
		$messages = [
			'Undefined array key "total"',
			'Call to undefined function wc_get_order()',
			'file_get_contents(wp-content/uploads/2026/11/export.csv): Failed to open stream: No such file or directory',
			'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
			'Attempt to read property "ID" on null',
			'foreach() argument must be of type array|object, string given',
		];
		foreach ( $messages as $message ) {
			$this->assertSame( $message, Scrubber::scrub( $message ) );
		}
	}

	public function test_a_long_message_is_cut_to_a_thousand_characters(): void {
		$message = str_repeat( 'é', 1500 );

		$this->assertSame( 1000, mb_strlen( Scrubber::scrub( $message ), 'UTF-8' ) );
		$this->assertSame( 1000, strlen( Scrubber::scrub( str_repeat( 'a', 1500 ) ) ) );
	}

	public function test_invalid_utf8_is_cut_by_bytes(): void {
		$message = str_repeat( "\xff", 1200 );

		$this->assertSame( 1000, strlen( Scrubber::scrub( $message ) ) );
	}
}
