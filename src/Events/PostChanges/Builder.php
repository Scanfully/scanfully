<?php
/**
 * The post change builder class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\PostChanges;

use Scanfully\Events\Controller;
use Scanfully\Events\PostSaved;

/**
 * Class Builder
 *
 * The background job of a `PostSaved` event: turns what the Collector noted
 * during the save into what the event reports (the content diff, the URL
 * before and after, names for authors, parents, terms, featured images and
 * page templates, ACF field labels) and sends the event.
 *
 * @package Scanfully\Events\PostChanges
 */
final class Builder {

	/**
	 * Action Scheduler callback: build a post event and send it.
	 *
	 * @param string $type    The event type.
	 * @param array  $user    The user who made the change.
	 * @param array  $data    The event data, with the changes as the Collector noted them.
	 * @param string $stash   The key of what the job needs beyond its arguments, or ''.
	 * @param int    $attempt Retry number; 0 for the first attempt.
	 *
	 * @return void
	 * @throws \RuntimeException When the API did not accept the event.
	 */
	public static function build( string $type, array $user, array $data, string $stash = '', int $attempt = 0 ): void {
		$noted = is_array( $data['changes'] ?? null ) ? $data['changes'] : [];
		if ( ! empty( $noted['stashed'] ) ) {
			$stored = '' !== $stash ? Stash::get( $stash ) : null;
			$noted  = is_array( $stored['changes'] ?? null ) ? $stored['changes'] : [];
		}

		$event = $data;
		unset( $event['changes'] );
		$changes = self::resolve( $noted, $data );
		if ( [] !== $changes ) {
			$event['changes'] = $changes;
		}

		$retrying = false;
		try {
			Controller::deliver(
				$type,
				$user,
				$event,
				$attempt,
				static function () use ( &$retrying, $type, $user, $data, $stash, $attempt ): void {
					$retrying = true;
					as_schedule_single_action( time() + Controller::SEND_RETRY_DELAY, Controller::ACTION_BUILD_POST_EVENT, [ $type, $user, $data, $stash, $attempt + 1 ], 'scanfully' );
				}
			);
		} finally {
			if ( ! $retrying && '' !== $stash ) {
				Stash::delete( $stash );
			}
		}
	}

	/**
	 * What the event reports as changed.
	 *
	 * @param array<string, mixed> $noted The changes as the Collector noted them.
	 * @param array<string, mixed> $data  The event data (summaries of the post before and after).
	 *
	 * @return array<string, mixed>
	 */
	public static function resolve( array $noted, array $data ): array {
		$post_id   = (int) ( $data['id'] ?? 0 );
		$post_type = (string) ( $data['post']['post_type'] ?? '' );
		$changes   = [];

		foreach ( [ 'title', 'slug' ] as $name ) {
			if ( self::is_pair( $noted[ $name ] ?? null ) ) {
				$changes[ $name ] = self::pair( $noted[ $name ] );
			}
		}

		if ( isset( $noted['slug'] ) || isset( $noted['parent'] ) || isset( $noted['date'] ) ) {
			$url = self::url_change( $data['post_before'] ?? null, $data['post'] ?? null );
			if ( null !== $url ) {
				$changes['url'] = $url;
			}
		}

		if ( isset( $noted['content'] ) && is_array( $noted['content'] ) ) {
			$changes['content'] = self::content( $noted['content'] );
		}

		foreach ( [ 'excerpt', 'date', 'comments', 'menu_order', 'password' ] as $name ) {
			if ( self::is_pair( $noted[ $name ] ?? null ) ) {
				$changes[ $name ] = self::pair( $noted[ $name ] );
			}
		}

		if ( self::is_pair( $noted['parent'] ?? null ) ) {
			$changes['parent'] = [
				'old' => self::parent( (int) $noted['parent']['old'] ),
				'new' => self::parent( (int) $noted['parent']['new'] ),
			];
		}

		if ( self::is_pair( $noted['author'] ?? null ) ) {
			$changes['author'] = [
				'old' => PostSaved::author_name( (int) $noted['author']['old'] ),
				'new' => PostSaved::author_name( (int) $noted['author']['new'] ),
			];
		}

		if ( isset( $noted['fields'] ) && is_array( $noted['fields'] ) ) {
			$fields = self::fields( $post_id, $post_type, $noted['fields'] );
			if ( [] !== $fields ) {
				$changes['fields'] = $fields;
			}
		}

		if ( isset( $noted['terms'] ) && is_array( $noted['terms'] ) ) {
			$terms = self::terms( $noted['terms'] );
			if ( [] !== $terms ) {
				$changes['terms'] = $terms;
			}
		}

		return $changes;
	}

