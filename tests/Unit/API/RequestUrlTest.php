<?php
/**
 * API request URL unit tests.
 *
 * @package Scanfully\Tests\Unit\API
 */

namespace Scanfully\Tests\Unit\API;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Scanfully\API;
use Scanfully\Connect\Buttons;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\API\Request
 * @covers \Scanfully\Connect\Buttons::dashboard
 */
final class RequestUrlTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// A site ID with characters that must not reach a URL path unencoded.
		Functions\when( 'get_option' )->justReturn( 'site 1/../x' );
	}

	/**
	 * @dataProvider provide_requests
	 *
	 * @param string $class Request class.
	 */
	public function test_the_site_id_is_encoded_in_the_url_path( string $class ): void {
		$url = ( new $class() )->get_url( '' );

		$this->assertStringContainsString( '/sites/site%201%2F..%2Fx/', $url );
		$this->assertStringNotContainsString( '/../', $url );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function provide_requests(): array {
		return [
			'events'                   => [ API\EventRequest::class ],
			'site data'                => [ API\SiteDataRequest::class ],
			'directories'              => [ API\SiteDirectoriesRequest::class ],
			'woocheckout config'       => [ API\WooCheckoutConfigRequest::class ],
			'email provision'          => [ API\EmailDeliverabilityProvisionRequest::class ],
			'email attempt'            => [ API\EmailDeliverabilityAttemptRequest::class ],
			'email attempt result'     => [ API\EmailDeliverabilityAttemptResultRequest::class ],
			'email state'              => [ API\EmailDeliverabilityStateRequest::class ],
		];
	}

	public function test_the_dashboard_button_follows_the_dashboard_url_filter(): void {
		$this->stubTranslationFunctions();
		Functions\when( 'esc_url' )->returnArg();
		Filters\expectApplied( 'scanfully_dashboard_url' )->andReturn( 'https://app.scanfully.test' );

		ob_start();
		Buttons::dashboard();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'https://app.scanfully.test/sites/site%201%2F..%2Fx/dashboard', $html );
	}
}
