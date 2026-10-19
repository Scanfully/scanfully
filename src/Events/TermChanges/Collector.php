<?php
/**
 * The term change collector class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\TermChanges;

use Scanfully\Events\Content\Report;
use Scanfully\Events\Content\Summary;
use Scanfully\Events\ContentEvent;
use Scanfully\Events\PostChanges\Collector as PostCollector;

/**
 * Class Collector
 *
 * Notes the categories, tags and other terms created, changed (name, slug,
 * parent, description) and deleted during a request, and reports them at
 * the end of it: each on its own row (TermCreated, TermChanged,
 * TermDeleted), or one summary row (TermBulkAction) when many terms of a
 * taxonomy got the same action.
 *
 * Like the post Collector it only keeps what WordPress passes to its hooks:
 * the new values from the filters wp_insert_term() and wp_update_term()
 * apply, the old ones from the term cache wp_update_term() has just filled,
 * and the deleted term WordPress hands to `delete_term`. Names of parent
 * terms are looked up later, in the job.
 *
 * @package Scanfully\Events\TermChanges
 */
final class Collector {

	/**
	 * The most terms noted per action in one request.
	 */
	private const MAX_TERMS = 20000;

	/**
	 * The longest description kept.
	 */
	private const MAX_DESCRIPTION = 1000;

	/**
	 * The term wp_insert_term() is about to add, per taxonomy.
	 *
	 * @var array<string, array{name: string, slug: string, parent: int}>
	 */
	private static array $inserting = [];

	/**
	 * The term wp_update_term() is changing, per taxonomy: its values before
	 * and the new ones.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $updating = [];

	/**
	 * Terms created in this request, by term taxonomy ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $created = [];

	/**
	 * Terms changed in this request, by term taxonomy ID: the values before
	 * the first change and after the last.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $changed = [];

	/**
	 * Terms deleted in this request, by term taxonomy ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $deleted = [];

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

		add_filter( 'wp_insert_term_data', [ self::class, 'note_inserting' ], PHP_INT_MAX, 3 );
		add_action( 'created_term', [ self::class, 'note_created' ], 10, 3 );
		add_filter( 'wp_update_term_parent', [ self::class, 'note_updating' ], PHP_INT_MAX, 4 );
		add_filter( 'wp_update_term_data', [ self::class, 'note_update_data' ], PHP_INT_MAX, 3 );
		add_action( 'edited_term', [ self::class, 'note_edited' ], 10, 3 );
		add_action( 'delete_term', [ self::class, 'note_deleted' ], 10, 5 );
		// After the post Collector, which tells which new terms its posts got.
		add_action( 'shutdown', [ self::class, 'flush' ], 1, 0 );
	}

	/**
	 * Forget everything noted (tests, and after a flush).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$inserting = [];
		self::$updating  = [];
		self::$created   = [];
		self::$changed   = [];
		self::$deleted   = [];
	}

	/**
	 * Whether changes to the terms of a taxonomy are reported: taxonomies
	 * with an admin screen, not during an import. Post formats have no
	 * screen; WordPress makes their terms when a format is first used.
	 *
	 * @param string $taxonomy The taxonomy.
	 *
	 * @return bool
	 */
	private static function is_counted( string $taxonomy ): bool {
		if ( 'post_format' === $taxonomy || ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) ) {
			return false;
		}

		$object = get_taxonomy( $taxonomy );

