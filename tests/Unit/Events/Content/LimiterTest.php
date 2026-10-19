<?php
/**
 * Content event limiter unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\Content
 */

namespace Scanfully\Tests\Unit\Events\Content;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Scanfully\Events\Content\Limiter;
use Scanfully\Events\Content\Report;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\Content\Limiter
 */
final class LimiterTest extends TestCase {

	/**
	 * In-memory transient store.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	/**
	 * How many transients were written.
	 *
	 * @var int
	 */
	private int $writes = 0;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_transient' )->alias( fn( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ) {
				++$this->writes;
				$this->transients[ $key ] = $value;
				return true;
			}
		);
	}

	public function test_a_request_takes_room_for_all_its_events_in_one_write(): void {
		$this->assertSame( 12, Limiter::take( Report::KIND_POST, 12 ) );
		$this->assertSame( 1, $this->writes );
	}

	public function test_events_over_the_cap_get_no_room(): void {
		Filters\expectApplied( 'scanfully_post_saved_events_per_minute' )->andReturn( 5 );

		$this->assertSame( 3, Limiter::take( Report::KIND_POST, 3 ) );
		$this->assertSame( 2, Limiter::take( Report::KIND_POST, 4 ) );
		$this->assertSame( 0, Limiter::take( Report::KIND_POST, 1 ) );
	}

	public function test_terms_have_a_cap_of_their_own(): void {
		Filters\expectApplied( 'scanfully_post_saved_events_per_minute' )->andReturn( 1 );
		Filters\expectApplied( 'scanfully_term_events_per_minute' )->andReturn( 2 );

		$this->assertSame( 1, Limiter::take( Report::KIND_POST, 3 ) );
		$this->assertSame( 2, Limiter::take( Report::KIND_TERM, 3 ) );
	}

	public function test_settings_menus_and_widgets_have_caps_of_their_own(): void {
		Filters\expectApplied( 'scanfully_settings_events_per_minute' )->andReturn( 1 );
		Filters\expectApplied( 'scanfully_menu_events_per_minute' )->andReturn( 2 );
		Filters\expectApplied( 'scanfully_widget_events_per_minute' )->andReturn( 3 );

		$this->assertSame( 1, Limiter::take( Limiter::KIND_SETTING, 5 ) );
		$this->assertSame( 2, Limiter::take( Limiter::KIND_MENU, 5 ) );
		$this->assertSame( 3, Limiter::take( Limiter::KIND_WIDGET, 5 ) );
	}

	public function test_no_cap_lets_everything_through_without_a_write(): void {
		Filters\expectApplied( 'scanfully_post_saved_events_per_minute' )->andReturn( 0 );

		$this->assertSame( 500, Limiter::take( Report::KIND_POST, 500 ) );
		$this->assertSame( 0, $this->writes );
	}
}
