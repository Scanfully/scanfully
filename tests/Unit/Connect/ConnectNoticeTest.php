<?php
/**
 * Connect notice escaping unit tests.
 *
 * @package Scanfully\Tests\Unit\Connect
 */

namespace Scanfully\Tests\Unit\Connect;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Scanfully\Connect\Controller;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Connect\Controller
 */
final class ConnectNoticeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$_GET = [];
		// A translation with characters that must be escaped exactly once.
		Functions\when( '__' )->justReturn( 'Verbinding & "toegang" geweigerd' );
		Functions\when( 'esc_html__' )->alias( static fn( $text ) => htmlspecialchars( 'Verbinding & "toegang" geweigerd', ENT_QUOTES ) );
		Functions\when( 'esc_html' )->alias( static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	protected function tearDown(): void {
		$_GET = [];
		parent::tearDown();
	}

	/**
	 * Run the connect request routing and render the queued notice.
	 *
	 * @param array<string, string> $query Query parameters.
	 *
	 * @return string The notice HTML.
	 */
	private function render_notice( array $query ): string {
		$_GET = $query;
		$html = '';
		Actions\expectAdded( 'scanfully_connect_notices' )->whenHappen(
			static function ( $callback ) use ( &$html ) {
				ob_start();
				$callback();
				$html = (string) ob_get_clean();
			}
		);

		Controller::catch_connect_requests();

		return $html;
	}

	/**
	 * @dataProvider provide_notices
	 *
	 * @param array<string, string> $query Query parameters.
	 */
	public function test_notice_text_is_escaped_exactly_once( array $query ): void {
		$html = $this->render_notice( $query );

		$this->assertStringContainsString( 'Verbinding &amp; &quot;toegang&quot; geweigerd', $html );
		$this->assertStringNotContainsString( '&amp;amp;', $html );
	}

	/**
	 * @return array<string, array{array<string, string>}>
	 */
	public function provide_notices(): array {
		return [
			'connected'     => [ [ 'scanfully-connect-done' => '1' ] ],
			'access denied' => [ [ 'scanfully-connect-error' => 'access_denied' ] ],
			'unknown error' => [ [ 'scanfully-connect-error' => 'other' ] ],
		];
	}
}
