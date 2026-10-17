<?php
/**
 * The post change collector class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\PostChanges;

use Scanfully\Events\Content\Report;
use Scanfully\Events\Content\Summary;
use Scanfully\Events\ContentEvent;
use Scanfully\Events\PostDeleted;
use Scanfully\Events\PostSaved;

/**
 * Class Collector
 *
 * Notes what changes in the posts saved during a request, and the posts
 * deleted permanently, and at the end of the request reports each one that
 * changed (the `PostSaved` event listens to `scanfully_post_changed`,
 * `PostDeleted` to its content event). A save that changes nothing tracked
 * is not reported. When many posts get the same action in one request, they
 * are reported as one summary row (Content\Report).
 *
 * Everything here must stay cheap, because it runs while a post is saved:
 * it compares values WordPress already has in memory, and keeps what the
 * meta, term and revision hooks pass along. The old value of a custom field
 * comes from the meta cache, which WordPress has just loaded to compare. The
 * work that costs time (the content diff, permalinks, names) happens later,
 * in the background job (Builder).
 *
 * @package Scanfully\Events\PostChanges
 */
final class Collector {

	/**
	 * The action fired at the end of the request for each changed post:
	 * post ID, post, whether it was an update, post before, changes.
	 */
	public const ACTION = 'scanfully_post_changed';

	/**
	 * The most posts noted in full in one request. Past them (a bulk edit
	 * of hundreds of posts, a long WP-CLI run), a saved post is only counted
	 * for the summary row, without a copy of the post.
	 */
	private const MAX_POSTS = 100;

	/**
	 * The most posts counted or deleted in one request.
	 */
	private const MAX_ITEMS = 20000;

	/**
	 * The most posts whose custom fields or terms are noted before they are
	 * saved.
	 */
	private const MAX_PENDING = 2 * self::MAX_POSTS;

	/**
	 * The most custom fields noted per post.
	 */
	private const MAX_FIELDS = 30;

	/**
	 * The longest title or excerpt kept.
	 */
	private const MAX_TEXT = 1000;

	/**
	 * Content larger than this (per side) is not compared without revisions.
	 */
	public const MAX_CONTENT = 256 * 1024;

	/**
	 * Post statuses whose publish date is fixed; a draft's date moves with
	 * every save until it is published.
	 */
	private const FIXED_DATE_STATUSES = [ 'publish', 'future', 'private' ];

	/**
	 * Posts saved in this request: the post before the first save and after
	 * the last.
	 *
	 * @var array<int, array{before: object|null, after: object, update: bool}>
	 */
	private static array $saved = [];

	/**
	 * Custom field changes in this request, per post and key.
	 *
	 * @var array<int, array<string, array<string, mixed>>>
	 */
	private static array $fields = [];

	/**
	 * Term changes in this request, per post and taxonomy: term taxonomy IDs
	 * before the first change and after the last.
	 *
	 * @var array<int, array<string, array{old: int[], new: int[]}>>
	 */
	private static array $terms = [];

	/**
	 * Revisions made in this request, per post: the first and the last, and
	 * whether the post had a revision before them.
	 *
	 * @var array<int, array{first: int, last: int, earlier: bool}>
	 */
	private static array $revisions = [];

	/**
	 * Posts WordPress found an earlier revision for while saving, by ID.
	 *
	 * @var array<int, bool>
	 */
	private static array $earlier_revisions = [];

	/**
	 * Posts saved in this request past the first MAX_POSTS, as summary
	 * items, by ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $counted = [];

	/**
	 * Posts deleted permanently in this request, by ID: what the event
	 * reports, noted while the post still existed, and whether the delete
	 * went through.
	 *
	 * @var array<int, array{title: string, post_type: string, post_status: string, expired: bool, done: bool}>
	 */
	private static array $deleted = [];

	/**
	 * Term taxonomy IDs added to the posts the last flush reported, so a
	 * term created for one of them isn't reported again on its own.
	 *
	 * @var array<int, bool>
	 */
	private static array $added_terms = [];

	/**
	 * Whether the hooks are added.
	 *
	 * @var bool
	 */
	private static bool $set_up = false;

