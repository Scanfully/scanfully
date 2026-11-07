<?php
/**
 * PHP error builder unit tests.
 *
 * @package Scanfully\Tests\Unit\Errors
 */

namespace Scanfully\Tests\Unit\Errors;

use Scanfully\Errors\Attribution;
use Scanfully\Errors\Builder;
use Scanfully\Errors\Fingerprint;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Errors\Builder
 */
final class BuilderTest extends TestCase {

	private const CONTEXT = [
		'request'     => 'front',
		'path'        => '/shop/',
		'php'         => '8.2.1',
		'wp'          => '6.8',
		'maintenance' => false,
	];

	private function attribution(): Attribution {
		return new Attribution(
			[
				'plugins' => '/srv/site/wp-content/plugins',
				'themes'  => '/srv/site/wp-content/themes',
				'content' => '/srv/site/wp-content',
				'abspath' => '/srv/site',
			],
			'6.8',
			static fn( string $kind, string $slug ): array => [
				'name' => ucfirst( $slug ),
				'version' => '1.0',
			]
		);
	}

	public function test_a_warning_with_its_stack_frames(): void {
		$error = Builder::build(
			[
				'type'    => E_WARNING,
				'message' => 'Undefined array key "total" for jane@scanfully.com',
				'file'    => '/srv/site/wp-content/plugins/shop/orders.php',
				'line'    => 42,
				'trace'   => [
					[
						'file' => '/srv/site/wp-content/plugins/shop/shop.php',
						'line' => 10,
						'function' => 'total',
						'class' => 'Shop\\Orders',
						'type' => '->',
						'args' => [ 'secret' ],
					],
					[
						'file' => '/srv/site/wp-includes/class-wp-hook.php',
						'line' => 324,
						'function' => '{closure}',
					],
					[
						'file' => '/srv/site/wp-includes/class-wp-hook.php',
						'line' => 348,
						'function' => 'apply_filters',
						'class' => 'WP_Hook',
						'type' => '->',
					],
				],
			],
			$this->attribution(),
			self::CONTEXT
		);

		$this->assertSame( 'warning', $error['level'] );
		$this->assertSame( 'Undefined array key "total" for [email]', $error['message'] );
		$this->assertSame( 'wp-content/plugins/shop/orders.php', $error['file'] );
		$this->assertSame( 42, $error['line'] );
		$this->assertSame( 'Shop\\Orders->total', $error['function'] );
		$this->assertSame(
			[
				[
					'file' => 'wp-content/plugins/shop/shop.php',
					'line' => 10,
					'function' => 'Shop\\Orders->total',
				],
				[
					'file' => 'wp-includes/class-wp-hook.php',
					'line' => 324,
					'function' => '{closure}',
				],
				[
					'file' => 'wp-includes/class-wp-hook.php',
					'line' => 348,
					'function' => 'WP_Hook->apply_filters',
				],
			],
			$error['trace']
		);
		$this->assertSame( 'plugin', $error['source']['kind'] );
		$this->assertSame( 'shop', $error['source']['slug'] );
		$this->assertSame( self::CONTEXT, $error['context'] );
		$this->assertSame( Fingerprint::of( 'warning', 'wp-content/plugins/shop/orders.php', 'Shop\\Orders->total', 'Undefined array key "total" for [email]' ), $error['fingerprint'] );
	}

