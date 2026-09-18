<?php
/**
 * Main unit tests.
 *
 * @package Scanfully\Tests\Unit
 */

namespace Scanfully\Tests\Unit;

use Brain\Monkey\Filters;
use Scanfully\Main;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Main
 */
final class MainTest extends TestCase {

	public function test_get_returns_a_singleton(): void {
		$this->assertSame( Main::get(), Main::get() );
	}

	public function test_api_url_defaults_to_the_production_api(): void {
		$this->assertSame( Main::API_URL, Main::get_api_url() );
	}

	public function test_api_url_is_filterable(): void {
		Filters\expectApplied( 'scanfully_api_url' )
			->once()
			->with( Main::API_URL )
			->andReturn( 'https://api.scanfully.test/v1' );

		$this->assertSame( 'https://api.scanfully.test/v1', Main::get_api_url() );
	}

	public function test_dashboard_url_is_filterable(): void {
		Filters\expectApplied( 'scanfully_dashboard_url' )
			->once()
			->andReturn( 'https://app.scanfully.test' );

		$this->assertSame( 'https://app.scanfully.test', Main::get_dashboard_url() );
	}

	public function test_connect_url_defaults_to_the_production_connect_page(): void {
		$this->assertSame( Main::CONNECT_URL, Main::get_connect_url() );
	}
}