	/**
	 * Add the hooks.
	 *
	 * @return void
	 */
	public static function setup(): void {
		if ( self::$set_up ) {
			return;
		}
		self::$set_up = true;

		add_action( 'wp_after_insert_post', [ self::class, 'note_saved' ], 10, 4 );
		add_action( 'update_post_meta', [ self::class, 'note_meta_updated' ], 10, 4 );
		add_action( 'added_post_meta', [ self::class, 'note_meta_added' ], 10, 4 );
		add_action( 'delete_post_meta', [ self::class, 'note_meta_deleted' ], 10, 4 );
		add_action( 'set_object_terms', [ self::class, 'note_terms' ], 10, 6 );
		add_action( 'before_delete_post', [ self::class, 'note_deleting' ], 10, 2 );
		add_action( 'deleted_post', [ self::class, 'note_deleted' ], 10, 1 );
		add_action( '_wp_put_post_revision', [ self::class, 'note_revision' ], 10, 2 );
		add_filter( 'wp_save_post_revision_check_for_changes', [ self::class, 'note_earlier_revision' ], PHP_INT_MAX, 3 );
		add_action( 'shutdown', [ self::class, 'flush' ], 0, 0 );
	}

	/**
	 * Forget everything noted (tests, and after a flush).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$saved             = [];
		self::$fields            = [];
		self::$terms             = [];
		self::$revisions         = [];
		self::$earlier_revisions = [];
		self::$counted           = [];
		self::$deleted           = [];
		self::$added_terms       = [];
	}

	/**
	 * Term taxonomy IDs added to the posts the last flush reported.
	 *
	 * @return array<int, bool>
	 */
	public static function added_terms(): array {
		return self::$added_terms;
	}

	/**
	 * Whether an import runs; imports save many posts and aren't edits.
	 *
	 * @return bool
	 */
	private static function importing(): bool {
		return defined( 'WP_IMPORTING' ) && WP_IMPORTING;
	}

	/**
	 * `wp_after_insert_post`: a post was saved, after its terms and custom
	 * fields.
	 *
	 * @param mixed $post_id     The post ID.
	 * @param mixed $post        The post.
	 * @param mixed $update      Whether an existing post was updated.
	 * @param mixed $post_before The post before the save, or null.
	 *
	 * @return void
	 */
	public static function note_saved( $post_id, $post, $update, $post_before ): void {
		if ( self::importing() || ! is_object( $post ) ) {
			return;
		}

		$post_id = (int) $post_id;
		if ( isset( self::$saved[ $post_id ] ) ) {
			self::$saved[ $post_id ]['after'] = clone $post;
			return;
		}

		if ( count( self::$saved ) >= self::MAX_POSTS ) {
			self::count_saved( $post_id, $post, (bool) $update, is_object( $post_before ) ? $post_before : null );
			return;
		}

		self::$saved[ $post_id ] = [
			'before' => is_object( $post_before ) ? clone $post_before : null,
			'after'  => clone $post,
			'update' => (bool) $update,
		];
	}

	/**
	 * A post saved past the first MAX_POSTS of the request: only counted for
	 * its summary row, from what this save changed. Its custom fields and
	 * terms were noted before the save; later changes to it in the request
	 * are not counted.
	 *
	 * @param int         $post_id The post ID.
	 * @param object      $post    The post.
	 * @param bool        $update  Whether an existing post was updated.
	 * @param object|null $before  The post before the save.
	 *
	 * @return void
	 */
	private static function count_saved( int $post_id, object $post, bool $update, ?object $before ): void {
		$fields = self::$fields[ $post_id ] ?? [];
		$terms  = self::$terms[ $post_id ] ?? [];
		unset( self::$fields[ $post_id ], self::$terms[ $post_id ], self::$revisions[ $post_id ], self::$earlier_revisions[ $post_id ] );

		if ( isset( self::$counted[ $post_id ] ) || count( self::$counted ) >= self::MAX_ITEMS || ! PostSaved::is_reported( $post ) ) {
			return;
		}

		$created = self::is_new( $update, $before );
		$changes = $created || null === $before ? [] : self::changes( $before, $post, $fields, $terms, null );
		if ( null === $changes ) {
			return;
		}

		self::$counted[ $post_id ] = self::saved_item( $post_id, $post, $before, $changes, $created );
	}

	/**
	 * Whether a save made the post: a new post, or the auto-draft the editor
	 * opened first.
	 *
	 * @param bool        $update Whether an existing post was updated.
	 * @param object|null $before The post before the save.
	 *
	 * @return bool
	 */
	private static function is_new( bool $update, ?object $before ): bool {
		return ! $update || null === $before || 'auto-draft' === ( $before->post_status ?? '' );
	}

