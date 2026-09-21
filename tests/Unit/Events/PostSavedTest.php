<?php
/**
 * PostSaved event unit tests.
 *
 * @package Scanfully\Tests\Unit\Events
 */

namespace Scanfully\Tests\Unit\Events;

use Scanfully\Events\PostSaved;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\PostSaved
 */
final class PostSavedTest extends TestCase {

	/**
	 * Build a post object with the given fields.
	 *
	 * @param array<string, mixed> $fields Post fields.
	 *
	 * @return object
	 */
	private function make_post( array $fields ): object {
		return (object) array_merge(
			[
				'ID'            => 42,
				'post_title'    => 'Hello',
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_password' => '',
			],
			$fields
		);
	}

	public function test_post_body_never_contains_the_post_password(): void {
		$before = $this->make_post( [ 'post_password' => 'old-secret' ] );
		$after  = $this->make_post( [ 'post_password' => 'new-secret' ] );

		$body = ( new PostSaved() )->get_post_body( [ 42, $after, true, $before ] );

		$this->assertArrayNotHasKey( 'post_password', $body['post'] );
		$this->assertArrayNotHasKey( 'post_password', $body['post_before'] );
		$encoded = (string) json_encode( $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		$this->assertStringNotContainsString( 'secret', $encoded );
	}

	public function test_post_body_flags_password_protected_posts(): void {
		$before = $this->make_post( [] );
		$after  = $this->make_post( [ 'post_password' => 'secret' ] );

		$body = ( new PostSaved() )->get_post_body( [ 42, $after, true, $before ] );

		$this->assertTrue( $body['post']['has_password'] );
		$this->assertFalse( $body['post_before']['has_password'] );
	}

	public function test_post_body_handles_array_posts(): void {
		$after = $this->make_post( [] );

		$body = ( new PostSaved() )->get_post_body(
			[
				42,
				$after,
				true,
				[
					'ID'            => 42,
					'post_password' => 'secret',
				],
			]
		);

		$this->assertArrayNotHasKey( 'post_password', $body['post_before'] );
		$this->assertTrue( $body['post_before']['has_password'] );
	}

	public function test_post_body_is_null_for_a_missing_previous_post(): void {
		$body = ( new PostSaved() )->get_post_body( [ 42, $this->make_post( [] ), false, null ] );

		$this->assertNull( $body['post_before'] );
	}
}
