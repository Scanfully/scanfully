<?php
/**
 * Connect page scope label unit tests.
 *
 * @package Scanfully\Tests\Unit\Connect
 */

namespace Scanfully\Tests\Unit\Connect;

use Brain\Monkey\Functions;
use Scanfully\Connect\OAuth;
use Scanfully\Connect\Page;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Connect\Page
 */
final class PageScopeLabelTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
	}

	public function test_every_scope_scanfully_grants_has_a_label(): void {
		$this->assertSame( 'Read site data: health, plugins and themes, and activity', Page::scope_label( 'wordpress:read' ) );
		$this->assertSame(
			'Update, install, activate, deactivate and remove plugins and themes, update WordPress and translations',
			Page::scope_label( 'wordpress:manage' )
		);
		$this->assertSame( 'Sign you in to WP admin', Page::scope_label( 'wordpress:login' ) );
	}

	public function test_an_unknown_scope_is_shown_by_its_name(): void {
		$this->assertSame( 'wordpress:database', Page::scope_label( 'wordpress:database' ) );
	}

	public function test_the_plugin_still_asks_only_to_read(): void {
		$this->assertSame( [ 'wordpress:read' ], OAuth::SCOPES );
		$this->assertSame( 'wordpress:manage', OAuth::SCOPE_MANAGE );
		$this->assertSame( 'wordpress:login', OAuth::SCOPE_LOGIN );
	}
}
