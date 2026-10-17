<?php
/**
 * The post deleted event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class PostDeleted
 *
 * A post, page or custom post type was deleted permanently: Delete
 * permanently, Empty trash, the trash WordPress empties itself after
 * EMPTY_TRASH_DAYS, or a force delete (WP-CLI, REST). The Collector notes it
 * on `before_delete_post`, while the post still exists, and reports it at
 * the end of the request, in a summary row (PostBulkAction) when many posts
 * were deleted.
 *
 * Data: `id`, `title`, `post_type`, `type_label` (the type's name on the
 * site), `post_status` before the delete, and `expired` with `trash_days`
 * when WordPress emptied the trash itself.
 *
 * @package Scanfully\Events
 */
class PostDeleted extends ContentEvent {

	/**
	 * Statuses of posts nobody made: the auto-draft the editor opens (which
	 * WordPress cleans up after a week), and revisions.
	 */
	private const IGNORED_STATUSES = [ 'auto-draft', 'inherit' ];

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'PostDeleted' );
	}

	/**
	 * Whether deleting a post is reported: a post type with an admin screen,
	 * not an auto-draft, not during an import.
	 *
	 * @param object $post The post.
	 *
	 * @return bool
	 */
	public static function is_reported( object $post ): bool {
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return false;
		}

		if ( ! isset( $post->post_type, $post->post_status ) || in_array( (string) $post->post_status, self::IGNORED_STATUSES, true ) ) {
			return false;
		}

		if ( ! PostSaved::is_tracked_post_type( (string) $post->post_type ) ) {
			return false;
		}

		/**
		 * Filters whether a permanent delete of a post is reported to Scanfully.
		 *
		 * @param bool   $should_fire Whether to report the delete.
		 * @param object $post        The post, before it is deleted.
		 */
		return (bool) apply_filters( 'scanfully_post_deleted_should_fire', true, $post );
	}
}