	/**
	 * `before_delete_post`: a post is about to be deleted permanently. What
	 * the event reports is read from the post WordPress passes; nothing is
	 * looked up.
	 *
	 * @param mixed $post_id The post ID.
	 * @param mixed $post    The post.
	 *
	 * @return void
	 */
	public static function note_deleting( $post_id, $post = null ): void {
		$post_id = (int) $post_id;
		if ( $post_id < 1 || ! is_object( $post ) || isset( self::$deleted[ $post_id ] ) || count( self::$deleted ) >= self::MAX_ITEMS ) {
			return;
		}

		// What the save hooks noted about the post is no longer a change.
		unset( self::$fields[ $post_id ], self::$terms[ $post_id ] );

		if ( ! PostDeleted::is_reported( $post ) ) {
			return;
		}

		self::$deleted[ $post_id ] = [
			'title'       => (string) ( $post->post_title ?? '' ),
			'post_type'   => (string) $post->post_type,
			'post_status' => (string) $post->post_status,
			// WordPress empties the trash in the `wp_scheduled_delete` cron job.
			'expired'     => doing_action( 'wp_scheduled_delete' ),
			'done'        => false,
		];
	}

	/**
	 * `deleted_post`: the post is gone.
	 *
	 * @param mixed $post_id The post ID.
	 *
	 * @return void
	 */
	public static function note_deleted( $post_id ): void {
		$post_id = (int) $post_id;
		if ( isset( self::$deleted[ $post_id ] ) ) {
			self::$deleted[ $post_id ]['done'] = true;
		}
	}

	/**
	 * `update_post_meta`: a custom field is about to change.
	 *
	 * @param mixed $meta_id   The meta ID.
	 * @param mixed $object_id The post ID.
	 * @param mixed $meta_key  The key.
	 * @param mixed $value     The new value.
	 *
	 * @return void
	 */
	public static function note_meta_updated( $meta_id, $object_id, $meta_key, $value ): void {
		// WordPress adds a field that doesn't exist yet instead of updating it.
		self::note_field( (int) $object_id, (string) $meta_key, true, $value, true );
	}

	/**
	 * `added_post_meta`: a custom field was added.
	 *
	 * @param mixed $meta_id   The meta ID.
	 * @param mixed $object_id The post ID.
	 * @param mixed $meta_key  The key.
	 * @param mixed $value     The value.
	 *
	 * @return void
	 */
	public static function note_meta_added( $meta_id, $object_id, $meta_key, $value ): void {
		self::note_field( (int) $object_id, (string) $meta_key, true, $value, false );
	}

	/**
	 * `delete_post_meta`: a custom field is about to be removed.
	 *
	 * @param mixed $meta_ids  The meta IDs.
	 * @param mixed $object_id The post ID.
	 * @param mixed $meta_key  The key.
	 * @param mixed $value     The value asked to remove, if any.
	 *
	 * @return void
	 */
	public static function note_meta_deleted( $meta_ids, $object_id, $meta_key, $value ): void {
		// WordPress only fires this when it found the field.
		self::note_field( (int) $object_id, (string) $meta_key, false, null, true );
	}

	/**
	 * Note a custom field change: the value before the first change in this
	 * request and after the last.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $key     The key.
	 * @param bool   $exists  Whether the field exists after the change.
	 * @param mixed  $value   The value after the change.
	 * @param bool   $existed Whether the field existed before the change.
	 *
	 * @return void
	 */
	private static function note_field( int $post_id, string $key, bool $exists, $value, bool $existed ): void {
		if ( $post_id < 1 || self::importing() || ! Fields::is_counted( $key ) || self::is_settled( $post_id ) ) {
			return;
		}

		if ( ! isset( self::$fields[ $post_id ][ $key ] ) ) {
			if ( ! isset( self::$fields[ $post_id ] ) && count( self::$fields ) >= self::MAX_PENDING ) {
				return;
			}
			if ( count( self::$fields[ $post_id ] ?? [] ) >= self::MAX_FIELDS ) {
				return;
			}

			$cached = $existed ? self::cached_value( $post_id, $key ) : [
				'found' => false,
				'value' => null,
			];

			self::$fields[ $post_id ][ $key ] = [
				'existed'  => $existed,
				'old'      => $existed && $cached['found'] ? Fields::describe( $cached['value'] ) : null,
				'old_hash' => $existed && $cached['found'] ? Fields::fingerprint( $cached['value'] ) : '',
			];
		}

		self::$fields[ $post_id ][ $key ]['exists']   = $exists;
		self::$fields[ $post_id ][ $key ]['new']      = $exists ? Fields::describe( $value ) : null;
		self::$fields[ $post_id ][ $key ]['new_hash'] = $exists ? Fields::fingerprint( $value ) : '';
	}

