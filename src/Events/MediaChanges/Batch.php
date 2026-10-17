<?php
/**
 * The media batch class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\MediaChanges;

use Scanfully\Events\ContentEvent;
use Scanfully\Events\Content\MinuteStore;
use Scanfully\Events\Content\Report;
use Scanfully\Events\Content\Summary;

/**
 * Class Batch
 *
 * The job that reports a minute of media changes, a minute after it. Per
 * person, it merges the notes the requests stored (Collector) per file: an
 * upload followed by edits is one upload with how the file is now, an
 * upload deleted again is nothing, several saves of the details are one
 * change from the first old value to the last new one, a change undone is
 * nothing. Then, per action, a few files keep an event each (MediaUploaded,
 * MediaChanged, MediaDeleted) and more become one MediaBulkAction row that
 * lists every file.
 *
 * @package Scanfully\Events\MediaChanges
 */
final class Batch {

	/**
	 * Default number of files of one action and person that still get
	 * events of their own; more become one summary row. Filterable via
	 * `scanfully_activity_bulk_threshold` (kind `media`).
	 */
	private const THRESHOLD = 10;

	/**
	 * Default number of events the job sends for a minute; what doesn't fit
	 * goes into summary rows. Filterable via
	 * `scanfully_media_events_per_minute`; 0 or less is no cap.
	 */
	private const PER_MINUTE = 30;

	/**
	 * The fields whose old and new text an event carries.
	 */
	private const TEXT_FIELDS = [ 'title', 'caption', 'description', 'alt', 'slug', 'status' ];

	/**
	 * Action Scheduler callback: report a minute's media changes.
	 *
	 * @param int $bucket The minute.
	 *
	 * @return void
	 */
	public static function send( int $bucket ): void {
		$by_user = [];
		foreach ( MinuteStore::take( Collector::KIND, $bucket ) as $entry ) {
			if ( ! is_array( $entry['items'] ?? null ) ) {
				continue;
			}

			$user = [
				'id'   => (int) ( $entry['user']['id'] ?? 0 ),
				'name' => (string) ( $entry['user']['name'] ?? '' ),
			];
			$who  = $user['id'] . '|' . $user['name'];

			$by_user[ $who ]['user'] = $user;
			foreach ( $entry['items'] as $item ) {
				if ( is_array( $item ) && isset( $item['id'] ) ) {
					$by_user[ $who ]['items'][] = $item;
				}
			}
		}

		$threshold = max( 0, (int) apply_filters( 'scanfully_activity_bulk_threshold', self::THRESHOLD, Report::KIND_MEDIA ) );
		$limit     = (int) apply_filters( 'scanfully_media_events_per_minute', self::PER_MINUTE );
		$room      = $limit > 0 ? $limit : PHP_INT_MAX;

		$queue = [];
		foreach ( $by_user as $entry ) {
			$merged = self::merge( $entry['items'] ?? [] );
			Details::prime( array_keys( $merged ) );

			$items = [];
			foreach ( $merged as $id => $file ) {
				$item = self::item( (int) $id, $file );
				if ( null !== $item ) {
					$items[] = $item;
				}
			}

			$singles = [];
			foreach ( Summary::group( $items ) as $group ) {
				if ( count( $group ) > $threshold ) {
					foreach ( Summary::rows( Report::KIND_MEDIA, $group ) as $row ) {
						$queue[] = [ $row['type'], $entry['user'], $row['data'] ];
						--$room;
					}
				} else {
					$singles[] = $group;
				}
			}

			foreach ( $singles as $group ) {
				$over = [];
				foreach ( $group as $item ) {
					if ( $room > 0 ) {
						$queue[] = [ (string) $item['type'], $entry['user'], (array) $item['data'] ];
						--$room;
					} else {
						$over[] = $item;
					}
				}
				// Past the cap the rest of a group is one summary row.
				foreach ( Summary::rows( Report::KIND_MEDIA, $over, true ) as $row ) {
					$queue[] = [ $row['type'], $entry['user'], $row['data'] ];
				}
			}
		}

		foreach ( $queue as [ $type, $user, $data ] ) {
			ContentEvent::queue( $type, $user, $data );
		}
	}

