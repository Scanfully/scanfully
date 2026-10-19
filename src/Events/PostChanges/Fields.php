<?php
/**
 * The post change fields class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\PostChanges;

/**
 * Class Fields
 *
 * Which custom fields (post meta) count as a change to a post, and how their
 * values are kept. A key counts when WordPress shows it in the Custom Fields
 * box (it doesn't start with `_`, which covers ACF field values, Rank Math
 * and most custom fields), or when it is one of the few `_` keys people do
 * edit. Every other `_` key is a plugin's own bookkeeping.
 *
 * @package Scanfully\Events\PostChanges
 */
final class Fields {

	/**
	 * Keys starting with `_` that people edit.
	 */
	public const COUNTED_PRIVATE_KEYS = [
		'_thumbnail_id',
		'_wp_page_template',
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'_yoast_wpseo_focuskw',
		'_yoast_wpseo_canonical',
		'_yoast_wpseo_meta-robots-noindex',
		'_elementor_data',
		'_fl_builder_data',
		'_bricks_page_content_2',
		'_regular_price',
		'_sale_price',
		'_sku',
	];

	/**
	 * The longest value kept as is; a longer value is only marked as large.
	 */
	public const MAX_VALUE_LENGTH = 200;

	/**
	 * Whether a change to a post meta key counts as a change to the post.
	 *
	 * @param string $key The meta key.
	 *
	 * @return bool
	 */
	public static function is_counted( string $key ): bool {
		if ( '' === $key ) {
			return false;
		}

		$counted = '_' !== $key[0] || in_array( $key, self::COUNTED_PRIVATE_KEYS, true );

		/**
		 * Filters whether a change to a post meta key counts as a change to
		 * the post, and is reported with the post's activity event.
		 *
		 * @param bool   $counted Whether the key counts.
		 * @param string $key     The meta key.
		 */
		return (bool) apply_filters( 'scanfully_post_change_meta_key', $counted, $key );
	}

	/**
	 * A meta value as it is kept: the value as a string when it is short
	 * enough, otherwise only that it is large.
	 *
	 * @param mixed $value The meta value.
	 *
	 * @return array{value: string|null, large: bool}
	 */
	public static function describe( $value ): array {
		if ( null === $value ) {
			return [
				'value' => null,
				'large' => false,
			];
		}

		if ( is_bool( $value ) ) {
			$text = $value ? '1' : '0';
		} elseif ( is_scalar( $value ) ) {
			$text = (string) $value;
		} elseif ( is_array( $value ) ) {
			$text = (string) wp_json_encode( $value );
		} else {
			$text = null;
		}

		if ( null === $text || mb_strlen( $text ) > self::MAX_VALUE_LENGTH ) {
			return [
				'value' => null,
				'large' => true,
			];
		}

		return [
			'value' => $text,
			'large' => false,
		];
	}

	/**
	 * A fingerprint of a meta value, to tell whether a value set again is
	 * the same.
	 *
	 * @param mixed $value The meta value.
	 *
	 * @return string
	 */
	public static function fingerprint( $value ): string {
		if ( is_scalar( $value ) || null === $value ) {
			return 's:' . md5( (string) $value );
		}

		try {
			return 'x:' . md5( serialize( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Only hashed, never stored.
		} catch ( \Throwable $e ) {
			return 'u:' . md5( (string) wp_rand() );
		}
	}
}