	/**
	 * Whether nothing more is noted about a post in this request: it is
	 * being deleted (WordPress removes its fields one by one), or it was
	 * already counted for a summary row.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return bool
	 */
	private static function is_settled( int $post_id ): bool {
		return isset( self::$deleted[ $post_id ] ) || isset( self::$counted[ $post_id ] );
	}

	/**
	 * A post's custom field value from the meta cache, without a query.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $key     The key.
	 *
	 * @return array{found: bool, value: mixed}
	 */
	private static function cached_value( int $post_id, string $key ): array {
		$cache = wp_cache_get( $post_id, 'post_meta' );
		if ( ! is_array( $cache ) || ! isset( $cache[ $key ] ) || ! is_array( $cache[ $key ] ) || [] === $cache[ $key ] ) {
			return [
				'found' => false,
				'value' => null,
			];
		}

		return [
			'found' => true,
			'value' => maybe_unserialize( $cache[ $key ][0] ),
		];
	}

	/**
	 * `set_object_terms`: the terms of an object in a taxonomy were set.
	 *
	 * @param mixed $object_id  The object ID.
	 * @param mixed $terms      The terms asked for.
	 * @param mixed $tt_ids     The term taxonomy IDs set.
	 * @param mixed $taxonomy   The taxonomy.
	 * @param mixed $append     Whether the terms were added to the existing ones.
	 * @param mixed $old_tt_ids The term taxonomy IDs before.
	 *
	 * @return void
	 */
	public static function note_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		$post_id  = (int) $object_id;
		$taxonomy = (string) $taxonomy;
		if ( $post_id < 1 || self::importing() || self::is_settled( $post_id ) || ! self::is_counted_taxonomy( $taxonomy ) ) {
			return;
		}

		$old = array_map( 'intval', is_array( $old_tt_ids ) ? $old_tt_ids : [] );
		$new = array_map( 'intval', is_array( $tt_ids ) ? $tt_ids : [] );
		if ( $append ) {
			$new = array_values( array_unique( array_merge( $old, $new ) ) );
		}

		if ( ! isset( self::$terms[ $post_id ][ $taxonomy ] ) ) {
			if ( ! isset( self::$terms[ $post_id ] ) && count( self::$terms ) >= self::MAX_PENDING ) {
				return;
			}
			self::$terms[ $post_id ][ $taxonomy ] = [
				'old' => $old,
				'new' => $new,
			];
			return;
		}

