<?php
/**
 * Address codec for the email-deliverability inbound address.
 *
 * Mirrors the API's internal/app/service/emailhealth/address.go: lowercase
 * base32 (no padding) of the 16 raw UUID bytes. A canonical hyphenated UUID
 * round-trips losslessly through encode/decode.
 *
 * @package Scanfully
 */

namespace Scanfully\EmailHealth;

/**
 * Pure helpers; no WordPress dependencies.
 */
class AddressCodec {

	private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

	/**
	 * Encode a hyphenated UUID into a 26-char lowercase base32 string.
	 *
	 * @param string $uuid Canonical hyphenated UUID (8-4-4-4-12 hex digits).
	 *
	 * @return string The encoded address segment.
	 *
	 * @throws \InvalidArgumentException If $uuid is not a valid UUID.
	 */
	public static function encode( string $uuid ): string {
		$hex = self::strip_uuid( $uuid );
		$raw = hex2bin( $hex );
		if ( false === $raw || 16 !== strlen( $raw ) ) {
			throw new \InvalidArgumentException( 'invalid uuid' );
		}
		return self::base32_encode_nopad( $raw );
	}

	/**
	 * Decode a 26-char lowercase base32 string into a hyphenated UUID.
	 *
	 * @param string $encoded The encoded segment.
	 *
	 * @return string Canonical hyphenated UUID.
	 *
	 * @throws \InvalidArgumentException If the input is not exactly 26 chars
	 *                                   of base32 alphabet.
	 */
	public static function decode( string $encoded ): string {
		$encoded = strtolower( $encoded );
		if ( 26 !== strlen( $encoded ) || 1 !== preg_match( '/^[a-z2-7]{26}$/', $encoded ) ) {
			throw new \InvalidArgumentException( 'invalid base32 segment' );
		}
		$raw = self::base32_decode_nopad( $encoded );
		if ( 16 !== strlen( $raw ) ) {
			throw new \InvalidArgumentException( 'decoded length mismatch' );
		}
		$hex = bin2hex( $raw );
		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}

	/**
	 * Strip dashes from a UUID and return its 32-char lowercase hex form.
	 *
	 * @param string $uuid Canonical hyphenated UUID.
	 *
	 * @return string
	 * @throws \InvalidArgumentException When the input is not a valid UUID.
	 */
	private static function strip_uuid( string $uuid ): string {
		$hex = strtolower( str_replace( '-', '', $uuid ) );
		if ( 32 !== strlen( $hex ) || 1 !== preg_match( '/^[0-9a-f]{32}$/', $hex ) ) {
			throw new \InvalidArgumentException( 'invalid uuid' );
		}
		return $hex;
	}

	/**
	 * Encode raw bytes using lowercase base32 with no padding.
	 *
	 * @param string $raw Raw byte string.
	 *
	 * @return string
	 */
	private static function base32_encode_nopad( string $raw ): string {
		$bits = '';
		$len  = strlen( $raw );
		for ( $i = 0; $i < $len; $i++ ) {
			$bits .= str_pad( decbin( ord( $raw[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		$bl  = strlen( $bits );
		for ( $i = 0; $i + 5 <= $bl; $i += 5 ) {
			$out .= self::ALPHABET[ bindec( substr( $bits, $i, 5 ) ) ];
		}
		$rem = $bl % 5;
		if ( 0 !== $rem ) {
			$out .= self::ALPHABET[ bindec( str_pad( substr( $bits, $bl - $rem ), 5, '0', STR_PAD_RIGHT ) ) ];
		}
		return $out;
	}

	/**
	 * Decode a lowercase base32 (no padding) string back to raw bytes.
	 *
	 * @param string $encoded Encoded string.
	 *
	 * @return string
	 * @throws \InvalidArgumentException When the input contains invalid characters.
	 */
	private static function base32_decode_nopad( string $encoded ): string {
		$map  = array_flip( str_split( self::ALPHABET ) );
		$bits = '';
		$len  = strlen( $encoded );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $encoded[ $i ];
			if ( ! isset( $map[ $ch ] ) ) {
				throw new \InvalidArgumentException( 'invalid base32 char' );
			}
			$bits .= str_pad( decbin( $map[ $ch ] ), 5, '0', STR_PAD_LEFT );
		}
		$bytes = '';
		$bl    = strlen( $bits );
		for ( $i = 0; $i + 8 <= $bl; $i += 8 ) {
			$bytes .= chr( bindec( substr( $bits, $i, 8 ) ) );
		}
		return $bytes;
	}
}
