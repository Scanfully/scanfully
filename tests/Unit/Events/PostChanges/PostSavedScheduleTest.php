<?php
/**
 * PostSaved job scheduling unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\PostChanges
 */

namespace Scanfully\Tests\Unit\Events\PostChanges;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Scanfully\Events\Controller;
use Scanfully\Events\PostSaved;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\PostSaved
 * @covers \Scanfully\Events\PostChanges\Stash
 */
final class PostSavedScheduleTest extends TestCase {

	/**
	 * Scheduled jobs: hook and arguments.
	 *
	 * @var array<int, array{hook: string, args: array<string, mixed>}>
	 */
	private array $scheduled = [];

	/**
	 * Stored transients.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	/**
	 * Whether storing a transient works.
	 *
	 * @var bool
	 */
	private bool $can_store = true;

	protected function setUp(): void {
		parent::setUp();
		$this->scheduled  = [];
		$this->transients = [];
		$this->can_store  = true;

		Functions\when( 'get_option' )->justReturn( 'yes' );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'get_post_type_object' )->justReturn( (object) [ 'show_ui' => true ] );
		// No per-minute cap, so the only transients are the stored changes.
		Filters\expectApplied( 'scanfully_post_saved_events_per_minute' )->zeroOrMoreTimes()->andReturn( 0 );
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) [
				'ID'           => 1,
				'display_name' => 'Admin',
			]
		);
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		Functions\when( 'wp_generate_uuid4' )->justReturn( '0f8fad5b-d9cb-469f-a165-70867728950e' );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ) {
				if ( ! $this->can_store ) {
					return false;
				}
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args ) {
				$this->scheduled[] = [
					'hook' => $hook,
					'args' => $args,
				];
				return 1;
			}
		);
	}

	/**
	 * Report a saved post with the given changes.
	 *
	 * @param array<string, mixed> $changes The changes.
	 *
	 * @return void
	 */
	private function report( array $changes ): void {
		$post = (object) [
			'ID'          => 7,
			'post_title'  => 'About',
			'post_status' => 'publish',
			'post_type'   => 'page',
		];

		( new PostSaved() )->listener_callback( 7, $post, true, $post, $changes );
	}

	public function test_the_build_job_gets_the_changes_in_its_arguments(): void {
		$changes = [
			'title' => [
				'old' => 'A',
				'new' => 'About',
			],
		];
		$this->report( $changes );

		$this->assertSame( Controller::ACTION_BUILD_POST_EVENT, $this->scheduled[0]['hook'] );
		$this->assertSame( $changes, $this->scheduled[0]['args']['data']['changes'] );
		$this->assertSame( '', $this->scheduled[0]['args']['stash'] );
		$this->assertSame( [], $this->transients );
	}

	public function test_a_save_without_changes_sends_no_changes_key(): void {
		$this->report( [] );

		$this->assertArrayNotHasKey( 'changes', $this->scheduled[0]['args']['data'] );
	}

	public function test_content_without_revisions_is_stored_for_the_job(): void {
		$changes = [
			'content' => [
				'stash' => [
					'old' => '<p>Hello</p>',
					'new' => '<p>Hi</p>',
				],
			],
		];
		$this->report( $changes );

		$args = $this->scheduled[0]['args'];
		$this->assertSame( [ 'stashed' => true ], $args['data']['changes'] );
		$this->assertSame( '0f8fad5bd9cb469fa16570867728950e', $args['stash'] );
		$this->assertSame( [ 'changes' => $changes ], $this->transients[ 'scanfully_post_change_' . $args['stash'] ] );
	}

	public function test_changes_too_large_for_the_job_arguments_are_stored(): void {
		$fields = [];
		for ( $i = 0; $i < 30; $i++ ) {
			$fields[ 'field_' . $i ] = [
				'change' => 'changed',
				'old'    => str_repeat( 'a', 200 ),
				'new'    => str_repeat( 'b', 200 ),
			];
		}
		$this->report( [ 'fields' => $fields ] );

		$this->assertSame( [ 'stashed' => true ], $this->scheduled[0]['args']['data']['changes'] );
		$this->assertCount( 1, $this->transients );
	}

	public function test_content_that_could_not_be_stored_is_only_reported_as_changed(): void {
		$this->can_store = false;
		$this->report(
			[
				'content' => [
					'stash' => [
						'old' => 'a',
						'new' => 'b',
					],
				],
			]
		);

		$this->assertSame( [ 'content' => [ 'unavailable' => true ] ], $this->scheduled[0]['args']['data']['changes'] );
		$this->assertSame( '', $this->scheduled[0]['args']['stash'] );
	}
}
