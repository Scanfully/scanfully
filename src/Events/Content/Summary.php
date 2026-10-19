<?php
/**
 * The content summary class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\Content;

/**
 * Class Summary
 *
 * Turns many changed posts, terms or files into summary rows
 * (`PostBulkAction`, `TermBulkAction`, `MediaBulkAction`): one row per
 * action and post type (or taxonomy), with every post, term or file in it,
 * so each one can still be found in Scanfully.
 *
 * An item is one post, term or file that changed:
 * - `action`: what happened (`created`, `updated`, `published`, `drafted`,
 *   `privated`, `trashed`, `restored`, `deleted` for posts; `created`,
 *   `changed`, `deleted` for terms; `uploaded`, `changed`, `attached`,
 *   `detached`, `trashed`, `restored`, `deleted` for files);
 * - `group`: the post type or taxonomy (`attachment` for files);
 * - `id` and `title` (a term's name), and a file's `name`;
 * - optional `changed` (the parts that changed), `terms` (term taxonomy IDs
 *   added and removed, per taxonomy), `posts` (posts that had a deleted
 *   term), `parent` (the post a file was attached to), `expired`
 *   (WordPress emptied the trash itself);
 * - `event`: how to report it on its own (`hook`, `args`), when the request
 *   still has everything for that.
 *
 * @package Scanfully\Events\Content
 */
final class Summary {

	/**
	 * The most posts or terms in one summary row; more make another row.
	 * With titles cut at MAX_TITLE characters, a row stays well within the
	 * size the API accepts for these events.
	 */
	public const MAX_ITEMS_PER_ROW = 2000;

	/**
	 * The summary row's event type per kind of item.
	 */
	private const TYPES = [
		Report::KIND_POST  => 'PostBulkAction',
		Report::KIND_TERM  => 'TermBulkAction',
		Report::KIND_MEDIA => 'MediaBulkAction',
	];

	/**
	 * The longest title or name kept in a summary row.
	 */
	public const MAX_TITLE = 200;

	/**
	 * The most term taxonomy IDs kept per taxonomy and side (added, removed)
	 * in a row about posts.
	 */
	private const MAX_TERM_IDS = 50;

	/**
	 * A title or name as summary rows keep it: plain text, cut.
	 *
	 * @param string $title The title, as WordPress stores it.
	 *
	 * @return string
	 */
	public static function title( string $title ): string {
		$title = html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );

