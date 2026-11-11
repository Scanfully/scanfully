<?php
/**
 * Network children unit tests: the mapping and the markers.
 *
 * @package Scanfully\Tests\Unit\Connect
 */

namespace Scanfully\Tests\Unit\Connect;

use Scanfully\Connect\Children;
use Scanfully\Options\Controller as OptionsController;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Connect\Children
 * @covers \Scanfully\Health\Subsites::read
 */
final class ChildrenTest extends TestCase {

	use NetworkFixture;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_network();
	}

	protected function tearDown(): void {
		$this->tear_down_network();
		parent::tearDown();
	}

	/**
	 * A subsite's stored marker, read back.
	 *
	 * @param int $blog The blog id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function marker( int $blog ): ?array {
		$raw = $this->db->options[ $blog ][ Children::MARKER_OPTION ] ?? null;

		return null === $raw ? null : maybe_unserialize( $raw );
	}

	public function test_an_answer_is_checked_entry_by_entry(): void {
		$this->assertSame(
			[
				2 => 'child-2',
				3 => 'child-3',
				7 => 'child-7',
			],
			Children::parse(
				[
					[
						'blog_id' => 3,
						'site_id' => 'child-3',
					],
					[
						'blog_id' => 1,
						'site_id' => 'main-is-never-a-child',
					],
					[
						'blog_id' => 2,
						'site_id' => 'child-2',
					],
					[
						'blog_id' => 2,
						'site_id' => 'second-entry-for-2',
					],
					[
						'blog_id' => 4,
						'site_id' => 'child-3',
					],
					[
						'blog_id' => '7',
						'site_id' => 'child-7',
					],
					[
						'blog_id' => 0,
						'site_id' => 'zero',
					],
					[
						'blog_id' => 2147483648,
						'site_id' => 'too-big',
					],
					[
						'blog_id' => 5,
						'site_id' => 'bad id/../',
					],
					[
						'blog_id' => 6,
					],
					'not an entry',
				]
			)
		);
	}

	public function test_the_network_learns_its_children_and_writes_their_markers(): void {
		$added = Children::learn(
			[
				[
					'blog_id' => 2,
					'site_id' => 'child-2',
				],
				[
					'blog_id' => 3,
					'site_id' => 'child-3',
				],
			]
		);

		$this->assertSame( [ 2, 3 ], $added );
		$this->assertSame(
			[
				2 => 'child-2',
				3 => 'child-3',
			],
			$this->site_options[ Children::MAPPING_OPTION ]
		);
		$this->assertSame( [ 2, 3 ], $this->site_options[ Children::PENDING_OPTION ] );
		$this->assertCount( 1, $this->as_calls_of( Children::ACTION_SYNC_MARKERS ) );

		Children::sync_markers();

		$this->assertArrayNotHasKey( Children::PENDING_OPTION, $this->site_options );
		$this->assertSame(
			[
				'site_id' => 'child-2',
				'blog_id' => 2,
				'home'    => hash( 'sha256', 'network.test/shop' ),
			],
			$this->marker( 2 )
		);
		$this->assertSame( 'child-3', $this->marker( 3 )['site_id'] );
		$this->assertNull( $this->marker( 1 ) );
		// No switch_to_blog() per subsite.
		$this->assertSame( [], $this->switches );
	}

	public function test_an_unchanged_answer_writes_nothing(): void {
		$this->monitor( [ 2 => 'child-2' ] );

		$this->assertSame(
			[],
			Children::learn(
				[
					[
						'blog_id' => 2,
						'site_id' => 'child-2',
					],
				]
			)
		);
		$this->assertArrayNotHasKey( Children::PENDING_OPTION, $this->site_options );
		$this->assertSame( [], $this->as_calls );
	}

	public function test_an_answer_without_the_list_keeps_the_children(): void {
		$this->monitor( [ 2 => 'child-2' ] );

		Children::learn( null );

		$this->assertSame( [ 2 => 'child-2' ], $this->site_options[ Children::MAPPING_OPTION ] );
	}

	public function test_an_empty_list_ends_every_child_and_its_marker_goes(): void {
		$this->monitor(
			[
				2 => 'child-2',
				3 => 'child-3',
			]
		);

		Children::learn( [] );
		Children::sync_markers();

		$this->assertArrayNotHasKey( Children::MAPPING_OPTION, $this->site_options );
		$this->assertNull( $this->marker( 2 ) );
		$this->assertNull( $this->marker( 3 ) );
	}

	public function test_a_subsite_monitored_as_another_child_site_gets_a_new_marker(): void {
		$this->monitor( [ 2 => 'child-2' ] );

		$this->assertSame(
			[ 2 ],
			Children::learn(
				[
					[
						'blog_id' => 2,
						'site_id' => 'child-2b',
					],
				]
			)
		);
		Children::sync_markers();

		$this->assertSame( 'child-2b', $this->marker( 2 )['site_id'] );
	}

	public function test_only_the_network_connection_learns(): void {
		$this->on_blog( 2 );

		$this->assertSame(
			[],
			Children::learn(
				[
					[
						'blog_id' => 3,
						'site_id' => 'child-3',
					],
				]
			)
		);
		$this->assertArrayNotHasKey( Children::MAPPING_OPTION, $this->site_options );
	}

	public function test_a_marker_is_not_written_for_a_site_of_another_network(): void {
		$this->db->add_site( 9, 'other.test', '/', [ 'site_id' => 2 ] );
		$this->mark( 9, 'stale' );

		Children::write_markers( [ 9 ], [ 9 => 'child-9' ] );

		$this->assertNull( $this->marker( 9 ) );
	}

	public function test_a_subsite_whose_address_changed_gets_its_marker_again(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->db->options[2]['home'] = 'https://shop.example.com';
		$this->db->writes             = [];

		Children::keep_marker( 2, 'child-2', $this->db->options[2] );

		$this->assertSame( hash( 'sha256', 'shop.example.com' ), $this->marker( 2 )['home'] );

		// Nothing to write the second time.
		$this->db->writes = [];
		Children::keep_marker( 2, 'child-2', $this->db->options[2] );
		$this->assertSame( [], $this->db->writes );
	}

	public function test_a_long_sync_goes_on_in_a_new_job(): void {
		$mapping = [];
		for ( $blog = 10; $blog < 260; $blog++ ) {
			$this->db->add_site( $blog, 'network.test', '/s' . $blog . '/' );
			$mapping[ $blog ] = 'child-' . $blog;
		}
		$this->site_options[ Children::MAPPING_OPTION ] = $mapping;
		$this->site_options[ Children::PENDING_OPTION ] = array_keys( $mapping );

		Children::sync_markers();

		$this->assertArrayNotHasKey( Children::PENDING_OPTION, $this->site_options );
		$this->assertSame( 'child-259', $this->marker( 259 )['site_id'] );
		// Three chunks of option tables read, a hundred subsites a query each.
		$this->assertCount( 3, array_filter( $this->db->queries, static fn( string $sql ): bool => false !== strpos( $sql, 'CONVERT(option_name' ) ) );
	}

	public function test_a_network_disconnect_ends_every_child(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$cleared = 0;
		\Brain\Monkey\Actions\expectDone( 'scanfully_network_children_cleared' )->once()->whenHappen(
			static function () use ( &$cleared ): void {
				++$cleared;
			}
		);

		OptionsController::with_scope( true, static fn() => Children::disconnected() );
		Children::sync_markers();

		$this->assertSame( 1, $cleared );
		$this->assertArrayNotHasKey( Children::MAPPING_OPTION, $this->site_options );
		$this->assertNull( $this->marker( 2 ) );
	}

	public function test_a_subsites_own_disconnect_leaves_the_children(): void {
		$this->monitor( [ 2 => 'child-2' ] );
		$this->on_blog( 2 );

		Children::disconnected();

		$this->assertSame( [ 2 => 'child-2' ], $this->site_options[ Children::MAPPING_OPTION ] );
	}

	public function test_a_deleted_subsite_leaves_the_mapping(): void {
		$this->monitor(
			[
				2 => 'child-2',
				3 => 'child-3',
			]
		);

		Children::site_deleted(
			(object) [
				'blog_id' => '2',
				'site_id' => '1',
			]
		);

		$this->assertSame( [ 3 => 'child-3' ], $this->site_options[ Children::MAPPING_OPTION ] );
	}

	public function test_a_subsite_moved_to_another_network_loses_its_marker(): void {
		$this->monitor( [ 2 => 'child-2' ] );

		Children::site_updated(
			(object) [
				'blog_id' => '2',
				'site_id' => '2',
			],
			(object) [
				'blog_id' => '2',
				'site_id' => '1',
			]
		);

		$this->assertArrayNotHasKey( Children::MAPPING_OPTION, $this->site_options );
		$this->assertNull( $this->marker( 2 ) );
	}

	public function test_a_gone_child_site_is_forgotten(): void {
		$this->monitor(
			[
				2 => 'child-2',
				3 => 'child-3',
			]
		);

		Children::forget( 2 );
		Children::forget( 5 );
		Children::sync_markers();

		$this->assertSame( [ 3 => 'child-3' ], $this->site_options[ Children::MAPPING_OPTION ] );
		$this->assertNull( $this->marker( 2 ) );
		$this->assertNotNull( $this->marker( 3 ) );
	}
}
