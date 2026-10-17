<?php
/**
 * Post change builder unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\PostChanges
 */

namespace Scanfully\Tests\Unit\Events\PostChanges;

use Brain\Monkey\Functions;
use RuntimeException;
use Scanfully\Events\Controller;
use Scanfully\Events\PostChanges\Builder;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\PostChanges\Builder
 * @covers \Scanfully\Events\Controller::deliver
 */
final class BuilderTest extends TestCase {

	/**
	 * The fake API status.
	 *
	 * @var int
	 */
	private int $status = 202;

	/**
	 * Bodies sent to the API.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * Retries scheduled: hook and arguments.
	 *
	 * @var array<int, array{hook: string, args: array<int, mixed>}>
	 */
	private array $retries = [];

	/**
	 * Stored transients.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( 'yes' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => null === $thing );
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->sent[] = json_decode( $args['body'], true );
				return [ $this->status ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $response ) => $response[0] );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args ) {
				$this->retries[] = [
					'hook' => $hook,
					'args' => $args,
				];
				return 1;
			}
		);
	}

	/**
	 * Event data for post 7 with the given changes.
	 *
	 * @param array<string, mixed> $changes The changes as noted.
	 *
	 * @return array<string, mixed>
	 */
	private function data( array $changes ): array {
		return [
			'id'          => 7,
			'title'       => 'About',
			'post_status' => 'publish',
			'post_before' => [ 'post_status' => 'publish' ],
			'post'        => [
				'post_status' => 'publish',
				'post_type'   => 'page',
			],
			'changes'     => $changes,
		];
	}

	/**
	 * Run the job and return the exception message, or '' when accepted.
	 *
	 * @param array<string, mixed> $data  The event data.
	 * @param string               $stash The stash key.
	 *
	 * @return string
	 */
	private function build( array $data, string $stash = '' ): string {
		try {
			Builder::build( 'PostSaved', [ 'id' => 1 ], $data, $stash );
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}

		return '';
	}

	public function test_title_password_and_order_changes_are_sent_as_noted(): void {
		$changes = [
			'title'      => [
				'old' => 'A',
				'new' => 'About',
			],
			'password'   => [
				'old' => false,
				'new' => true,
			],
			'menu_order' => [
				'old' => 0,
				'new' => 2,
			],
		];
		$this->build( $this->data( $changes ) );

		$this->assertSame( $changes['title'], $this->sent[0]['data']['changes']['title'] );
		$this->assertSame( $changes['password'], $this->sent[0]['data']['changes']['password'] );
		$this->assertSame( $changes['menu_order'], $this->sent[0]['data']['changes']['menu_order'] );
	}

	public function test_a_save_without_changes_is_sent_without_a_changes_key(): void {
		$this->build( $this->data( [] ) );

		$this->assertArrayNotHasKey( 'changes', $this->sent[0]['data'] );
	}

	public function test_content_that_was_too_large_is_reported_as_changed(): void {
		$this->build( $this->data( [ 'content' => [ 'too_large' => true ] ] ) );

		$this->assertSame(
			[
				'changed' => true,
				'reason'  => 'too_large',
			],
			$this->sent[0]['data']['changes']['content']
		);
	}

	public function test_stashed_changes_that_expired_are_left_out(): void {
		$this->build( $this->data( [ 'stashed' => true ] ), '0f8fad5bd9cb469fa16570867728950e' );

		$this->assertArrayNotHasKey( 'changes', $this->sent[0]['data'] );
	}

	public function test_stashed_changes_are_used_and_removed_once_sent(): void {
		$key = '0f8fad5bd9cb469fa16570867728950e';
		$this->transients[ 'scanfully_post_change_' . $key ] = [
			'changes' => [
				'title' => [
					'old' => 'A',
					'new' => 'B',
				],
			],
		];

		$this->build( $this->data( [ 'stashed' => true ] ), $key );

		$this->assertSame( 'B', $this->sent[0]['data']['changes']['title']['new'] );
		$this->assertSame( [], $this->transients );
	}

	public function test_a_temporary_failure_retries_the_build_and_keeps_the_stash(): void {
		$key = '0f8fad5bd9cb469fa16570867728950e';
		$this->transients[ 'scanfully_post_change_' . $key ] = [ 'changes' => [] ];
		$this->status                                        = 503;

		$message = $this->build( $this->data( [ 'stashed' => true ] ), $key );

		$this->assertStringContainsString( 'HTTP 503', $message );
		$this->assertSame( Controller::ACTION_BUILD_POST_EVENT, $this->retries[0]['hook'] );
		$this->assertSame( [ 'stashed' => true ], $this->retries[0]['args'][2]['changes'], 'The retry builds again from what was noted.' );
		$this->assertSame( $key, $this->retries[0]['args'][3] );
		$this->assertSame( 1, $this->retries[0]['args'][4] );
		$this->assertCount( 1, $this->transients );
	}

	public function test_a_refused_event_removes_the_stash(): void {
		$key = '0f8fad5bd9cb469fa16570867728950e';
		$this->transients[ 'scanfully_post_change_' . $key ] = [ 'changes' => [] ];
		$this->status                                        = 400;

		$this->build( $this->data( [ 'stashed' => true ] ), $key );

		$this->assertSame( [], $this->retries );
		$this->assertSame( [], $this->transients );
	}
}
