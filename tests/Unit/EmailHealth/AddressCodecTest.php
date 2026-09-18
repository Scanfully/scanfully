<?php
/**
 * AddressCodec unit tests.
 *
 * @package Scanfully\Tests\Unit\EmailHealth
 */

namespace Scanfully\Tests\Unit\EmailHealth;

use InvalidArgumentException;
use Scanfully\EmailHealth\AddressCodec;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\EmailHealth\AddressCodec
 */
final class AddressCodecTest extends TestCase {

	private const UUID = '0e5a6c9e-3b1f-4d2a-9c7e-5f8b2a1d4c6e';

	public function test_encode_returns_26_lowercase_base32_chars(): void {
		$encoded = AddressCodec::encode( self::UUID );

		$this->assertMatchesRegularExpression( '/^[a-z2-7]{26}$/', $encoded );
	}

	public function test_encode_and_decode_round_trip(): void {
		$this->assertSame( self::UUID, AddressCodec::decode( AddressCodec::encode( self::UUID ) ) );
	}

	public function test_encode_normalises_uppercase_uuids(): void {
		$this->assertSame( AddressCodec::encode( self::UUID ), AddressCodec::encode( strtoupper( self::UUID ) ) );
	}

	public function test_decode_accepts_uppercase_input(): void {
		$encoded = AddressCodec::encode( self::UUID );

		$this->assertSame( self::UUID, AddressCodec::decode( strtoupper( $encoded ) ) );
	}

	/**
	 * @dataProvider provide_invalid_uuids
	 *
	 * @param string $uuid Invalid UUID input.
	 */
	public function test_encode_rejects_invalid_uuids( string $uuid ): void {
		$this->expectException( InvalidArgumentException::class );

		AddressCodec::encode( $uuid );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function provide_invalid_uuids(): array {
		return [
			'empty'     => [ '' ],
			'too short' => [ '0e5a6c9e-3b1f-4d2a-9c7e' ],
			'non-hex'   => [ 'zz5a6c9e-3b1f-4d2a-9c7e-5f8b2a1d4c6e' ],
		];
	}

	/**
	 * @dataProvider provide_invalid_segments
	 *
	 * @param string $segment Invalid encoded segment.
	 */
	public function test_decode_rejects_invalid_segments( string $segment ): void {
		$this->expectException( InvalidArgumentException::class );

		AddressCodec::decode( $segment );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function provide_invalid_segments(): array {
		return [
			'empty'         => [ '' ],
			'too short'     => [ 'abc' ],
			'invalid chars' => [ str_repeat( '1', 26 ) ],
		];
	}
}
