<?php
/**
 * The media change collector class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\MediaChanges;

use Scanfully\Events\ContentEvent;
use Scanfully\Events\Content\MinuteStore;
use Scanfully\Events\Controller;
use Scanfully\Options;

/**
 * Class Collector
 *
 * Notes what happens to files in the media library during a request
 * (uploads, changed details, alt text, image edits, a file replaced or
 * renamed, attached or detached, trashed or restored, deleted), and at the
 * end of the request stores the notes for the minute's job (Batch), which
 * merges them per file and person and reports them.
 *
 * WordPress spreads media work over many requests: the media library
 * uploads each file in its own request, the grid view deletes a selection
 * one request per file and saves each detail field on its own. That is why
 * media is reported per minute instead of per request.
 *
 * Like the post Collector it only keeps what WordPress passes to its hooks
 * or has in its cache: the posts before and after an update, the new meta
 * value and the old one from the meta cache WordPress has just loaded to
 * compare. File names, sizes and parent titles are read in the job.
 *
 * @package Scanfully\Events\MediaChanges
 */
final class Collector {

	/**
	 * The MinuteStore kind of media notes.
	 */
	public const KIND = 'media';

	/**
	 * The most files noted in one request.
	 */
	private const MAX_ITEMS = 20000;

	/**
	 * The longest title, caption, description or alt text kept.
	 */
	public const MAX_TEXT = 1000;

	/**
	 * The fields of an attachment's post that are noted, by post field.
	 */
	private const POST_FIELDS = [
		'post_title'   => 'title',
		'post_excerpt' => 'caption',
		'post_content' => 'description',
		'post_name'    => 'slug',
		'post_parent'  => 'parent',
		'post_status'  => 'status',
	];

	/**
	 * The meta keys the collector listens to.
	 */
	private const ALT_KEY       = '_wp_attachment_image_alt';
	private const FILE_KEY      = '_wp_attached_file';
	private const META_KEY      = '_wp_attachment_metadata';
	private const BACKUP_KEY    = '_wp_attachment_backup_sizes';
	private const THUMBNAIL_KEY = '_thumbnail_id';

	/**
	 * What happened to each file in this request, by attachment ID:
	 * - `uploaded`: added in this request, with `edited_from` and `edits`
	 *   for the copy the block editor makes when an image is cropped;
	 * - `old` and `new`: per field, the value before the first change and
	 *   after the last;
	 * - `image`: edited in the image editor (`edits`, `target`) or restored;
	 * - `replaced`: the file replaced through a replace plugin (`old`, `new`);
	 * - `deleted`: deleted permanently (`done` once it went through), with
	 *   what the row says about it;
	 * - `known`: its name, title and type after its post was last saved, for
	 *   the job should the file be deleted before it runs.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $notes = [];

	/**
	 * Files WordPress stored for attachments in this request, by ID, so the
	 * file of an upload needs no query.
	 *
	 * @var array<int, string>
	 */
	private static array $files = [];

	/**
	 * The media REST route being served: `type` (`edit`, `sideload`,
	 * `finalize`), `id`, and the `edits` of an edit.
	 *
	 * @var array<string, mixed>
	 */
	private static array $route = [];

	/**
	 * The image editor request being served: `id`, `do` (`save`, `scale`,
	 * `restore`), `edits` and `target`. Noted once WordPress writes the new
	 * metadata, so a refused or failed request is not reported.
	 *
	 * @var array<string, mixed>
	 */
	private static array $image_editor = [];

	/**
	 * The attachment WordPress is deleting.
	 *
	 * @var int
	 */
	private static int $deleting = 0;

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