	/**
	 * The notes of one person merged per file, in the order each file was
	 * first noted.
	 *
	 * @param array<int, array<string, mixed>> $items The notes, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function merge( array $items ): array {
		$files = [];
		foreach ( $items as $item ) {
			$id   = (int) $item['id'];
			$file = $files[ $id ] ?? [];

			if ( isset( $item['deleted'] ) ) {
				// Uploaded and deleted again: nothing happened.
				$files[ $id ] = ! empty( $file['uploaded'] ) ? [ 'gone' => true ] : [ 'deleted' => (array) $item['deleted'] ];
				continue;
			}

			if ( ! empty( $file['gone'] ) || isset( $file['deleted'] ) ) {
				continue;
			}

			if ( ! empty( $item['uploaded'] ) ) {
				$files[ $id ] = $item;
				continue;
			}

			// An upload is reported as the file is now.
			if ( ! empty( $file['uploaded'] ) ) {
				continue;
			}

			foreach ( (array) ( $item['new'] ?? [] ) as $field => $new ) {
				if ( ! array_key_exists( $field, $file['old'] ?? [] ) ) {
					$file['old'][ $field ] = $item['old'][ $field ] ?? null;
				}
				$file['new'][ $field ] = $new;
			}

			if ( isset( $item['image'] ) ) {
				$image = (array) $item['image'];
				if ( empty( $image['restored'] ) && ! empty( $file['image']['edits'] ) ) {
					$image['edits'] = array_values( array_unique( array_merge( (array) $file['image']['edits'], (array) ( $image['edits'] ?? [] ) ) ) );
				}
				$file['image'] = $image;
			}

			if ( isset( $item['replaced'] ) ) {
				$file['replaced'] = [
					'old' => (string) ( $file['replaced']['old'] ?? $item['replaced']['old'] ?? '' ),
					'new' => (string) ( $item['replaced']['new'] ?? '' ),
				];
				unset( $file['old']['file'], $file['new']['file'] );
			}

			$files[ $id ] = $file;
		}

		return array_filter( $files, static fn( array $file ): bool => empty( $file['gone'] ) );
	}

	/**
	 * A merged file as a summary item with its own event; null when there is
	 * nothing to report.
	 *
	 * @param int                  $id   The attachment ID.
	 * @param array<string, mixed> $file The merged notes.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function item( int $id, array $file ): ?array {
		if ( isset( $file['deleted'] ) ) {
			$data   = self::deleted( $id, (array) $file['deleted'] );
			$action = 'deleted';
			$type   = 'MediaDeleted';
		} elseif ( ! empty( $file['uploaded'] ) ) {
			$data   = self::uploaded( $id, $file );
			$action = 'uploaded';
			$type   = 'MediaUploaded';
		} else {
			$changes = self::changes( $file );
			if ( [] === $changes ) {
				return null;
			}
			$data            = self::describe( $id, $file );
			$data['changes'] = $changes;
			$action          = self::action( $changes );
			$type            = 'MediaChanged';
		}

		/**
		 * Filters whether a media change is reported to Scanfully.
		 *
		 * @param bool                 $should_fire Whether to report it.
		 * @param string               $action      `uploaded`, `changed`, `attached`, `detached`,
		 *                                          `trashed`, `restored` or `deleted`.
		 * @param array<string, mixed> $data        The event data.
		 */
		if ( ! (bool) apply_filters( 'scanfully_media_should_fire', true, $action, $data ) ) {
			return null;
		}

		$item = [
			'action' => $action,
			'group'  => 'attachment',
			'id'     => $id,
			'title'  => Summary::title( (string) ( $data['title'] ?? '' ) ),
			'name'   => Summary::title( (string) ( $data['name'] ?? '' ) ),
			'type'   => $type,
			'data'   => $data,
		];
		if ( 'changed' === $action ) {
			$item['changed'] = array_keys( $data['changes'] );
		}
		if ( 'attached' === $action ) {
			$item['parent'] = $data['changes']['parent']['new'];
		}
		if ( ! empty( $data['expired'] ) ) {
			$item['expired'] = true;
		}