		return is_object( $object ) && ! empty( $object->show_ui );
	}

	/**
	 * `wp_insert_term_data`: a term is about to be added.
	 *
	 * @param mixed $data     The term's name, slug and group.
	 * @param mixed $taxonomy The taxonomy.
	 * @param mixed $args     The arguments, with the parent.
	 *
	 * @return mixed The data, unchanged.
	 */
	public static function note_inserting( $data, $taxonomy, $args ) {
		if ( is_array( $data ) && is_string( $taxonomy ) && self::is_counted( $taxonomy ) ) {
			self::$inserting[ $taxonomy ] = [
				'name'   => (string) ( $data['name'] ?? '' ),
				'slug'   => (string) ( $data['slug'] ?? '' ),
				'parent' => (int) ( is_array( $args ) ? ( $args['parent'] ?? 0 ) : 0 ),
			];
		}

		return $data;
	}

	/**
	 * `created_term`: the term was added.
	 *
	 * @param mixed $term_id  The term ID.
	 * @param mixed $tt_id    The term taxonomy ID.
	 * @param mixed $taxonomy The taxonomy.
	 *
	 * @return void
	 */
	public static function note_created( $term_id, $tt_id, $taxonomy ): void {
		$taxonomy = (string) $taxonomy;
		if ( ! isset( self::$inserting[ $taxonomy ] ) ) {
			return;
		}
		$term = self::$inserting[ $taxonomy ];
		unset( self::$inserting[ $taxonomy ] );

		if ( count( self::$created ) < self::MAX_TERMS ) {
			self::$created[ (int) $tt_id ] = array_merge(
				$term,
				[
					'id'       => (int) $term_id,
					'taxonomy' => $taxonomy,
				]
			);
		}
	}

	/**
	 * `wp_update_term_parent`: a term is about to change. The term is still
	 * in the cache as it was: wp_update_term() has just read it. The parent
	 * passed here is the one that will be saved, and the arguments carry the
	 * new description.
	 *
	 * @param mixed $parent_id   The new parent.
	 * @param mixed $term_id     The term ID.
	 * @param mixed $taxonomy    The taxonomy.
	 * @param mixed $parsed_args The update arguments.
	 *
	 * @return mixed The parent, unchanged.
	 */
	public static function note_updating( $parent_id, $term_id, $taxonomy, $parsed_args ) {
		$taxonomy = (string) $taxonomy;
		if ( ! self::is_counted( $taxonomy ) ) {
			return $parent_id;
		}

		$cached = wp_cache_get( (int) $term_id, 'terms' );
		if ( ! is_object( $cached ) || ! isset( $cached->name ) ) {
			unset( self::$updating[ $taxonomy ] );
			return $parent_id;
		}

		self::$updating[ $taxonomy ] = [
			'old' => [
				'name'        => (string) $cached->name,
				'slug'        => (string) ( $cached->slug ?? '' ),
				'parent'      => (int) ( $cached->parent ?? 0 ),
				'description' => (string) ( $cached->description ?? '' ),
			],
			'new' => [
				'parent'      => (int) $parent_id,
				'description' => (string) ( is_array( $parsed_args ) ? ( $parsed_args['description'] ?? '' ) : '' ),
			],
		];

		return $parent_id;
	}

	/**
	 * `wp_update_term_data`: the new name and slug of the term.
	 *
	 * @param mixed $data     The name, slug and group.
	 * @param mixed $term_id  The term ID.
	 * @param mixed $taxonomy The taxonomy.
	 *
	 * @return mixed The data, unchanged.
	 */
	public static function note_update_data( $data, $term_id, $taxonomy ) {
		$taxonomy = (string) $taxonomy;
		if ( is_array( $data ) && isset( self::$updating[ $taxonomy ] ) ) {
			self::$updating[ $taxonomy ]['new']['name'] = (string) ( $data['name'] ?? '' );
			self::$updating[ $taxonomy ]['new']['slug'] = (string) ( $data['slug'] ?? '' );
		}

		return $data;
	}

	/**
	 * `edited_term`: the term was changed.
	 *
	 * @param mixed $term_id  The term ID.
	 * @param mixed $tt_id    The term taxonomy ID.
	 * @param mixed $taxonomy The taxonomy.
	 *
	 * @return void
	 */
	public static function note_edited( $term_id, $tt_id, $taxonomy ): void {
		$taxonomy = (string) $taxonomy;
		if ( ! isset( self::$updating[ $taxonomy ]['new']['name'] ) ) {
			return;
		}
		$update = self::$updating[ $taxonomy ];
		unset( self::$updating[ $taxonomy ] );

		$tt_id = (int) $tt_id;
		$new   = $update['new'];

		// A term made in this request is reported once, as it is now.
		if ( isset( self::$created[ $tt_id ] ) ) {
			self::$created[ $tt_id ] = array_merge( self::$created[ $tt_id ], array_intersect_key( $new, array_flip( [ 'name', 'slug', 'parent' ] ) ) );
			return;
		}

		if ( isset( self::$changed[ $tt_id ] ) ) {
			self::$changed[ $tt_id ]['new'] = $new;
			return;
		}

		if ( count( self::$changed ) < self::MAX_TERMS ) {
			self::$changed[ $tt_id ] = [
				'id'       => (int) $term_id,
				'taxonomy' => $taxonomy,
				'old'      => $update['old'],
				'new'      => $new,
			];
		}
	}

	/**
	 * `delete_term`: a term was deleted. WordPress passes a copy of it and
	 * the posts (or other objects) that had it.
	 *
	 * @param mixed $term_id      The term ID.
	 * @param mixed $tt_id        The term taxonomy ID.
	 * @param mixed $taxonomy     The taxonomy.
	 * @param mixed $deleted_term The deleted term.
	 * @param mixed $object_ids   The objects that had it.
	 *
	 * @return void
	 */
	public static function note_deleted( $term_id, $tt_id, $taxonomy, $deleted_term, $object_ids ): void {
		$taxonomy = (string) $taxonomy;
		$tt_id    = (int) $tt_id;
		if ( ! $deleted_term instanceof \WP_Term || ! self::is_counted( $taxonomy ) ) {
			return;
		}

		unset( self::$changed[ $tt_id ] );
		// A term made and deleted in one request is no change.
		if ( isset( self::$created[ $tt_id ] ) ) {
			unset( self::$created[ $tt_id ] );
			return;
		}

		if ( count( self::$deleted ) < self::MAX_TERMS ) {
			self::$deleted[ $tt_id ] = [
				'id'       => (int) $term_id,
				'taxonomy' => $taxonomy,
				'name'     => (string) $deleted_term->name,
				'slug'     => (string) $deleted_term->slug,
				'posts'    => is_array( $object_ids ) ? count( $object_ids ) : 0,
			];
		}
	}

	/**
	 * Report the terms created, changed and deleted, once, at the end of
	 * the request.
	 *
	 * @return void
	 */
	public static function flush(): void {
		$created = self::$created;
		$changed = self::$changed;
		$deleted = self::$deleted;
		self::reset();

		// A term made for a post saved in this request is on that post's row.
		$on_posts = PostCollector::added_terms();

		$items = [];
		foreach ( $created as $tt_id => $term ) {
			if ( isset( $on_posts[ $tt_id ] ) ) {
				continue;
			}

			$data = self::describe( $term );
			if ( $term['parent'] > 0 ) {
				$data['parent'] = [ 'id' => $term['parent'] ];
			}
			$items[] = self::item( 'created', $term, $data );
		}

		foreach ( $changed as $term ) {
			$changes = self::changes( $term['old'], $term['new'] );
			if ( [] === $changes ) {
				continue;
			}

			$data            = self::describe( array_merge( $term, $term['new'] ) );
			$data['changes'] = $changes;
			$item            = self::item( 'changed', array_merge( $term, $term['new'] ), $data );
			$item['changed'] = array_keys( $changes );
			$items[]         = $item;
		}

		foreach ( $deleted as $term ) {
			$data          = self::describe( $term );
			$data['posts'] = $term['posts'];
			$item          = self::item( 'deleted', $term, $data );
			$item['posts'] = $term['posts'];
			$items[]       = $item;
		}

		Report::send( Report::KIND_TERM, $items );
	}

	/**
	 * What a term's event says about it: ID, name, slug, taxonomy and the
	 * taxonomy's singular name on the site.
	 *
	 * @param array<string, mixed> $term The term as noted.
	 *
	 * @return array<string, mixed>
	 */
	private static function describe( array $term ): array {
		$object = get_taxonomy( (string) $term['taxonomy'] );

		return [
			'id'       => (int) $term['id'],
			'name'     => self::plain( (string) $term['name'] ),
			'slug'     => (string) $term['slug'],
			'taxonomy' => (string) $term['taxonomy'],
			'label'    => is_object( $object ) && isset( $object->labels->singular_name ) ? self::plain( (string) $object->labels->singular_name ) : (string) $term['taxonomy'],
		];
	}

	/**
	 * A term as a summary item, with its own event.
	 *
	 * @param string               $action `created`, `changed` or `deleted`.
	 * @param array<string, mixed> $term   The term as noted.
	 * @param array<string, mixed> $data   Its event's data.
	 *
	 * @return array<string, mixed>
	 */
	private static function item( string $action, array $term, array $data ): array {
		$types = [
			'created' => 'TermCreated',
			'changed' => 'TermChanged',
			'deleted' => 'TermDeleted',
		];

		return [
			'action' => $action,
			'group'  => (string) $term['taxonomy'],
			'id'     => (int) $term['id'],
			'title'  => Summary::title( (string) $term['name'] ),
			'event'  => [
				'hook' => ContentEvent::hook( $types[ $action ] ),
				'args' => [ $data ],
			],
		];
	}

	/**
	 * What changed in a term: name, slug and description as old and new,
	 * the parent by ID (the job adds the names).
	 *
	 * @param array<string, mixed> $before The values before.
	 * @param array<string, mixed> $after  The values after.
	 *
	 * @return array<string, mixed>
	 */
	public static function changes( array $before, array $after ): array {
		$changes = [];
		foreach ( [ 'name', 'slug', 'description' ] as $field ) {
			$old = (string) ( $before[ $field ] ?? '' );
			$new = (string) ( $after[ $field ] ?? '' );
			if ( $old !== $new ) {
				$changes[ $field ] = [
					'old' => self::cut( self::plain( $old ) ),
					'new' => self::cut( self::plain( $new ) ),
				];
			}
		}

		$old_parent = (int) ( $before['parent'] ?? 0 );
		$new_parent = (int) ( $after['parent'] ?? 0 );
		if ( $old_parent !== $new_parent ) {
			$changes['parent'] = [
				'old' => [ 'id' => $old_parent ],
				'new' => [ 'id' => $new_parent ],
			];
		}

		return $changes;
	}

	/**
	 * Plain text from a value WordPress stores HTML-encoded.
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 */
	private static function plain( string $value ): string {
		return html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * A text cut to the longest description kept.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private static function cut( string $text ): string {
		return mb_strlen( $text ) > self::MAX_DESCRIPTION ? mb_substr( $text, 0, self::MAX_DESCRIPTION ) : $text;
	}
}
