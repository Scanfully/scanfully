<?php
/**
 * The Font Library batch class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\SiteEditor;

use Scanfully\Events\Content\MinuteStore;
use Scanfully\Events\ContentEvent;

/**
 * Class Fonts
 *
 * The minute's job of the Font Library: joins what each person did to each
 * font family within the minute into one row. Installing a font sends one
 * request for the family and one per variant, so the requests of one
 * install become one FontInstalled row; a family removed (with its
 * variants) one FontDeleted row. A family added and removed within the
 * minute, or a variant added and removed again, is left out.
 *
 * @package Scanfully\Events\SiteEditor
 */
final class Fonts {

	/**
	 * The most rows one minute sends; fonts are installed a few at a time.
	 */
	private const MAX_ROWS = 30;

	/**
	 * Action Scheduler callback: report a minute's Font Library changes.
	 *
	 * @param int $bucket The minute.
	 *
	 * @return void
	 */
	public static function send( int $bucket ): void {
		$rows = [];
		foreach ( self::rows( MinuteStore::take( Collector::KIND_FONTS, $bucket ) ) as [ $type, $user, $data ] ) {
			if ( count( $rows ) >= self::MAX_ROWS ) {
				break;
			}
			$rows[] = [ $type, $user, $data ];
		}

		foreach ( $rows as [ $type, $user, $data ] ) {
			ContentEvent::queue( $type, $user, $data );
		}
	}

	/**
	 * The rows of a minute's entries: per person and family, what was
	 * installed and what was removed.
	 *
	 * @param array<int, array<string, mixed>> $entries The entries, oldest first.
	 *
	 * @return array<int, array{0: string, 1: array{id: int, name: string}, 2: array<string, mixed>}>
	 */
	public static function rows( array $entries ): array {
		$families = [];
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry['items'] ?? null ) ) {
				continue;
			}
			$user = [
				'id'   => (int) ( $entry['user']['id'] ?? 0 ),
				'name' => (string) ( $entry['user']['name'] ?? '' ),
			];

			foreach ( $entry['items'] as $item ) {
				if ( ! is_array( $item ) || (int) ( $item['id'] ?? 0 ) < 1 ) {
					continue;
				}
				$key = $user['id'] . '|' . $user['name'] . '|' . (int) $item['id'];
				if ( ! isset( $families[ $key ] ) ) {
					$families[ $key ] = [
						'user'    => $user,
						'name'    => (string) ( $item['name'] ?? '' ),
						'slug'    => (string) ( $item['slug'] ?? '' ),
						'added'   => false,
						'removed' => false,
						'faces'   => [],
					];
				}
				if ( 'added' === ( $item['family'] ?? '' ) ) {
					$families[ $key ]['added'] = true;
				} elseif ( 'removed' === ( $item['family'] ?? '' ) ) {
					$families[ $key ]['removed'] = true;
				}
				foreach ( (array) ( $item['faces'] ?? [] ) as $face ) {
					if ( ! is_array( $face ) ) {
						continue;
					}
					$variant = (string) ( $face['style'] ?? 'normal' ) . '|' . (string) ( $face['weight'] ?? '400' );
					$step    = 'added' === ( $face['action'] ?? '' ) ? 1 : -1;
					$families[ $key ]['faces'][ $variant ] = ( $families[ $key ]['faces'][ $variant ] ?? 0 ) + $step;
				}
			}
		}

		$rows = [];
		foreach ( $families as $family ) {
			// Installed and removed again within the minute.
			if ( $family['added'] && $family['removed'] ) {
				continue;
			}

			$added   = [];
			$removed = [];
			foreach ( $family['faces'] as $variant => $count ) {
				if ( $count > 0 ) {
					$added[] = self::variant( (string) $variant );
				} elseif ( $count < 0 ) {
					$removed[] = self::variant( (string) $variant );
				}
			}

			$base = [
				'name' => $family['name'],
				'slug' => $family['slug'],
			];
			if ( $family['removed'] ) {
				$rows[] = [
					'FontDeleted',
					$family['user'],
					$base + [
						'family' => true,
						'variants' => self::sorted( $removed ),
					],
				];
				continue;
			}
			if ( $family['added'] || [] !== $added ) {
				$rows[] = [
					'FontInstalled',
					$family['user'],
					$base + [
						'family' => $family['added'],
						'variants' => self::sorted( $added ),
					],
				];
			}
			if ( [] !== $removed ) {
				$rows[] = [
					'FontDeleted',
					$family['user'],
					$base + [
						'family' => false,
						'variants' => self::sorted( $removed ),
					],
				];
			}
		}

		return $rows;
	}

	/**
	 * A variant from its key.
	 *
	 * @param string $key `style|weight`.
	 *
	 * @return array{style: string, weight: string}
	 */
	private static function variant( string $key ): array {
		[ $style, $weight ] = array_pad( explode( '|', $key, 2 ), 2, '400' );

		return [
			'style'  => $style,
			'weight' => $weight,
		];
	}

	/**
	 * Variants by weight, then upright before italic.
	 *
	 * @param array<int, array{style: string, weight: string}> $variants The variants.
	 *
	 * @return array<int, array{style: string, weight: string}>
	 */
	private static function sorted( array $variants ): array {
		usort(
			$variants,
			static function ( array $a, array $b ): int {
				$weight = (int) $a['weight'] <=> (int) $b['weight'];

				return 0 !== $weight ? $weight : strcmp( 'normal' === $a['style'] ? '0' : $a['style'], 'normal' === $b['style'] ? '0' : $b['style'] );
			}
		);

		return $variants;
	}
}
