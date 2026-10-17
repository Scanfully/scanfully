<?php
/**
 * Content event builder unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\Content
 */

namespace Scanfully\Tests\Unit\Events\Content;

use Brain\Monkey\Functions;
use Scanfully\Events\Content\Builder;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\Content\Builder
 */
final class BuilderTest extends TestCase {

	/**
	 * Bodies sent to the API.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

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
				return [ 202 ];
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
		Functions\when( 'get_term' )->alias(
			static function ( $id ) {
				$names = [
					4 => 'Company',
					5 => 'News &amp; updates',
				];
				if ( ! isset( $names[ $id ] ) ) {
					return null;
				}
				$term       = new \WP_Term( (object) [] );
				$term->name = $names[ $id ];
				return $term;
			}
		);
		Functions\when( 'get_term_by' )->alias(
			static function ( $field, $id ) {
				$term       = new \WP_Term( (object) [] );
				$term->name = 12 === $id ? 'News' : 'Sports';
				return $term;
			}
		);
		Functions\when( 'get_taxonomy' )->justReturn( (object) [ 'labels' => (object) [ 'name' => 'Categories' ] ] );
	}

	public function test_a_new_terms_parent_is_named(): void {
		$data = Builder::resolve(
			'TermCreated',
			[
				'id'       => 9,
				'taxonomy' => 'category',
				'parent'   => [ 'id' => 4 ],
			]
		);

		$this->assertSame(
			[
				'id'   => 4,
				'name' => 'Company',
			],
			$data['parent']
		);
	}

	public function test_a_moved_terms_parents_are_named_and_a_gone_one_keeps_its_id(): void {
		$data = Builder::resolve(
			'TermChanged',
			[
				'taxonomy' => 'category',
				'changes'  => [
					'parent' => [
						'old' => [ 'id' => 99 ],
						'new' => [ 'id' => 5 ],
					],
				],
			]
		);

		$this->assertSame(
			[
				'old' => [
					'id'   => 99,
					'name' => '',
				],
				'new' => [
					'id'   => 5,
					'name' => 'News & updates',
				],
			],
			$data['changes']['parent']
		);
	}

	public function test_the_terms_of_a_bulk_edit_are_named(): void {
		$data = Builder::resolve(
			'PostBulkAction',
			[
				'terms' => [
					'category' => [
						'added'   => [ 12 ],
						'removed' => [ 13 ],
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'taxonomy' => 'category',
					'label'    => 'Categories',
					'added'    => [ 'News' ],
					'removed'  => [ 'Sports' ],
				],
			],
			$data['terms']
		);
	}

	public function test_stored_data_is_sent_and_removed(): void {
		$key                                        = str_repeat( 'a', 32 );
		$this->transients[ 'scanfully_post_change_' . $key ] = [
			'data' => [
				'action' => 'trashed',
				'count'  => 1,
				'posts'  => [
					[
						'id'    => 1,
						'title' => 'About',
					],
				],
			],
		];

		Builder::build( 'PostBulkAction', [ 'id' => 1 ], [ 'stashed' => true ], $key );

		$this->assertSame( 'PostBulkAction', $this->sent[0]['type'] );
		$this->assertSame( 'About', $this->sent[0]['data']['posts'][0]['title'] );
		$this->assertSame( [], $this->transients );
	}

	public function test_stored_data_that_expired_sends_nothing(): void {
		Builder::build( 'PostBulkAction', [ 'id' => 1 ], [ 'stashed' => true ], str_repeat( 'b', 32 ) );

		$this->assertSame( [], $this->sent );
	}
}
