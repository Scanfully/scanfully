<?php
/**
 * Rewrite rules event unit tests.
 *
 * @package Scanfully\Tests\Unit\Events
 */

namespace Scanfully\Tests\Unit\Events;

use Brain\Monkey\Functions;
use ReflectionProperty;
use Scanfully\Events\RewriteRules;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\RewriteRules
 */
final class RewriteRulesTest extends TestCase {

	/**
	 * In-memory transient store.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	protected function setUp(): void {
		parent::setUp();
		$this->transients = [];
		$this->new_request();

		Functions\when( 'get_transient' )->alias( fn( string $key ) => array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
	}

	/**
	 * Reset the per-request state.
	 */
	private function new_request(): void {
		$fired = new ReflectionProperty( RewriteRules::class, 'fired' );
		$fired->setAccessible( true );
		$fired->setValue( null, false );
	}

	/**
	 * Whether an update of the option from $old to $new fires.
	 *
	 * @param mixed $old Old value.
	 * @param mixed $new New value.
	 *
	 * @return bool
	 */
	private function fires( $old, $new ): bool {
		return ( new RewriteRules() )->should_fire( [ $old, $new, 'rewrite_rules' ] );
	}

	public function test_a_flush_on_old_wordpress_versions_is_reported_once(): void {
		$rules = [ '^foo/?$' => 'index.php?foo=1' ];

		// Before 6.4 a flush empties the option, then saves the rules.
		$this->assertFalse( $this->fires( $rules, '' ) );
		$this->assertTrue( $this->fires( '', $rules ) );
	}

	public function test_repeated_flushes_are_throttled(): void {
		$rules = [ '^foo/?$' => 'index.php?foo=1' ];

		$this->assertTrue( $this->fires( [], $rules ) );
		$this->assertFalse( $this->fires( [], $rules ), 'Same request.' );

		$this->new_request();
		$this->assertFalse( $this->fires( [], $rules ), 'A flush on the next page load within 5 minutes.' );

		$this->transients = [];
		$this->new_request();
		$this->assertTrue( $this->fires( [], $rules ), 'After the throttle window.' );
	}
}
