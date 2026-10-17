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
		// WooCommerce Subscriptions is "active" in every test, so the
		// subscription paths always run; orders have none unless a test says so.
		Functions\when( 'wcs_get_subscriptions_for_order' )->justReturn( [] );
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
	 * @return \Mockery\MockInterface&\WC_Order
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

	public function test_a_probe_payment_tags_and_cancels_the_subscriptions_it_created(): void {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_scanfully_probe_order', 'true' );
		$order->shouldReceive( 'set_status' )->once()->with( 'cancelled', Mockery::type( 'string' ) );
		$order->shouldReceive( 'save' )->once();

		$pending = Mockery::mock( 'WC_Order' );
		$pending->shouldReceive( 'update_meta_data' )->once()->with( '_scanfully_probe_order', 'true' );
		$pending->shouldReceive( 'save_meta_data' )->once();
		$pending->shouldReceive( 'has_status' )->with( 'cancelled' )->andReturn( false );
		$pending->shouldReceive( 'update_status' )->once()->with( 'cancelled', Mockery::type( 'string' ) );

		// Subscriptions already cancelled it along with the parent order.
		$already = Mockery::mock( 'WC_Order' );
		$already->shouldReceive( 'update_meta_data' )->once()->with( '_scanfully_probe_order', 'true' );
		$already->shouldReceive( 'save_meta_data' )->once();
		$already->shouldReceive( 'has_status' )->with( 'cancelled' )->andReturn( true );
		$already->shouldNotReceive( 'update_status' );

		Functions\when( 'wcs_get_subscriptions_for_order' )->justReturn(
			[
				101 => $pending,
				102 => $already,
			]
		);
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'home_url' )->justReturn( 'https://shop.test/' );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );

		$result = ( new ProbeGateway() )->process_payment( 12 );

		$this->assertSame( 'success', $result['result'] );
	}

	public function test_a_subscription_already_cancelled_with_its_order_is_not_cancelled_again(): void {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_scanfully_probe_order', 'true' );
		$order->shouldReceive( 'set_status' )->once()->with( 'cancelled', Mockery::type( 'string' ) );
		$order->shouldReceive( 'save' )->once();

		// Loaded before the order is saved: still pending, only tagged.
		$stale = Mockery::mock( 'WC_Order' );
		$stale->shouldReceive( 'update_meta_data' )->once()->with( '_scanfully_probe_order', 'true' );
		$stale->shouldReceive( 'save_meta_data' )->once();
		$stale->shouldNotReceive( 'update_status' );

		// Loaded after: Subscriptions cancelled it along with the order.
		$fresh = Mockery::mock( 'WC_Order' );
		$fresh->shouldReceive( 'has_status' )->with( 'cancelled' )->andReturn( true );
		$fresh->shouldNotReceive( 'update_status' );

		$loads = 0;
		Functions\when( 'wcs_get_subscriptions_for_order' )->alias(
			static function () use ( $stale, $fresh, &$loads ) {
				++$loads;
				return [ 101 => 1 === $loads ? $stale : $fresh ];
			}
		);
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'home_url' )->justReturn( 'https://shop.test/' );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );

		( new ProbeGateway() )->process_payment( 12 );

		$this->assertSame( 2, $loads, 'The subscriptions are read again after the order is saved.' );
	}

	public function test_emails_about_probe_subscriptions_are_suppressed(): void {
		$subscription = $this->order();

		$this->assertSame( '', ProbeGateway::suppress_email_for_probe( 'shop@scanfully.com', $subscription ) );
		$this->assertSame( 'shop@scanfully.com', ProbeGateway::suppress_email_for_probe( 'shop@scanfully.com', $this->order( false ) ) );
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
				if ( 'shop_order' !== $args['type'] ) {
					return [];
				}
				$queried_statuses[] = $args['status'];
				return [ 'pending' ] === $args['status'] ? [ $pending ] : [ $cancelled ];
			}
		);

		Cleanup::run();

		$this->assertSame( [ [ 'pending' ], [ 'cancelled' ] ], $queried_statuses );
	}

	public function test_cleanup_handles_probe_subscriptions_before_probe_orders(): void {
		$pending   = $this->order();
		$cancelled = $this->order();
		$pending->shouldReceive( 'update_status' )->once()->with( 'cancelled', Mockery::type( 'string' ) );
		$cancelled->shouldReceive( 'delete' )->once()->with( true );

		$queries = [];
		Functions\when( 'wc_get_orders' )->alias(
			function ( array $args ) use ( $pending, $cancelled, &$queries ) {
				$queries[] = $args['type'] . ':' . $args['status'][0];
				if ( 'shop_subscription' !== $args['type'] ) {
					return [];
				}
				return [ 'pending' ] === $args['status'] ? [ $pending ] : [ $cancelled ];
			}
		);

		Cleanup::run();

		$this->assertSame( [ 'shop_subscription:pending', 'shop_subscription:cancelled', 'shop_order:pending', 'shop_order:cancelled' ], $queries );
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

	public function test_the_probe_gateway_lets_subscriptions_cancel_probe_subscriptions(): void {
		// WC_Subscription::can_be_updated_to( 'cancelled' ) requires the
		// subscription's gateway to declare this feature; without it the
		// subscriptions a probe order creates can never leave pending.
		$this->assertContains( 'subscription_cancellation', ( new ProbeGateway() )->supports );
	}

	public function test_cleanup_goes_on_when_a_probe_subscription_cannot_be_cancelled(): void {
		$stuck = $this->order();
		$stuck->shouldReceive( 'get_id' )->andReturn( 101 );
		$stuck->shouldReceive( 'update_status' )->once()->andThrow( new \Exception( 'Unable to change subscription status to "cancelled".' ) );
		$next          = $this->order();
		$pending_order = $this->order();
		$next->shouldReceive( 'update_status' )->once()->with( 'cancelled', Mockery::type( 'string' ) );
		$pending_order->shouldReceive( 'update_status' )->once()->with( 'cancelled', Mockery::type( 'string' ) );

		$logger = Mockery::mock();
		$logger->shouldReceive( 'warning' )->once()->with( Mockery::pattern( '/could not cancel probe order #101/' ), [ 'source' => 'scanfully' ] );
		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		$queries = [];
		Functions\when( 'wc_get_orders' )->alias(
			function ( array $args ) use ( $stuck, $next, $pending_order, &$queries ) {
				$queries[] = $args['type'] . ':' . $args['status'][0];
				if ( [ 'pending' ] !== $args['status'] ) {
					return [];
				}
				return 'shop_subscription' === $args['type'] ? [ $stuck, $next ] : [ $pending_order ];
			}
		);

		Cleanup::run();

		// The subscription that could not be cancelled did not end the run:
		// the next subscription and every later pass were still handled.
		$this->assertSame( [ 'shop_subscription:pending', 'shop_subscription:cancelled', 'shop_order:pending', 'shop_order:cancelled' ], $queries );
	}

	public function test_cleanup_goes_on_when_a_probe_order_cannot_be_deleted(): void {
		$stuck = $this->order();
		$stuck->shouldReceive( 'get_id' )->andReturn( 7 );
		$stuck->shouldReceive( 'delete' )->once()->with( true )->andThrow( new \RuntimeException( 'Database error.' ) );
		$next = $this->order();
		$next->shouldReceive( 'delete' )->once()->with( true )->andReturn( true );

		$logger = Mockery::mock();
		$logger->shouldReceive( 'warning' )->once()->with( Mockery::pattern( '/could not delete probe order #7/' ), [ 'source' => 'scanfully' ] );
		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		Functions\when( 'wc_get_orders' )->alias(
			static function ( array $args ) use ( $stuck, $next ) {
				return 'shop_order' === $args['type'] && [ 'cancelled' ] === $args['status'] ? [ $stuck, $next ] : [];
			}
		);

		Cleanup::run();
	}

	public function test_cleanup_stops_batching_when_a_full_batch_changes_nothing(): void {
		$stuck = [];
		for ( $i = 1; $i <= 50; $i++ ) {
			$order = $this->order();
			$order->shouldReceive( 'get_id' )->andReturn( $i );
			$order->shouldReceive( 'update_status' )->once()->andThrow( new \Exception( 'refused' ) );
			$stuck[] = $order;
		}
		$logger = Mockery::mock();
		$logger->shouldReceive( 'warning' )->times( 50 );
		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		$pending_queries = 0;
		Functions\when( 'wc_get_orders' )->alias(
			static function ( array $args ) use ( $stuck, &$pending_queries ) {
				if ( 'shop_order' !== $args['type'] || [ 'pending' ] !== $args['status'] ) {
					return [];
				}
				++$pending_queries;
				return $stuck;
			}
		);

		Cleanup::run();

		// A full batch that refused every change is not queried again and
		// again: the same rows would only come back.
		$this->assertSame( 1, $pending_queries );
	}
}