		self::$terms[ $post_id ][ $taxonomy ]['new'] = $new;
	}

	/**
	 * Whether term changes in a taxonomy count: taxonomies with an admin
	 * screen, and post formats.
	 *
	 * @param string $taxonomy The taxonomy.
	 *
	 * @return bool
	 */
	private static function is_counted_taxonomy( string $taxonomy ): bool {
		if ( 'post_format' === $taxonomy ) {
			return true;
		}

		$object = get_taxonomy( $taxonomy );

		return is_object( $object ) && ! empty( $object->show_ui );
	}

	/**
	 * `_wp_put_post_revision`: a revision of a post was made.
	 *
	 * @param mixed $revision_id The revision ID.
	 * @param mixed $post_id     The post ID (WordPress 6.4 and later).
	 *
	 * @return void
	 */
	public static function note_revision( $revision_id, $post_id = null ): void {
		$revision_id = (int) $revision_id;
		if ( $revision_id < 1 || self::importing() ) {
			return;
		}

		$post_id = null !== $post_id ? (int) $post_id : (int) wp_get_post_parent_id( $revision_id );
		if ( $post_id < 1 ) {
			return;
		}

		if ( isset( self::$revisions[ $post_id ] ) ) {
			self::$revisions[ $post_id ]['last'] = $revision_id;
			return;
		}

		self::$revisions[ $post_id ] = [
			'first'   => $revision_id,
			'last'    => $revision_id,
			'earlier' => isset( self::$earlier_revisions[ $post_id ] ),
		];
	}

	/**
	 * `wp_save_post_revision_check_for_changes`: WordPress only asks this
	 * when the post already has a revision, so it tells for free whether the
	 * content before this save is in a revision. A post's first revision
	 * (its first edit after it was created) has nothing before it.
	 *
	 * @param mixed $check_for_changes Whether to check for changes; unchanged.
	 * @param mixed $latest_revision   The latest revision.
	 * @param mixed $post              The post being saved.
	 *
	 * @return mixed
	 */
	public static function note_earlier_revision( $check_for_changes, $latest_revision, $post ) {
		$post_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
		if ( $post_id > 0 && ! isset( self::$revisions[ $post_id ] ) ) {
			self::$earlier_revisions[ $post_id ] = true;
		}

		return $check_for_changes;
	}

	/**
	 * Report each saved post that changed and each deleted post, once, at
	 * the end of the request.
	 *
	 * @return void
	 */
	public static function flush(): void {
		$saved     = self::$saved;
		$fields    = self::$fields;
		$terms     = self::$terms;
		$revisions = self::$revisions;
		$counted   = self::$counted;
		$deleted   = self::$deleted;
		self::reset();

		$items   = [];
		$created = [];
		foreach ( $saved as $post_id => $save ) {
			$before = $save['before'];
			$after  = $save['after'];
			$new    = self::is_new( $save['update'], $before );
			if ( $new ) {
				$created[ $post_id ] = true;
			}
			// A deleted post's saves are not reported: only its delete, or
			// nothing when it was also made in this request.
			if ( isset( $deleted[ $post_id ] ) || ! PostSaved::is_reported( $after ) ) {
				continue;
			}

			$changes = $new || null === $before ? [] : self::changes( $before, $after, $fields[ $post_id ] ?? [], $terms[ $post_id ] ?? [], $revisions[ $post_id ] ?? null );
			if ( null === $changes ) {
				continue;
			}

			$item          = self::saved_item( $post_id, $after, $before, $changes, $new );
			$item['event'] = [
				'hook' => self::ACTION,
				'args' => [ $post_id, $after, $save['update'], $before, $changes ],
			];
			$items[]       = $item;
		}

		foreach ( $counted as $post_id => $item ) {
			if ( 'created' === $item['action'] ) {
				$created[ $post_id ] = true;
			}
			if ( ! isset( $deleted[ $post_id ] ) ) {
				$items[] = $item;
			}
		}

		foreach ( $deleted as $post_id => $delete ) {
			if ( $delete['done'] && ! isset( $created[ $post_id ] ) ) {
				$items[] = self::deleted_item( $post_id, $delete );
			}
		}

		foreach ( $items as $item ) {
			foreach ( (array) ( $item['terms'] ?? [] ) as $set ) {
				foreach ( (array) ( $set['added'] ?? [] ) as $tt_id ) {
					self::$added_terms[ (int) $tt_id ] = true;
				}
			}
		}

		Report::send( Report::KIND_POST, $items );
	}

	/**
	 * A saved post as a summary item (see Content\Summary).
	 *
	 * @param int                  $post_id The post ID.
	 * @param object               $after   The post after its saves.
	 * @param object|null          $before  The post before.
	 * @param array<string, mixed> $changes What changed (empty for a new post or a status change only).
	 * @param bool                 $created Whether the post was made in this request.
	 *
	 * @return array<string, mixed>
	 */
	private static function saved_item( int $post_id, object $after, ?object $before, array $changes, bool $created ): array {
		$item = [
			'action' => $created ? 'created' : self::action( (string) ( $before->post_status ?? '' ), (string) ( $after->post_status ?? '' ) ),
			'group'  => (string) ( $after->post_type ?? '' ),
			'id'     => $post_id,
			'title'  => Summary::title( (string) ( $after->post_title ?? '' ) ),
		];
		if ( [] !== $changes ) {
			$item['changed'] = array_keys( $changes );
		}
		if ( isset( $changes['terms'] ) ) {
			$item['terms'] = $changes['terms'];
		}

		return $item;
	}

	/**
	 * What a save did to an existing post, by its status before and after.
	 *
	 * @param string $from The status before.
	 * @param string $to   The status after.
	 *
	 * @return string
	 */
	private static function action( string $from, string $to ): string {
		if ( $from === $to ) {
			return 'updated';
		}
		if ( 'trash' === $to ) {
			return 'trashed';
		}
		if ( 'trash' === $from ) {
			return 'restored';
		}

		switch ( $to ) {
			case 'publish':
				return 'published';
			case 'draft':
				return 'drafted';
			case 'private':
				return 'privated';
			default:
				return 'updated';
		}
	}

	/**
	 * A deleted post as a summary item, with its own `PostDeleted` event.
	 *
	 * @param int                  $post_id The post ID.
	 * @param array<string, mixed> $delete  What was noted before the delete.
	 *
	 * @return array<string, mixed>
	 */
	private static function deleted_item( int $post_id, array $delete ): array {
		$object = get_post_type_object( $delete['post_type'] );
		$data   = [
			'id'          => $post_id,
			'title'       => $delete['title'],
			'post_type'   => $delete['post_type'],
			'type_label'  => is_object( $object ) && isset( $object->labels->singular_name ) ? Summary::title( (string) $object->labels->singular_name ) : $delete['post_type'],
			'post_status' => $delete['post_status'],
		];
		if ( $delete['expired'] ) {
			$data['expired']    = true;
			$data['trash_days'] = defined( 'EMPTY_TRASH_DAYS' ) ? (int) EMPTY_TRASH_DAYS : 30;
		}

		return [
			'action'  => 'deleted',
			'group'   => $delete['post_type'],
			'id'      => $post_id,
			'title'   => Summary::title( $delete['title'] ),
			'expired' => $delete['expired'],
			'event'   => [
				'hook' => ContentEvent::hook( 'PostDeleted' ),
				'args' => [ $data ],
			],
		];
	}

	/**
	 * What changed between a post before and after its saves in this
	 * request, as the background job expects it. Null when nothing tracked
	 * changed; an empty array when only the status changed.
	 *
	 * @param object                                       $before    The post before.
	 * @param object                                       $after     The post after.
	 * @param array<string, array<string, mixed>>          $fields    Custom field changes, per key.
	 * @param array<string, array{old: int[], new: int[]}> $terms     Term changes, per taxonomy.
	 * @param array<string, int|bool>|null                 $revisions Revisions made in this request (first, last, earlier).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function changes( object $before, object $after, array $fields, array $terms, ?array $revisions ): ?array {
		$changes = [];

		foreach ( [
			'post_title'     => 'title',
			'post_excerpt'   => 'excerpt',
			'comment_status' => 'comments',
		] as $field => $name ) {
			$old = (string) ( $before->$field ?? '' );
			$new = (string) ( $after->$field ?? '' );
			if ( $old !== $new ) {
				$changes[ $name ] = [
					'old' => self::cap( $old ),
					'new' => self::cap( $new ),
				];
			}
		}

		// A draft has no slug until it is published; getting one isn't a
		// change. Neither is the `__trashed` slug WordPress gives a post in
		// the trash, or getting the old one back.
		$old_slug = (string) ( $before->post_name ?? '' );
		$new_slug = (string) ( $after->post_name ?? '' );
		$trashed  = 'trash' === ( $before->post_status ?? '' ) || 'trash' === ( $after->post_status ?? '' );
		if ( '' !== $old_slug && $old_slug !== $new_slug && ! $trashed ) {
			$changes['slug'] = [
				'old' => $old_slug,
				'new' => $new_slug,
			];
		}

		foreach ( [
			'post_parent' => 'parent',
			'post_author' => 'author',
			'menu_order'  => 'menu_order',
		] as $field => $name ) {
			$old = (int) ( $before->$field ?? 0 );
			$new = (int) ( $after->$field ?? 0 );
			if ( $old !== $new ) {
				$changes[ $name ] = [
					'old' => $old,
					'new' => $new,
				];
			}
		}

		if ( in_array( (string) ( $before->post_status ?? '' ), self::FIXED_DATE_STATUSES, true ) && (string) ( $before->post_date ?? '' ) !== (string) ( $after->post_date ?? '' ) ) {
			$changes['date'] = [
				'old' => (string) ( $before->post_date ?? '' ),
				'new' => (string) ( $after->post_date ?? '' ),
			];
		}

		// Never the password itself: only whether the post has one, and
		// whether it changed.
		$old_password = (string) ( $before->post_password ?? '' );
		$new_password = (string) ( $after->post_password ?? '' );
		if ( $old_password !== $new_password ) {
			$changes['password'] = [
				'old' => '' !== $old_password,
				'new' => '' !== $new_password,
			];
		}

		$old_content = (string) ( $before->post_content ?? '' );
		$new_content = (string) ( $after->post_content ?? '' );
		if ( $old_content !== $new_content ) {
			$changes['content'] = self::content_source( $after, $old_content, $new_content, $revisions );
		}

		$field_changes = self::field_changes( $fields );
		if ( [] !== $field_changes ) {
			$changes['fields'] = $field_changes;
		}

		$term_changes = self::term_changes( $terms );
		if ( [] !== $term_changes ) {
			$changes['terms'] = $term_changes;
		}

		if ( [] !== $changes ) {
			return $changes;
		}

		return (string) ( $before->post_status ?? '' ) !== (string) ( $after->post_status ?? '' ) ? [] : null;
	}

	/**
	 * Where the background job finds the content before and after: the
	 * revisions WordPress made, or (without them) the content itself, which
	 * the event stores once for the job.
	 *
	 * @param object                       $after       The post after.
	 * @param string                       $old_content The content before.
	 * @param string                       $new_content The content after.
	 * @param array<string, int|bool>|null $revisions   Revisions made in this request (first, last, earlier).
	 *
	 * @return array<string, mixed>
	 */
	private static function content_source( object $after, string $old_content, string $new_content, ?array $revisions ): array {
		// The content before is in a revision only when the post had one
		// before this request.
		if ( null !== $revisions && ! empty( $revisions['earlier'] ) && self::keeps_earlier_revision( $after ) ) {
			return [
				'revisions' => [
					'first' => (int) $revisions['first'],
					'last'  => (int) $revisions['last'],
				],
			];
		}

		if ( strlen( $old_content ) > self::MAX_CONTENT || strlen( $new_content ) > self::MAX_CONTENT ) {
			return [ 'too_large' => true ];
		}

		return [
			'stash' => [
				'old' => $old_content,
				'new' => $new_content,
			],
		];
	}

	/**
	 * Whether WordPress keeps the revision before the newest one, which the
	 * job compares against.
	 *
	 * @param object $post The post.
	 *
	 * @return bool
	 */
	private static function keeps_earlier_revision( object $post ): bool {
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$keep = wp_revisions_to_keep( $post );

		return $keep < 0 || $keep >= 2;
	}

	/**
	 * The custom field changes to report, per key.
	 *
	 * @param array<string, array<string, mixed>> $fields Noted changes, per key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function field_changes( array $fields ): array {
		$changes = [];
		foreach ( $fields as $key => $field ) {
			$existed = (bool) ( $field['existed'] ?? false );
			$exists  = (bool) ( $field['exists'] ?? false );

			if ( $existed && $exists ) {
				if ( '' !== $field['old_hash'] && $field['old_hash'] === $field['new_hash'] ) {
					continue;
				}
				$change = 'changed';
			} elseif ( $existed ) {
				$change = 'removed';
			} elseif ( $exists ) {
				$change = 'added';
			} else {
				continue;
			}

			$entry = [ 'change' => $change ];
			foreach ( [ 'old', 'new' ] as $side ) {
				$value = $field[ $side ] ?? null;
				if ( ! is_array( $value ) ) {
					continue;
				}
				if ( $value['large'] ) {
					$entry['large'] = true;
				} elseif ( null !== $value['value'] ) {
					$entry[ $side ] = $value['value'];
				}
			}
			$changes[ (string) $key ] = $entry;
		}

		return $changes;
	}

	/**
	 * The term changes to report, per taxonomy: term taxonomy IDs added and
	 * removed.
	 *
	 * @param array<string, array{old: int[], new: int[]}> $terms Noted changes, per taxonomy.
	 *
	 * @return array<string, array{added: int[], removed: int[]}>
	 */
	private static function term_changes( array $terms ): array {
		$changes = [];
		foreach ( $terms as $taxonomy => $sets ) {
			$added   = array_values( array_diff( $sets['new'], $sets['old'] ) );
			$removed = array_values( array_diff( $sets['old'], $sets['new'] ) );
			if ( [] !== $added || [] !== $removed ) {
				$changes[ (string) $taxonomy ] = [
					'added'   => $added,
					'removed' => $removed,
				];
			}
		}

		return $changes;
	}

	/**
	 * A text cut to the longest kept.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private static function cap( string $text ): string {
		return mb_strlen( $text ) > self::MAX_TEXT ? mb_substr( $text, 0, self::MAX_TEXT ) : $text;
	}
}