		return $item;
	}

	/**
	 * The data of an upload: the file as it is now, the post it was
	 * uploaded to, and for the block editor's edited copy the original.
	 *
	 * @param int                  $id   The attachment ID.
	 * @param array<string, mixed> $file The merged notes.
	 *
	 * @return array<string, mixed>
	 */
	private static function uploaded( int $id, array $file ): array {
		$data   = self::describe( $id, $file );
		$parent = (int) wp_get_post_parent_id( $id );
		if ( $parent > 0 ) {
			$data['parent'] = Details::parent( $parent );
		}

		if ( isset( $file['edited_from'] ) ) {
			$original            = Details::describe( (int) $file['edited_from'] );
			$data['edited_from'] = [
				'id'   => (int) $file['edited_from'],
				'name' => (string) ( $original['name'] ?? '' ),
			];
			$data['edits']       = array_values( (array) ( $file['edits'] ?? [] ) );
		}

		return $data;
	}

	/**
	 * The data of a permanent delete, from what the request noted before the
	 * file was gone.
	 *
	 * @param int                  $id      The attachment ID.
	 * @param array<string, mixed> $deleted The noted file.
	 *
	 * @return array<string, mixed>
	 */
	private static function deleted( int $id, array $deleted ): array {
		$file = (string) ( $deleted['file'] ?? '' );
		$data = array_merge(
			[
				'id'        => $id,
				'name'      => Details::name( $file ),
				'file'      => $file,
				'title'     => (string) ( $deleted['title'] ?? '' ),
				'mime_type' => (string) ( $deleted['mime_type'] ?? '' ),
			],
			Details::facts( (array) ( $deleted['facts'] ?? [] ) ),
			[
				'post_status' => (string) ( $deleted['post_status'] ?? '' ),
				'featured_on' => (int) ( $deleted['featured_on'] ?? 0 ),
			]
		);

		if ( ! empty( $deleted['expired'] ) ) {
			$data['expired']    = true;
			$data['trash_days'] = defined( 'EMPTY_TRASH_DAYS' ) ? (int) EMPTY_TRASH_DAYS : 30;
		}

		return $data;
	}

	/**
	 * A file as it is now, or, when it is gone since, as far as the notes
	 * know it.
	 *
	 * @param int                  $id   The attachment ID.
	 * @param array<string, mixed> $file The merged notes.
	 *
	 * @return array<string, mixed>
	 */
	private static function describe( int $id, array $file ): array {
		$data = Details::describe( $id );
		if ( null !== $data ) {
			return $data;
		}

		$path = (string) ( $file['new']['file'] ?? $file['file'] ?? '' );

		return [
			'id'        => $id,
			'name'      => Details::name( $path ),
			'file'      => $path,
			'title'     => (string) ( $file['new']['title'] ?? '' ),
			'mime_type' => '',
		];
	}

	/**
	 * What changed in a file, for its event.
	 *
	 * @param array<string, mixed> $file The merged notes.
	 *
	 * @return array<string, mixed>
	 */
	private static function changes( array $file ): array {
		$changes = [];
		foreach ( (array) ( $file['new'] ?? [] ) as $field => $new ) {
			$old = $file['old'][ $field ] ?? null;
			if ( $old === $new ) {
				continue;
			}

			if ( in_array( $field, self::TEXT_FIELDS, true ) ) {
				$changes[ $field ] = [
					'old' => (string) $old,
					'new' => (string) $new,
				];
			} elseif ( 'parent' === $field ) {
				$changes['parent'] = [
					'old' => Details::parent( max( 0, (int) $old ) ),
					'new' => Details::parent( (int) $new ),
				];
				if ( $changes['parent']['old']['id'] === $changes['parent']['new']['id'] ) {
					unset( $changes['parent'] );
				}
			} elseif ( 'file' === $field ) {
				$changes['file'] = [
					'old' => Details::name( (string) $old ),
					'new' => Details::name( (string) $new ),
				];
			}
		}

		if ( isset( $file['replaced'] ) ) {
			$changes['file'] = [
				'old'      => (string) $file['replaced']['old'],
				'new'      => (string) $file['replaced']['new'],
				'replaced' => true,
			];
		}

		if ( isset( $file['image'] ) ) {
			$changes['image'] = ! empty( $file['image']['restored'] )
				? [ 'restored' => true ]
				: [
					'edits'  => array_values( (array) ( $file['image']['edits'] ?? [] ) ),
					'target' => (string) ( $file['image']['target'] ?? 'all' ),
				];
		}

		return $changes;
	}

	/**
	 * The action of a change: moved to the trash or back, attached or
	 * detached when only the post it belongs to changed, otherwise changed.
	 *
	 * @param array<string, mixed> $changes The changes.
	 *
	 * @return string
	 */
	private static function action( array $changes ): string {
		if ( isset( $changes['status'] ) ) {
			if ( 'trash' === $changes['status']['new'] ) {
				return 'trashed';
			}
			if ( 'trash' === $changes['status']['old'] ) {
				return 'restored';
			}
		}

		if ( [ 'parent' ] === array_keys( $changes ) ) {
			return $changes['parent']['new']['id'] > 0 ? 'attached' : 'detached';
		}

		return 'changed';
	}
}
