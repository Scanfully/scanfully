<?php
/**
 * Probe order lifecycle unit tests: cancelling, tagging and cleanup.
 *
 * @package Scanfully\Tests\Unit\WooCheckout
 */

namespace Scanfully\Tests\Unit\WooCheckout;

use Brain\Monkey\Functions;
use Mockery;
use ReflectionProperty;
use Scanfully\WooCheckout\Cleanup;
use Scanfully\WooCheckout\Controller;
use Scanfully\WooCheckout\ProbeGateway;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\WooCheckout\ProbeGateway
 * @covers \Scanfully\WooCheckout\Cleanup
 */
final class ProbeOrderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->stubTranslationFunctions();
		$this->set_probe_request( false );
	}

	protected function tearDown(): void {
		// Mockery expectations (shouldReceive/shouldNotReceive) are the
		// assertions in several tests; count them so PHPUnit sees them.
		$this->addToAssertionCount( Mockery::getContainer()->mockery_getExpectationCount() );

		$property = new ReflectionProperty( Controller::class, 'probe_request_cache' );
		$property->setAccessible( true );
		$property->setValue( null, null );
		parent::tearDown();
	}

	/**
	 * Set the memoised probe-request result.
	 *
	 * @param bool $is_probe Whether the current request is a probe.
	 */
	private function set_probe_request( bool $is_probe ): void {
		$property = new ReflectionProperty( Controller::class, 'probe_request_cache' );
		$property->setAccessible( true );
		$property->setValue( null, $is_probe );
	}

	/**
	 * An order double.
	 *
	 * @param bool   $tagged         Whether the probe meta is set.
	 * @param string $payment_method Payment method ID.
	 *
	 * @return \Mockery\MockInterface
	 */
	private function order( bool $tagged = true, string $payment_method = Controller::PROBE_GATEWAY_ID ) {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->with( '_scanfully_probe_order' )->andReturn( $tagged ? 'true' : '' );
		$order->shouldReceive( 'get_payment_method' )->andReturn( $payment_method );
		return $order;
	}

	public function test_a_probe_payment_cancels_the_order(): void {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_scanfully_probe_order', 'true' );
		$order->shouldReceive( 'set_status' )->once()->with( 'cancelled', Mockery::type( 'string' ) );
		$order->shouldReceive( 'save' )->once();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'home_url' )->justReturn( 'https://shop.test/' );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );

		$result = ( new ProbeGateway() )->process_payment( 12 );

		$this->assertSame( 'success', $result['result'] );
		$this->assertStringContainsString( 'scanfully_probe_psp=1', $result['redirect'] );
	}

	public function test_orders_created_during_a_probe_are_tagged(): void {
		$this->set_probe_request( true );
		$_SERVER['HTTP_X_SCANFULLY_PROBE'] = 'sf-1-abcd:sig';
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_scanfully_probe_order', 'true' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_scanfully_scan_id', 'sf-1-abcd' );
		$order->shouldReceive( 'get_id' )->andReturn( 12 );
		$order->shouldReceive( 'save_meta_data' )->once();

		ProbeGateway::tag_new_probe_order( $order );

		unset( $_SERVER['HTTP_X_SCANFULLY_PROBE'] );
	}

	public function test_orders_created_outside_a_probe_are_not_tagged(): void {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldNotReceive( 'update_meta_data' );

		ProbeGateway::tag_new_probe_order( $order );
	}

	public function test_cleanup_cancels_stale_pending_probe_orders_and_deletes_only_cancelled_ones(): void {
		$pending   = $this->order();
		$cancelled = $this->order();
		$pending->shouldReceive( 'update_status' )->once()->with( 'cancelled', Mockery::type( 'string' ) );
		$pending->shouldNotReceive( 'delete' );
		$cancelled->shouldReceive( 'delete' )->once()->with( true );

		$queried_statuses = [];
		Functions\when( 'wc_get_orders' )->alias(
			function ( array $args ) use ( $pending, $cancelled, &$queried_statuses ) {
				$queried_statuses[] = $args['status'];
				return [ 'pending' ] === $args['status'] ? [ $pending ] : [ $cancelled ];
			}
		);

		Cleanup::run();

		$this->assertSame( [ [ 'pending' ], [ 'cancelled' ] ], $queried_statuses );
	}

	public function test_cleanup_never_touches_orders_that_are_not_probe_orders(): void {
		$untagged    = $this->order( false );
		$real_method = $this->order( true, 'stripe' );
		foreach ( [ $untagged, $real_method ] as $order ) {
			$order->shouldNotReceive( 'update_status' );
			$order->shouldNotReceive( 'delete' );
		}

		// Simulates a data store that ignores the meta query.
		Functions\when( 'wc_get_orders' )->justReturn( [ $untagged, $real_method ] );

		Cleanup::run();
	}
}