		return mb_strlen( $title ) > self::MAX_TITLE ? mb_substr( $title, 0, self::MAX_TITLE ) : $title;
	}

	/**
	 * Items grouped by what happened to them: action, post type or
	 * taxonomy, and (for deletes) whether WordPress emptied the trash itself.
	 *
	 * @param array<int, array<string, mixed>> $items The items.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function group( array $items ): array {
		$groups = [];
		foreach ( $items as $item ) {
			$key              = $item['action'] . '|' . $item['group'] . ( ! empty( $item['expired'] ) ? '|expired' : '' );
			$groups[ $key ][] = $item;
		}

		return $groups;
	}

	/**
	 * The summary rows of one group: the event type, its data and the
	 * items in it.
	 *
	 * @param string                           $kind     Report::KIND_POST, Report::KIND_TERM or Report::KIND_MEDIA.
	 * @param array<int, array<string, mixed>> $items    The items of the group.
	 * @param bool                             $overflow Whether these were over the per-minute cap.
	 *
	 * @return array<int, array{type: string, data: array<string, mixed>, items: array<int, array<string, mixed>>}>
	 */
	public static function rows( string $kind, array $items, bool $overflow = false ): array {
		if ( [] === $items ) {
			return [];
		}

		$chunks = array_chunk( $items, self::MAX_ITEMS_PER_ROW );
		$rows   = [];
		foreach ( $chunks as $index => $chunk ) {
			switch ( $kind ) {
				case Report::KIND_TERM:
					$data = self::term_row( $chunk );
					break;
				case Report::KIND_MEDIA:
					$data = self::media_row( $chunk );
					break;
				default:
					$data = self::post_row( $chunk );
			}
			if ( count( $chunks ) > 1 ) {
				$data['part']  = $index + 1;
				$data['parts'] = count( $chunks );
				$data['total'] = count( $items );
			}
			if ( $overflow ) {
				$data['overflow'] = true;
			}

			$rows[] = [
				'type'  => self::TYPES[ $kind ] ?? 'PostBulkAction',
				'data'  => $data,
				'items' => $chunk,
			];
		}

		return $rows;
	}

	/**
	 * The data of a row about posts.
	 *
	 * @param array<int, array<string, mixed>> $items The posts.
	 *
	 * @return array<string, mixed>
	 */
	private static function post_row( array $items ): array {
		$first     = $items[0];
		$post_type = (string) $first['group'];
		$object    = get_post_type_object( $post_type );

		$data = [
			'action'     => (string) $first['action'],
			'post_type'  => $post_type,
			'type_label' => is_object( $object ) && isset( $object->labels->name ) ? self::title( (string) $object->labels->name ) : $post_type,
			'count'      => count( $items ),
			'posts'      => self::list( $items, 'title' ),
		];

		$changed = self::changed( $items );
		if ( [] !== $changed ) {
			$data['changed'] = $changed;
		}

		$terms = self::terms( $items );
		if ( [] !== $terms ) {
			$data['terms'] = $terms;
		}

		if ( ! empty( $first['expired'] ) ) {
			$data['expired']    = true;
			$data['trash_days'] = defined( 'EMPTY_TRASH_DAYS' ) ? (int) EMPTY_TRASH_DAYS : 30;
		}

		return $data;
	}

	/**
	 * The data of a row about terms.
	 *
	 * @param array<int, array<string, mixed>> $items The terms.
	 *
	 * @return array<string, mixed>
	 */
	private static function term_row( array $items ): array {
		$first    = $items[0];
		$taxonomy = (string) $first['group'];
		$object   = get_taxonomy( $taxonomy );

		$data = [
			'action'   => (string) $first['action'],
			'taxonomy' => $taxonomy,
			'label'    => is_object( $object ) && isset( $object->labels->name ) ? self::title( (string) $object->labels->name ) : $taxonomy,
			'count'    => count( $items ),
			'terms'    => self::list( $items, 'name' ),
		];

		$changed = self::changed( $items );
		if ( [] !== $changed ) {
			$data['changed'] = $changed;
		}

		if ( 'deleted' === $data['action'] ) {
			$data['posts'] = array_sum( array_map( static fn( array $item ): int => (int) ( $item['posts'] ?? 0 ), $items ) );
		}

		return $data;
	}

	/**
	 * The data of a row about files.
	 *
	 * @param array<int, array<string, mixed>> $items The files.
	 *
	 * @return array<string, mixed>
	 */
	private static function media_row( array $items ): array {
		$first = $items[0];
		$data  = [
			'action' => (string) $first['action'],
			'count'  => count( $items ),
			'files'  => array_map(
				static fn( array $item ): array => [
					'id'    => (int) $item['id'],
					'name'  => (string) ( $item['name'] ?? '' ),
					'title' => (string) $item['title'],
				],
				$items
			),
		];

		$changed = self::changed( $items );
		if ( [] !== $changed ) {
			$data['changed'] = $changed;
		}

		// Files attached to one post at once, as the media library does.
		$parents = array_unique( array_map( static fn( array $item ): int => (int) ( $item['parent']['id'] ?? 0 ), $items ) );
		if ( 'attached' === $data['action'] && 1 === count( $parents ) && isset( $first['parent'] ) ) {
			$data['parent'] = $first['parent'];
		}

		if ( ! empty( $first['expired'] ) ) {
			$data['expired']    = true;
			$data['trash_days'] = defined( 'EMPTY_TRASH_DAYS' ) ? (int) EMPTY_TRASH_DAYS : 30;
		}

		return $data;
	}

	/**
	 * Every item by ID and title (or name).
	 *
	 * @param array<int, array<string, mixed>> $items The items.
	 * @param string                           $name  The key of the title: `title` or `name`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function list( array $items, string $name ): array {
		return array_map(
			static fn( array $item ): array => [
				'id'  => (int) $item['id'],
				$name => (string) $item['title'],
			],
			$items
		);
	}

	/**
	 * How many items each part changed in.
	 *
	 * @param array<int, array<string, mixed>> $items The items.
	 *
	 * @return array<string, int>
	 */
	private static function changed( array $items ): array {
		$changed = [];
		foreach ( $items as $item ) {
			foreach ( (array) ( $item['changed'] ?? [] ) as $part ) {
				$changed[ (string) $part ] = ( $changed[ (string) $part ] ?? 0 ) + 1;
			}
		}

		return $changed;
	}

	/**
	 * The terms added to and removed from the posts, per taxonomy: term
	 * taxonomy IDs, which the job names.
	 *
	 * @param array<int, array<string, mixed>> $items The posts.
	 *
	 * @return array<string, array{added: int[], removed: int[]}>
	 */
	private static function terms( array $items ): array {
		$terms = [];
		foreach ( $items as $item ) {
			foreach ( (array) ( $item['terms'] ?? [] ) as $taxonomy => $set ) {
				foreach ( [ 'added', 'removed' ] as $side ) {
					foreach ( (array) ( $set[ $side ] ?? [] ) as $id ) {
						$list = $terms[ $taxonomy ][ $side ] ?? [];
						if ( count( $list ) < self::MAX_TERM_IDS && ! in_array( (int) $id, $list, true ) ) {
							$list[] = (int) $id;
						}
						$terms[ $taxonomy ][ $side ] = $list;
					}
				}
			}
		}

		foreach ( $terms as $taxonomy => $set ) {
			$terms[ $taxonomy ] = [
				'added'   => $set['added'] ?? [],
				'removed' => $set['removed'] ?? [],
			];
		}

		return $terms;
	}
}
