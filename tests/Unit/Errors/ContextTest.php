<?php
/**
 * PHP error context unit tests.
 *
 * @package Scanfully\Tests\Unit\Errors
 */

namespace Scanfully\Tests\Unit\Errors;

use Scanfully\Errors\Context;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Errors\Context
 */
final class ContextTest extends TestCase {

	private const WEB = [
		'REQUEST_METHOD' => 'GET',
		'REQUEST_URI' => '/shop/?add-to-cart=12',
	];

	public function test_the_kind_of_request(): void {
		$none = [
			'cli' => false,
			'cron' => false,
			'ajax' => false,
			'rest' => false,
			'admin' => false,
		];

		$this->assertSame( 'front', Context::kind( self::WEB, $none ) );
		$this->assertSame( 'admin', Context::kind( self::WEB, [ 'admin' => true ] + $none ) );
		$this->assertSame(
			'ajax',
			Context::kind(
				self::WEB,
				[
					'ajax' => true,
					'admin' => true,
				] + $none
			)
		);
		$this->assertSame( 'cron', Context::kind( self::WEB, [ 'cron' => true ] + $none ) );
		$this->assertSame( 'cli', Context::kind( self::WEB, [ 'cli' => true ] + $none ) );
		$this->assertSame( 'rest', Context::kind( self::WEB, [ 'rest' => true ] + $none ) );
	}

	public function test_a_rest_request_is_known_by_its_path_before_wordpress_dispatches_it(): void {
		$none = [
			'cli' => false,
			'cron' => false,
			'ajax' => false,
			'rest' => false,
			'admin' => false,
		];

		$this->assertSame(
			'rest',
			Context::kind(
				[
					'REQUEST_METHOD' => 'GET',
					'REQUEST_URI' => '/wp-json/wc/v3/orders?page=2',
				],
				$none
			)
		);
		$this->assertSame(
			'rest',
			Context::kind(
				[
					'REQUEST_METHOD' => 'GET',
					'REQUEST_URI' => '/index.php/wp-json',
				],
				$none
			)
		);
		$this->assertSame(
			'rest',
			Context::kind(
				[
					'REQUEST_METHOD' => 'POST',
					'REQUEST_URI' => '/?rest_route=/wp/v2/posts',
				],
				$none
			)
		);
		$this->assertSame(
			'front',
			Context::kind(
				[
					'REQUEST_METHOD' => 'GET',
					'REQUEST_URI' => '/wp-json-export/',
				],
				$none
			)
		);
	}

	public function test_the_path_has_no_query_string_and_no_control_characters(): void {
		$this->assertSame( '/shop/', Context::path( self::WEB ) );
		$this->assertSame( '/a/b', Context::path( [ 'REQUEST_URI' => '/a/b#frag' ] ) );
		$this->assertSame( '/ab', Context::path( [ 'REQUEST_URI' => "/a\x00b" ] ) );
		$this->assertSame( '', Context::path( [] ) );
		$this->assertSame( 200, strlen( Context::path( [ 'REQUEST_URI' => '/' . str_repeat( 'a', 300 ) ] ) ) );
	}
}