		add_action( 'add_attachment', [ self::class, 'note_added' ], 10, 1 );
		add_action( 'attachment_updated', [ self::class, 'note_updated' ], 10, 3 );
		add_action( 'update_post_meta', [ self::class, 'note_meta_updating' ], 10, 4 );
		add_action( 'added_post_meta', [ self::class, 'note_meta_added' ], 10, 4 );
		add_action( 'delete_post_meta', [ self::class, 'note_meta_deleting' ], 10, 4 );
		add_action( 'wp_media_attach_action', [ self::class, 'note_attach' ], 10, 3 );
		add_action( 'delete_attachment', [ self::class, 'note_deleting' ], 10, 2 );
		add_action( 'deleted_post', [ self::class, 'note_deleted' ], 10, 1 );
		add_action( 'wp_ajax_image-editor', [ self::class, 'note_image_editor' ], 0, 0 );
		add_filter( 'rest_request_before_callbacks', [ self::class, 'note_rest_route' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ self::class, 'forget_rest_route' ], 10, 1 );
		add_action( 'enable-media-replace-upload-done', [ self::class, 'note_replaced' ], 10, 3 );
		// After the post and term collectors.
		add_action( 'shutdown', [ self::class, 'flush' ], 2, 0 );
	}

	/**
	 * Forget everything noted (tests, and after a flush).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$notes        = [];
		self::$files        = [];
		self::$route        = [];
		self::$image_editor = [];
		self::$deleting     = 0;
	}

	/**
	 * Whether media changes are noted now: not during an import, which adds
	 * many files nobody uploaded by hand.
	 *
	 * @return bool
	 */
	private static function is_noting(): bool {
		return ! ( defined( 'WP_IMPORTING' ) && WP_IMPORTING );
	}

	/**
	 * Make sure a file has a note, when there is room for it: a request
	 * notes at most MAX_ITEMS files.
	 *
	 * @param int $id The attachment ID.
	 *
	 * @return bool Whether the file has a note.
	 */
	private static function open( int $id ): bool {
		if ( isset( self::$notes[ $id ] ) ) {
			return true;
		}
		if ( $id < 1 || count( self::$notes ) >= self::MAX_ITEMS ) {
			return false;
		}

		self::$notes[ $id ] = [];

		return true;
	}

	/**
	 * Whether nothing more is noted about a file: it was added in this
	 * request (the job reads how it is now), or it is being deleted.
	 *
	 * @param int $id The attachment ID.
	 *
	 * @return bool
	 */
	private static function is_settled( int $id ): bool {
		return ! empty( self::$notes[ $id ]['uploaded'] ) || isset( self::$notes[ $id ]['deleted'] ) || self::$deleting === $id;
	}

	/**
	 * `add_attachment`: a file was added to the media library.
	 *
	 * @param mixed $post_id The attachment ID.
	 *
	 * @return void
	 */
	public static function note_added( $post_id ): void {
		$id = (int) $post_id;
		if ( ! self::is_noting() || ! self::open( $id ) ) {
			return;
		}

		self::$notes[ $id ] = [ 'uploaded' => true ];
		if ( 'edit' === ( self::$route['type'] ?? '' ) ) {
			self::$notes[ $id ]['edited_from'] = (int) self::$route['id'];
			self::$notes[ $id ]['edits']       = (array) self::$route['edits'];
		}
	}

	/**
	 * `attachment_updated`: an attachment's post was saved.
	 *
	 * @param mixed $post_id The attachment ID.
	 * @param mixed $after   The post after the update.
	 * @param mixed $before  The post before the update.
	 *
	 * @return void
	 */
	public static function note_updated( $post_id, $after, $before ): void {
		$id = (int) $post_id;
		if ( ! is_object( $after ) || ! is_object( $before ) || ! self::is_noting() || self::is_settled( $id ) ) {
			return;
		}

		// The `__trashed` slug WordPress gives a file in the trash, or getting
		// the old one back, isn't a change of its own.
		$trashed = 'trash' === ( $before->post_status ?? '' ) || 'trash' === ( $after->post_status ?? '' );

		foreach ( self::POST_FIELDS as $post_field => $field ) {
			if ( 'slug' === $field && $trashed ) {
				continue;
			}

			$old = $before->$post_field ?? '';
			$new = $after->$post_field ?? '';
			if ( 'parent' === $field ) {
				$old = (int) $old;
				$new = (int) $new;
			} else {
				$old = (string) $old;
				$new = (string) $new;
			}

			if ( $old !== $new ) {
				self::change( $id, $field, $old, $new );
			}
		}

		// What the job says about the file should someone delete it before
		// the job runs, from the post WordPress passes.
		if ( isset( self::$notes[ $id ] ) ) {
			self::$notes[ $id ]['known'] = self::known( $id, $after );
		}
	}

	/**
	 * A file's name, title and type from its post and the meta cache, never
	 * the database.
	 *
	 * @param int    $id   The attachment ID.
	 * @param object $post The attachment.
	 *
	 * @return array{name: string, file: string, title: string, mime_type: string}
	 */
	private static function known( int $id, object $post ): array {
		$file = self::cached_value( $id, self::FILE_KEY );
		$file = is_string( $file ) ? $file : '';

		return [
			'name'      => Details::name( $file, (string) ( $post->guid ?? '' ) ),
			'file'      => $file,
			'title'     => self::text( (string) ( $post->post_title ?? '' ) ),
			'mime_type' => (string) ( $post->post_mime_type ?? '' ),
		];
	}

	/**
	 * `update_post_meta`: a meta value is about to change.
	 *
	 * @param mixed $meta_id   The meta ID.
	 * @param mixed $object_id The post ID.
	 * @param mixed $meta_key  The key.
	 * @param mixed $value     The new value.
	 *
	 * @return void
	 */
	public static function note_meta_updating( $meta_id, $object_id, $meta_key, $value ): void {
		self::note_meta( (int) $object_id, (string) $meta_key, $value, true );
	}

	/**
	 * `added_post_meta`: a meta value was added.
	 *
	 * @param mixed $meta_id   The meta ID.
	 * @param mixed $object_id The post ID.
	 * @param mixed $meta_key  The key.
	 * @param mixed $value     The value.
	 *
	 * @return void
	 */
	public static function note_meta_added( $meta_id, $object_id, $meta_key, $value ): void {
		self::note_meta( (int) $object_id, (string) $meta_key, $value, false );
	}

	/**
	 * `delete_post_meta`: meta values are about to be removed. While a file
	 * is deleted, WordPress removes it as featured image from every post in
	 * one go: one meta ID per post.
	 *
	 * @param mixed $meta_ids  The meta IDs.
	 * @param mixed $object_id The post ID, 0 when removed from every post.
	 * @param mixed $meta_key  The key.
	 * @param mixed $value     The value asked to remove, if any.
	 *
	 * @return void
	 */
	public static function note_meta_deleting( $meta_ids, $object_id, $meta_key, $value ): void {
		$meta_key = (string) $meta_key;
		if ( self::THUMBNAIL_KEY === $meta_key ) {
			if ( self::$deleting > 0 && (int) $object_id < 1 && is_scalar( $value ) && (int) $value === self::$deleting && isset( self::$notes[ self::$deleting ]['deleted'] ) ) {
				self::$notes[ self::$deleting ]['deleted']['featured_on'] = is_array( $meta_ids ) ? count( $meta_ids ) : 0;
			}
			return;
		}

		if ( self::ALT_KEY === $meta_key ) {
			self::note_meta( (int) $object_id, $meta_key, '', true );
		}
	}

	/**
	 * Note a meta change of an attachment: alt text and file, and the
	 * metadata write that confirms an image editor request.
	 *
	 * @param int    $id      The attachment ID.
	 * @param string $key     The key.
	 * @param mixed  $value   The value after the change.
	 * @param bool   $existed Whether the key existed before the change.
	 *
	 * @return void
	 */
	private static function note_meta( int $id, string $key, $value, bool $existed ): void {
		switch ( $key ) {
			case self::ALT_KEY:
				if ( self::is_noting() && ! self::is_settled( $id ) ) {
					$old = $existed ? self::cached_value( $id, $key ) : '';
					if ( null !== $old ) {
						self::change( $id, 'alt', (string) $old, is_scalar( $value ) ? (string) $value : '' );
					}
				}
				return;
			case self::FILE_KEY:
				self::note_file( $id, is_scalar( $value ) ? (string) $value : '', $existed );
				return;
			case self::META_KEY:
			case self::BACKUP_KEY:
				self::note_image_edited( $id );
				return;
		}
	}

	/**
	 * Note a change of the file of an attachment. WordPress's own follow-up
	 * files (`-scaled`, `-rotated`), the 7.x upload steps (`sideload`,
	 * `finalize`) and the image editor's new file are not a change of their
	 * own: the upload or the image edit says it.
	 *
	 * @param int    $id      The attachment ID.
	 * @param string $file    The new file, relative to the uploads folder.
	 * @param bool   $existed Whether the attachment had a file before.
	 *
	 * @return void
	 */
	private static function note_file( int $id, string $file, bool $existed ): void {
		if ( $id < 1 || '' === $file ) {
			return;
		}

		if ( ! $existed ) {
			// An upload stores its file just before `add_attachment`.
			if ( count( self::$files ) < self::MAX_ITEMS ) {
				self::$files[ $id ] = $file;
			}
			return;
		}

		if ( ! self::is_noting() || self::is_settled( $id ) || in_array( self::$route['type'] ?? '', [ 'sideload', 'finalize' ], true ) || ( self::$image_editor['id'] ?? 0 ) === $id ) {
			return;
		}

		$old = self::cached_value( $id, self::FILE_KEY );
		if ( null === $old || self::same_file( (string) $old, $file ) ) {
			return;
		}

		self::change( $id, 'file', (string) $old, $file );
	}

	/**
	 * Whether two file paths are the same file apart from the suffixes
	 * WordPress adds itself: `-scaled` for a big image, `-rotated` for a
	 * photo turned upright.
	 *
	 * @param string $before The old path.
	 * @param string $after  The new path.
	 *
	 * @return bool
	 */
	public static function same_file( string $before, string $after ): bool {
		$strip = static function ( string $path ): string {
			return (string) preg_replace( '/(?:-scaled|-rotated)+(\.[^.\/]+)$/', '$1', $path );
		};

		return $strip( $before ) === $strip( $after );
	}

	/**
	 * `wp_media_attach_action`: files attached to or detached from a post in
	 * the media library's list view. WordPress updates the parent with SQL,
	 * so no post update hook fires.
	 *
	 * @param mixed $action        `attach` or `detach`.
	 * @param mixed $attachment_id The attachment ID.
	 * @param mixed $parent_id     The post attached to.
	 *
	 * @return void
	 */
	public static function note_attach( $action, $attachment_id, $parent_id ): void {
		$id = (int) $attachment_id;
		if ( ! self::is_noting() || self::is_settled( $id ) ) {
			return;
		}

		// The post cache still holds the parent from before: WordPress read
		// the attachment to check the user may edit it, and clears the cache
		// right after this hook. A detach passes the post it detaches from.
		$cached = wp_cache_get( $id, 'posts' );
		$new    = 'attach' === $action ? (int) $parent_id : 0;
		if ( is_object( $cached ) && isset( $cached->post_parent ) ) {
			$old = (int) $cached->post_parent;
		} else {
			$old = 'attach' === $action ? 0 : (int) $parent_id;
		}
		if ( $old !== $new ) {
			self::change( $id, 'parent', $old, $new );
		}
	}

	/**
	 * Note a change of a field: the value before the first change and after
	 * the last.
	 *
	 * @param int        $id     The attachment ID.
	 * @param string     $field  The field.
	 * @param int|string $before The value before.
	 * @param int|string $after  The value after.
	 *
	 * @return void
	 */
	private static function change( int $id, string $field, $before, $after ): void {
		if ( ! self::open( $id ) ) {
			return;
		}

		if ( is_string( $before ) && is_string( $after ) && in_array( $field, [ 'title', 'caption', 'description', 'alt' ], true ) ) {
			$before = self::text( $before );
			$after  = self::text( $after );
		}

		if ( ! array_key_exists( $field, self::$notes[ $id ]['old'] ?? [] ) ) {
			self::$notes[ $id ]['old'][ $field ] = $before;
		}
		self::$notes[ $id ]['new'][ $field ] = $after;
	}

	/**
	 * `wp_ajax_image-editor`, before WordPress handles it: the image editor
	 * saves, scales or restores an image. What it does is read from the
	 * request here and only noted once WordPress has checked the nonce and
	 * saved the image (note_image_edited).
	 *
	 * @return void
	 */
	public static function note_image_editor(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- WordPress verifies the nonce right after; nothing is noted unless it saved the image.
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		$id = isset( $_REQUEST['postid'] ) ? (int) $_REQUEST['postid'] : 0;
		if ( $id < 1 || ! in_array( $do, [ 'save', 'scale', 'restore' ], true ) ) {
			return;
		}

		$edits = 'scale' === $do ? [ 'scale' ] : [];
		if ( 'save' === $do && isset( $_REQUEST['history'] ) ) {
			$history = json_decode( (string) wp_unslash( $_REQUEST['history'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, only read for its keys.
			$edits   = self::history_edits( is_array( $history ) ? $history : [] );
		}

		$target = isset( $_REQUEST['target'] ) ? sanitize_key( wp_unslash( $_REQUEST['target'] ) ) : 'all';
		// phpcs:enable

		self::$image_editor = [
			'id'     => $id,
			'do'     => $do,
			'edits'  => $edits,
			'target' => '' !== $target ? $target : 'all',
		];
	}

	/**
	 * The edits in the image editor's history: crop, rotate and flip, in
	 * the order first made.
	 *
	 * @param array<int|string, mixed> $history The history: one object per step.
	 *
	 * @return array<int, string>
	 */
	public static function history_edits( array $history ): array {
		$names = [
			'c' => 'crop',
			'r' => 'rotate',
			'f' => 'flip',
		];

		$edits = [];
		foreach ( $history as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			foreach ( $names as $key => $name ) {
				if ( isset( $step[ $key ] ) && ! in_array( $name, $edits, true ) ) {
					$edits[] = $name;
				}
			}
		}

		return $edits;
	}

	/**
	 * Note an image editor request as done: WordPress wrote the image's new
	 * metadata or backup sizes.
	 *
	 * @param int $id The attachment ID.
	 *
	 * @return void
	 */
	private static function note_image_edited( int $id ): void {
		if ( ( self::$image_editor['id'] ?? 0 ) !== $id || ! self::is_noting() || self::is_settled( $id ) || ! self::open( $id ) ) {
			return;
		}

		self::$notes[ $id ]['image'] = 'restore' === self::$image_editor['do']
			? [ 'restored' => true ]
			: [
				'edits'  => self::$image_editor['edits'],
				'target' => self::$image_editor['target'],
			];
	}

	/**
	 * `rest_request_before_callbacks`: note the media routes that make an
	 * edited copy (`edit`) or finish an upload in the browser (`sideload`,
	 * `finalize`, WordPress 7.0 and later).
	 *
	 * @param mixed $response The response so far.
	 * @param mixed $handler  The route handler.
	 * @param mixed $request  The request.
	 *
	 * @return mixed The response, unchanged.
	 */
	public static function note_rest_route( $response, $handler, $request ) {
		self::$route = [];
		if ( ! $request instanceof \WP_REST_Request || 1 !== preg_match( '#^/wp/v2/media/(\d+)/(edit|sideload|finalize)$#', (string) $request->get_route(), $match ) ) {
			return $response;
		}

		self::$route = [
			'type'  => $match[2],
			'id'    => (int) $match[1],
			'edits' => 'edit' === $match[2] ? self::rest_edits( $request ) : [],
		];

		return $response;
	}

	/**
	 * `rest_request_after_callbacks`: the media route is done.
	 *
	 * @param mixed $response The response.
	 *
	 * @return mixed The response, unchanged.
	 */
	public static function forget_rest_route( $response ) {
		self::$route = [];

		return $response;
	}

	/**
	 * The edits of a block editor image edit: its `modifiers`, or the older
	 * `rotation` and crop parameters.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return array<int, string>
	 */
	private static function rest_edits( \WP_REST_Request $request ): array {
		$edits     = [];
		$modifiers = $request->get_param( 'modifiers' );
		if ( is_array( $modifiers ) ) {
			foreach ( $modifiers as $modifier ) {
				$type = is_array( $modifier ) ? sanitize_key( (string) ( $modifier['type'] ?? '' ) ) : '';
				if ( in_array( $type, [ 'crop', 'rotate', 'flip' ], true ) && ! in_array( $type, $edits, true ) ) {
					$edits[] = $type;
				}
			}

			return $edits;
		}

		if ( ! empty( $request->get_param( 'rotation' ) ) ) {
			$edits[] = 'rotate';
		}
		if ( null !== $request->get_param( 'width' ) && null !== $request->get_param( 'height' ) ) {
			$edits[] = 'crop';
		}

		return $edits;
	}

	/**
	 * `enable-media-replace-upload-done`: Enable Media Replace replaced the
	 * file of an attachment, under the same name or a new one.
	 *
	 * @param mixed $target_url The URL of the new file.
	 * @param mixed $source_url The URL of the old file.
	 * @param mixed $post_id    The attachment ID.
	 *
	 * @return void
	 */
	public static function note_replaced( $target_url, $source_url, $post_id ): void {
		$id = (int) $post_id;
		if ( ! self::is_noting() || self::is_settled( $id ) || ! self::open( $id ) ) {
			return;
		}

		if ( ! isset( self::$notes[ $id ]['replaced'] ) ) {
			self::$notes[ $id ]['replaced'] = [ 'old' => self::url_name( $source_url ) ];
		}
		self::$notes[ $id ]['replaced']['new'] = self::url_name( $target_url );
		// The replace says what happened to the file.
		unset( self::$notes[ $id ]['old']['file'], self::$notes[ $id ]['new']['file'] );
	}

	/**
	 * The file name in a URL.
	 *
	 * @param mixed $url The URL.
	 *
	 * @return string
	 */
	private static function url_name( $url ): string {
		$path = is_string( $url ) ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';

		return '' !== $path ? rawurldecode( wp_basename( $path ) ) : '';
	}

	/**
	 * `delete_attachment`: a file is about to be deleted permanently. Its
	 * file and metadata are in the meta cache: WordPress has just read them
	 * to know which files to remove.
	 *
	 * @param mixed $post_id The attachment ID.
	 * @param mixed $post    The attachment.
	 *
	 * @return void
	 */
	public static function note_deleting( $post_id, $post = null ): void {
		$id = (int) $post_id;
		if ( ! is_object( $post ) || ! self::is_noting() || ! self::open( $id ) ) {
			return;
		}

		self::$deleting = $id;
		if ( ! empty( self::$notes[ $id ]['uploaded'] ) ) {
			// Added and deleted in one request: nothing to report.
			self::$notes[ $id ]['deleted'] = [ 'done' => false ];
			return;
		}

		$meta = self::cached_value( $id, self::META_KEY );
		$file = self::cached_value( $id, self::FILE_KEY );

		self::$notes[ $id ]['deleted'] = [
			'title'       => self::text( (string) ( $post->post_title ?? '' ) ),
			'file'        => is_string( $file ) ? $file : '',
			'mime_type'   => (string) ( $post->post_mime_type ?? '' ),
			'post_status' => (string) ( $post->post_status ?? '' ),
			'parent'      => (int) ( $post->post_parent ?? 0 ),
			'facts'       => is_array( $meta ) ? Details::facts( $meta ) : [],
			'featured_on' => 0,
			// WordPress empties the media trash in the `wp_scheduled_delete` cron job.
			'expired'     => doing_action( 'wp_scheduled_delete' ),
			'done'        => false,
		];
	}

	/**
	 * `deleted_post`: the delete went through.
	 *
	 * @param mixed $post_id The post ID.
	 *
	 * @return void
	 */
	public static function note_deleted( $post_id ): void {
		$id = (int) $post_id;
		if ( isset( self::$notes[ $id ]['deleted'] ) ) {
			self::$notes[ $id ]['deleted']['done'] = true;
		}
		if ( self::$deleting === $id ) {
			self::$deleting = 0;
		}
	}

	/**
	 * Store the notes of the request for the minute's job, once, at the end
	 * of the request. A request that noted nothing writes nothing.
	 *
	 * @return void
	 */
	public static function flush(): void {
		$notes = self::$notes;
		$files = self::$files;
		self::reset();

		$items = [];
		foreach ( $notes as $id => $note ) {
			$item = self::item( (int) $id, $note, $files );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		// A site that isn't connected has nowhere to send events.
		if ( [] === $items || ! Options\Controller::get_options()->is_connected ) {
			return;
		}

		$user = wp_get_current_user();
		MinuteStore::add(
			self::KIND,
			[
				'user'  => [
					'id'   => (int) $user->ID,
					'name' => ContentEvent::user_name( $user ),
				],
				'items' => $items,
			],
			Controller::ACTION_SEND_MEDIA
		);
	}

	/**
	 * A file's note as the job reads it; null when there is nothing to
	 * report.
	 *
	 * @param int                  $id    The attachment ID.
	 * @param array<string, mixed> $note  The note.
	 * @param array<int, string>   $files The files stored in the request.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function item( int $id, array $note, array $files ): ?array {
		if ( isset( $note['deleted'] ) ) {
			if ( empty( $note['deleted']['done'] ) || ! empty( $note['uploaded'] ) ) {
				return null;
			}
			$deleted = $note['deleted'];
			unset( $deleted['done'] );

			return [
				'id'      => $id,
				'deleted' => $deleted,
			];
		}

		if ( ! empty( $note['uploaded'] ) ) {
			$item = [
				'id'       => $id,
				'uploaded' => true,
			];
			if ( isset( $files[ $id ] ) ) {
				$item['file'] = $files[ $id ];
			}
			if ( isset( $note['edited_from'] ) ) {
				$item['edited_from'] = $note['edited_from'];
				$item['edits']       = $note['edits'] ?? [];
			}

			return $item;
		}

		$item = [ 'id' => $id ];
		foreach ( (array) ( $note['new'] ?? [] ) as $field => $new ) {
			$old = $note['old'][ $field ] ?? null;
			if ( $old !== $new ) {
				$item['old'][ $field ] = $old;
				$item['new'][ $field ] = $new;
			}
		}
		foreach ( [ 'image', 'replaced' ] as $key ) {
			if ( isset( $note[ $key ] ) ) {
				$item[ $key ] = $note[ $key ];
			}
		}
		if ( count( $item ) < 2 ) {
			return null;
		}
		if ( isset( $note['known'] ) ) {
			$item['known'] = $note['known'];
		}

		return $item;
	}

	/**
	 * An attachment's meta value from the meta cache, without a query; null
	 * when it isn't cached.
	 *
	 * @param int    $id  The attachment ID.
	 * @param string $key The key.
	 *
	 * @return mixed
	 */
	private static function cached_value( int $id, string $key ) {
		$cache = wp_cache_get( $id, 'post_meta' );
		if ( ! is_array( $cache ) ) {
			return null;
		}
		if ( ! isset( $cache[ $key ] ) || ! is_array( $cache[ $key ] ) || [] === $cache[ $key ] ) {
			// Cached, without this key: it was empty.
			return '';
		}

		return maybe_unserialize( $cache[ $key ][0] );
	}

	/**
	 * Plain text, cut to the longest text kept.
	 *
	 * @param string $text The text, as WordPress stores it.
	 *
	 * @return string
	 */
	private static function text( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

		return mb_strlen( $text ) > self::MAX_TEXT ? mb_substr( $text, 0, self::MAX_TEXT ) : $text;
	}
}