	/**
	 * Whether a value is an old and new pair.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function is_pair( $value ): bool {
		return is_array( $value ) && array_key_exists( 'old', $value ) && array_key_exists( 'new', $value );
	}

	/**
	 * An old and new pair with nothing else.
	 *
	 * @param array<string, mixed> $value The pair.
	 *
	 * @return array{old: mixed, new: mixed}
	 */
	private static function pair( array $value ): array {
		return [
			'old' => $value['old'],
			'new' => $value['new'],
		];
	}

	/**
	 * The content diff.
	 *
	 * @param array<string, mixed> $source Where the content before and after is.
	 *
	 * @return array<string, mixed>
	 */
	private static function content( array $source ): array {
		if ( isset( $source['revisions'] ) && is_array( $source['revisions'] ) ) {
			$after = get_post( (int) ( $source['revisions']['last'] ?? 0 ) );
			if ( ! $after instanceof \WP_Post || 'revision' !== $after->post_type ) {
				return ContentDiff::unchanged_text( 'unavailable' );
			}

			$before = get_post( self::revision_before( (int) $after->post_parent, (int) ( $source['revisions']['first'] ?? 0 ) ) );
			if ( ! $before instanceof \WP_Post ) {
				return ContentDiff::unchanged_text( 'no_earlier_version' );
			}

			return ContentDiff::compare( (string) $before->post_content, (string) $after->post_content );
		}

		if ( isset( $source['stash'] ) && is_array( $source['stash'] ) ) {
			return ContentDiff::compare( (string) ( $source['stash']['old'] ?? '' ), (string) ( $source['stash']['new'] ?? '' ) );
		}

		return ContentDiff::unchanged_text( ! empty( $source['too_large'] ) ? 'too_large' : 'unavailable' );
	}

