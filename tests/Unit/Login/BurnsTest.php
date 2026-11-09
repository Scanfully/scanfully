<?php
/**
 * Burn list unit tests: a sign-in link works once.
 *
 * @package Scanfully\Tests\Unit\Login
 */

namespace Scanfully\Tests\Unit\Login;

use Brain\Monkey\Functions;
use Scanfully\Login\Burns;
use Scanfully\Login\Controller;
use Scanfully\Tests\Unit\Manage\FakeWpdb;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Login\Burns
 */
final class BurnsTest extends TestCase {

	private const NOW = 1800000000;

	private FakeWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db        = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->db;
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * The list as stored.
	 *
	 * @return array<string, int>
	 */
	private function stored(): array {
		return json_decode( $this->db->rows[ Burns::OPTION ], true );
	}

	public function test_a_jti_is_burned_once(): void {
		$this->assertSame( Burns::BURNED, Burns::burn( 'jti-1', self::NOW + 60, self::NOW ) );
		$this->assertSame( Burns::SEEN, Burns::burn( 'jti-1', self::NOW + 60, self::NOW + 1 ) );
		$this->assertSame( Burns::BURNED, Burns::burn( 'jti-2', self::NOW + 60, self::NOW + 1 ) );
		$this->assertSame(
			[
				hash( 'sha256', 'jti-1' ) => self::NOW + 60,
				hash( 'sha256', 'jti-2' ) => self::NOW + 60,
			],
			$this->stored()
		);
	}

	public function test_the_list_holds_no_jti_in_clear(): void {
		Burns::burn( 'jti-in-clear', self::NOW + 60, self::NOW );

		$this->assertStringNotContainsString( 'jti-in-clear', $this->db->rows[ Burns::OPTION ] );
	}

	public function test_a_jti_stays_burned_for_as_long_as_its_token_could_pass(): void {
		Burns::burn( 'jti-1', self::NOW + 60, self::NOW );

		$this->assertSame( Burns::SEEN, Burns::burn( 'jti-1', self::NOW + 60, self::NOW + 60 + Controller::SKEW ), 'the last second its token passes the expiry check' );
	}

	public function test_every_burn_prunes_the_expired_entries(): void {
		Burns::burn( 'old', self::NOW + 60, self::NOW );
		Burns::burn( 'newer', self::NOW + 120, self::NOW + 60 );

		Burns::burn( 'new', self::NOW + 200, self::NOW + 60 + Controller::SKEW + 1 );

		$this->assertSame( [ hash( 'sha256', 'newer' ), hash( 'sha256', 'new' ) ], array_keys( $this->stored() ) );
	}

	public function test_a_full_list_refuses_rather_than_forgets(): void {
		$list = [];
		for ( $i = 0; $i < Burns::MAX; $i++ ) {
			$list[ hash( 'sha256', 'jti-' . $i ) ] = self::NOW + 60;
		}
		$this->db->add( Burns::OPTION, (string) json_encode( $list ) );

		$this->assertSame( Burns::FULL, Burns::burn( 'one-more', self::NOW + 60, self::NOW ) );
		$this->assertSame( Burns::SEEN, Burns::burn( 'jti-0', self::NOW + 60, self::NOW ), 'no entry was dropped' );
		$this->assertSame( Burns::BURNED, Burns::burn( 'one-more', self::NOW + 120, self::NOW + 91 ), 'once they expire there is room' );
	}

	public function test_two_requests_with_the_same_token_at_once_get_in_once(): void {
		// The other request writes the same jti between this one's read and
		// its write.
		$db              = new class() extends \stdClass {
			/**
			 * The table.
			 *
			 * @var FakeWpdb
			 */
			public FakeWpdb $inner;

			/**
			 * Whether the other request wrote.
			 *
			 * @var bool
			 */
			public bool $raced = false;

			public string $options = 'wp_options';

			public function prepare( string $sql, ...$args ): array {
				return $this->inner->prepare( $sql, ...$args );
			}

			public function get_var( array $prepared ): ?string {
				return $this->inner->get_var( $prepared );
			}

			public function query( array $prepared ): int {
				if ( ! $this->raced ) {
					$this->raced = true;
					Burns::burn( 'same-jti', 1800000060, 1800000000 );
				}
				return $this->inner->query( $prepared );
			}
		};
		$db->inner       = $this->db;
		$GLOBALS['wpdb'] = $db;

		$this->assertSame( Burns::SEEN, Burns::burn( 'same-jti', self::NOW + 60, self::NOW ) );
		$this->assertCount( 1, $this->stored() );
	}

	public function test_a_list_that_keeps_changing_burns_nothing(): void {
		$this->db->add( Burns::OPTION, '{}' );
		$db              = new class() extends \stdClass {
			/**
			 * The table.
			 *
			 * @var FakeWpdb
			 */
			public FakeWpdb $inner;

			public string $options = 'wp_options';

			public function prepare( string $sql, ...$args ): array {
				return $this->inner->prepare( $sql, ...$args );
			}

			public function get_var( array $prepared ): ?string {
				return $this->inner->get_var( $prepared );
			}

			public function query( array $prepared ): int {
				unset( $prepared );
				return 0;
			}
		};
		$db->inner       = $this->db;
		$GLOBALS['wpdb'] = $db;

		$this->assertSame( Burns::BUSY, Burns::burn( 'jti', self::NOW + 60, self::NOW ) );
	}

	public function test_a_damaged_list_starts_over(): void {
		$this->db->add( Burns::OPTION, 'not json' );

		$this->assertSame( Burns::BURNED, Burns::burn( 'jti', self::NOW + 60, self::NOW ) );
		$this->assertSame( [ hash( 'sha256', 'jti' ) => self::NOW + 60 ], $this->stored() );
	}
}