	public function test_an_uncaught_exception_gets_its_message_and_trace_split(): void {
		$message = "Uncaught RuntimeException: Payment failed for order 12 in /srv/site/wp-content/plugins/shop/gateway.php:88\nStack trace:\n#0 /srv/site/wp-content/plugins/shop/checkout.php(31): Shop\\Gateway->pay(Object(WC_Order))\n#1 /srv/site/wp-includes/class-wp-hook.php(324): shop_checkout('')\n#2 {main}\n  thrown";
		$error   = Builder::build(
			[
				'type'    => E_ERROR,
				'message' => $message,
				'file'    => '/srv/site/wp-content/plugins/shop/gateway.php',
				'line'    => 88,
				'trace'   => [],
			],
			$this->attribution(),
			self::CONTEXT
		);

		$this->assertSame( 'fatal', $error['level'] );
		$this->assertSame( 'Uncaught RuntimeException: Payment failed for order 12', $error['message'] );
		$this->assertSame( 'Shop\\Gateway->pay', $error['function'] );
		$this->assertSame(
			[
				[
					'file' => 'wp-content/plugins/shop/checkout.php',
					'line' => 31,
					'function' => 'Shop\\Gateway->pay',
				],
				[
					'file' => 'wp-includes/class-wp-hook.php',
					'line' => 324,
					'function' => 'shop_checkout',
				],
			],
			$error['trace']
		);
		$this->assertSame( 'shop', $error['source']['slug'] );
	}

	public function test_an_exception_thrown_in_a_path_with_spaces_loses_its_place(): void {
		$split = Builder::split_exception( "Uncaught Exception: boom in C:\\Program Files\\site\\x.php:88\nStack trace:\n#0 {main}\n  thrown" );

		$this->assertSame( 'Uncaught Exception: boom', $split['message'] );
	}

	public function test_a_fatal_error_without_a_trace(): void {
		$error = Builder::build(
			[
				'type'    => E_ERROR,
				'message' => 'Call to undefined function wc_get_order() in /srv/site/wp-content/themes/storefront/functions.php',
				'file'    => '/srv/site/wp-content/themes/storefront/functions.php',
				'line'    => 9,
			],
			$this->attribution(),
			self::CONTEXT
		);

		$this->assertSame( 'Call to undefined function wc_get_order() in wp-content/themes/storefront/functions.php', $error['message'] );
		$this->assertSame( '', $error['function'] );
		$this->assertSame( [], $error['trace'] );
		$this->assertSame( 'theme', $error['source']['kind'] );
		$this->assertSame( 'storefront', $error['source']['slug'] );
	}

	public function test_a_core_error_is_attributed_through_the_trace(): void {
		$error = Builder::build(
			[
				'type'    => E_WARNING,
				'message' => 'foreach() argument must be of type array|object, null given',
				'file'    => '/srv/site/wp-includes/formatting.php',
				'line'    => 100,
				'trace'   => [
					[
						'file' => '/srv/site/wp-content/plugins/shop/shop.php',
						'line' => 10,
						'function' => 'wp_kses',
					],
				],
			],
			$this->attribution(),
			self::CONTEXT
		);

		$this->assertSame( 'wp_kses', $error['function'] );
		$this->assertSame( 'wp-includes/formatting.php', $error['file'] );
		$this->assertSame( 'plugin', $error['source']['kind'] );
	}

	public function test_a_notice_is_not_built(): void {
		$this->assertNull(
			Builder::build(
				[
					'type' => E_NOTICE,
					'message' => 'x',
					'file' => '',
					'line' => 0,
				],
				$this->attribution(),
				self::CONTEXT
			)
		);
		$this->assertNull(
			Builder::build(
				[
					'type' => E_DEPRECATED,
					'message' => 'x',
					'file' => '',
					'line' => 0,
				],
				$this->attribution(),
				self::CONTEXT
			)
		);
		$this->assertSame( 'fatal', Builder::level( E_PARSE ) );
		$this->assertSame( 'fatal', Builder::level( E_USER_ERROR ) );
		$this->assertSame( 'fatal', Builder::level( E_RECOVERABLE_ERROR ) );
		$this->assertSame( 'warning', Builder::level( E_USER_WARNING ) );
	}

	public function test_the_trace_is_capped(): void {
		$frames = [];
		for ( $i = 0; $i < 30; $i++ ) {
			$frames[] = [
				'file' => '/srv/site/a.php',
				'line' => $i,
				'function' => 'f' . $i,
			];
		}

		$this->assertCount( Builder::MAX_FRAMES, Builder::frames( $frames, $this->attribution() ) );
	}
}