	/**
	 * The revision of a post made before a given one, skipping autosaves;
	 * 0 when there is none.
	 *
	 * @param int $post_id     The post ID.
	 * @param int $revision_id The revision ID.
	 *
	 * @return int
	 */
	private static function revision_before( int $post_id, int $revision_id ): int {
		global $wpdb;

		if ( $post_id < 1 || $revision_id < 1 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One indexed lookup in a background job; WordPress has no API for "the revision before this one".
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision' AND ID < %d AND post_name NOT LIKE %s ORDER BY ID DESC LIMIT 1",
				$post_id,
				$revision_id,
				'%' . $wpdb->esc_like( '-autosave-v1' )
			)
		);
	}

	/**
	 * The URL before and after, when a published post's URL changed.
	 *
	 * @param mixed $before The summary of the post before.
	 * @param mixed $after  The summary of the post after.
	 *
	 * @return array{old: string, new: string}|null
	 */
	private static function url_change( $before, $after ): ?array {
		if ( ! is_array( $before ) || ! is_array( $after ) || 'publish' !== ( $before['post_status'] ?? '' ) || 'publish' !== ( $after['post_status'] ?? '' ) ) {
			return null;
		}

		$old = self::permalink( $before );
		$new = self::permalink( $after );
		if ( '' === $old || '' === $new || $old === $new ) {
			return null;
		}

		return [
			'old' => $old,
			'new' => $new,
		];
	}

	/**
	 * The permalink of a post as a summary describes it.
	 *
	 * @param array<string, mixed> $summary The summary.
	 *
	 * @return string
	 */
	private static function permalink( array $summary ): string {
		unset( $summary['has_password'] );
		$summary['filter'] = 'raw';

		$link = get_permalink( new \WP_Post( (object) $summary ) );

		return is_string( $link ) ? $link : '';
	}

	/**
	 * A parent post: its ID and title.
	 *
	 * @param int $post_id The parent's ID; 0 for none.
	 *
	 * @return array{id: int, title: string}
	 */
	private static function parent( int $post_id ): array {
		$post = $post_id > 0 ? get_post( $post_id ) : null;

		return [
			'id'    => $post_id,
			'title' => $post instanceof \WP_Post ? self::plain( $post->post_title ) : '',
		];
	}

	/**
	 * The custom field changes, with labels and readable values.
	 *
	 * @param int                                 $post_id   The post ID.
	 * @param string                              $post_type The post type.
	 * @param array<string, array<string, mixed>> $fields    The noted changes, per key.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function fields( int $post_id, string $post_type, array $fields ): array {
		$changes = [];
		foreach ( $fields as $key => $field ) {
			if ( ! is_array( $field ) || ! isset( $field['change'] ) ) {
				continue;
			}

			$key   = (string) $key;
			$entry = [
				'key'    => $key,
				'change' => (string) $field['change'],
			];

			$label = self::field_label( $post_id, $key );
			if ( '' !== $label ) {
				$entry['label'] = $label;
			}

			foreach ( [ 'old', 'new' ] as $side ) {
				if ( isset( $field[ $side ] ) ) {
					$entry[ $side ] = self::field_value( $key, (string) $field[ $side ], $post_type );
				}
			}

			if ( ! empty( $field['large'] ) ) {
				$entry['large'] = true;
			}

			$changes[] = $entry;
		}

		return $changes;
	}

	/**
	 * The label of a custom field, when WordPress knows one: an ACF field's
	 * label.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $key     The key.
	 *
	 * @return string
	 */
	private static function field_label( int $post_id, string $key ): string {
		if ( '_' === $key[0] || ! function_exists( 'acf_get_field' ) ) {
			return '';
		}

		$reference = get_post_meta( $post_id, '_' . $key, true );
		if ( ! is_string( $reference ) || 0 !== strpos( $reference, 'field_' ) ) {
			return '';
		}

		$field = acf_get_field( $reference );

		return is_array( $field ) && isset( $field['label'] ) ? self::plain( $field['label'] ) : '';
	}

	/**
	 * A readable custom field value: a featured image's file name, a page
	 * template's name; other values as they are.
	 *
	 * @param string $key       The key.
	 * @param string $value     The value.
	 * @param string $post_type The post type.
	 *
	 * @return string
	 */
	private static function field_value( string $key, string $value, string $post_type ): string {
		if ( '_thumbnail_id' === $key && ctype_digit( $value ) ) {
			$file = get_attached_file( (int) $value );

			return is_string( $file ) && '' !== $file ? basename( $file ) : $value;
		}

		if ( '_wp_page_template' === $key && 'default' !== $value ) {
			$templates = wp_get_theme()->get_page_templates( null, '' !== $post_type ? $post_type : 'page' );

			return isset( $templates[ $value ] ) ? self::plain( $templates[ $value ] ) : $value;
		}

		return $value;
	}

	/**
	 * The term changes, with names.
	 *
	 * @param array<string, array{added: int[], removed: int[]}> $terms The noted changes, per taxonomy.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function terms( array $terms ): array {
		$changes = [];
		foreach ( $terms as $taxonomy => $set ) {
			$taxonomy = (string) $taxonomy;
			$added    = self::term_names( $taxonomy, (array) ( $set['added'] ?? [] ) );
			$removed  = self::term_names( $taxonomy, (array) ( $set['removed'] ?? [] ) );
			if ( [] === $added && [] === $removed ) {
				continue;
			}

			$object    = get_taxonomy( $taxonomy );
			$changes[] = [
				'taxonomy' => $taxonomy,
				'label'    => is_object( $object ) && isset( $object->labels->name ) ? self::plain( $object->labels->name ) : $taxonomy,
				'added'    => $added,
				'removed'  => $removed,
			];
		}

		return $changes;
	}

	/**
	 * Names of terms by their term taxonomy IDs; terms that no longer exist
	 * are left out.
	 *
	 * @param string $taxonomy The taxonomy.
	 * @param array  $ids      Term taxonomy IDs.
	 *
	 * @return string[]
	 */
	private static function term_names( string $taxonomy, array $ids ): array {
		$names = [];
		foreach ( $ids as $id ) {
			$term = get_term_by( 'term_taxonomy_id', (int) $id, $taxonomy );
			if ( $term instanceof \WP_Term ) {
				$names[] = self::plain( $term->name );
			}
		}

		return $names;
	}

	/**
	 * Plain text from a value WordPress stores HTML-encoded.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function plain( $value ): string {
		return html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}
