<?php
/**
 * Settings page unit tests: a subsite the network monitors.
 *
 * @package Scanfully\Tests\Unit\Connect
 */

namespace Scanfully\Tests\Unit\Connect;

use Brain\Monkey\Functions;
use Scanfully\Connect\Multisite;
use Scanfully\Connect\Page;
use Scanfully\Connect\Target;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Connect\Page::render_network_child
 * @covers \Scanfully\Connect\Multisite::network_monitored_note
 * @covers \Scanfully\Connect\Multisite::connect_also_note
 */
final class PageNetworkChildTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->echoArg();
	}

	public function test_a_monitored_subsite_says_it_is_monitored_through_the_network(): void {
		ob_start();
		Page::render_network_child( Target::child( 'child-2', 2 ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Monitored through the network', $html );
		$this->assertStringContainsString( "Monitored through the network's connection: its activity, plugins, health, PHP errors and WooCommerce go to the network owner's Scanfully account.", $html );
	}

	public function test_another_site_shows_nothing(): void {
		ob_start();
		Page::render_network_child( null );

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_connecting_on_its_own_is_worded_as_optional(): void {
		$this->assertStringStartsWith( 'Optional:', Multisite::connect_also_note() );
	}
}
