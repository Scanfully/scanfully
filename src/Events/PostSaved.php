<?php
/**
 * The activated plugin event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

use Scanfully\Events\PostChanges\Collector;
use Scanfully\Events\PostChanges\Stash;

/**
 * Class PostSaved
 *
 * A post was saved and something changed: it was created, its status
 * changed, or its title, slug, content, custom fields, terms or other
 * fields changed. The Collector notes the changes while the post is saved
 * and reports each changed post once, at the end of the request; a save
 * that changes nothing is not reported. The background job
 * (PostChanges\Builder) works out the content diff and names and sends the
 * event.
 *
 * @package Scanfully\Events
 */
class PostSaved extends Event {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'PostSaved', Collector::ACTION, 10, 5 );

		Collector::setup();
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data passed to the action: post ID, post, update, post before, changes.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$post_id = $data[0];
		$post    = $data[1];
		// $update      = $data[2];
		$post_before = $data[3];
		$changes     = $data[4] ?? [];

		$body = [
			'id'          => $post_id,
			'title'       => $post->post_title,
			'post_status' => $post->post_status,
			'post_before' => self::summarize_post( $post_before ),
			'post'        => self::summarize_post( $post ),
		];

		if ( is_array( $changes ) && [] !== $changes ) {
			$body['changes'] = $changes;
		}

		return $body;
	}

	/**
	 * Schedule the background job that works out what changed and sends
	 * the event. What doesn't fit in the job's arguments (the content of a
	 * post without revisions, many changed fields) is stored for the job.
	 *
	 * @param string $type The event type.
	 * @param array  $user The user who made the change.
	 * @param array  $data The event data.
	 *
	 * @return void
	 */
	protected function schedule( string $type, array $user, array $data ): void {
		$stash = '';
		if ( isset( $data['changes'] ) && ( isset( $data['changes']['content']['stash'] ) || self::args_length( $type, $user, $data ) > self::MAX_ARGS_LENGTH ) ) {
			$stash           = Stash::put( [ 'changes' => $data['changes'] ] );
			$data['changes'] = '' !== $stash ? [ 'stashed' => true ] : self::without_stashed_content( $data['changes'] );
		}

		$args = self::fit_args(
			[
				'type'  => $type,
				'user'  => $user,
				'data'  => $data,
				'stash' => $stash,
			]
		);

		as_schedule_single_action( time(), Controller::ACTION_BUILD_POST_EVENT, $args, 'scanfully' );
	}

	/**
	 * The length of a job's arguments as JSON.
	 *
	 * @param string $type The event type.
	 * @param array  $user The user.
	 * @param array  $data The event data.
	 *
	 * @return int
	 */
	private static function args_length( string $type, array $user, array $data ): int {
		return strlen(
			(string) wp_json_encode(
				[
					'type' => $type,
					'user' => $user,
					'data' => $data,
				]
			)
		);
	}

	/**
	 * Changes without the content to compare, for when it could not be
	 * stored: the content is only reported as changed.
	 *
	 * @param array $changes The changes.
	 *
	 * @return array
	 */
	private static function without_stashed_content( array $changes ): array {
		if ( isset( $changes['content']['stash'] ) ) {
			$changes['content'] = [ 'unavailable' => true ];
		}

		return $changes;
	}

	/**
	 * A user's name as events report it (never an email address); '' when
	 * the user doesn't exist.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return string
	 */
	public static function author_name( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;

		return $user instanceof \WP_User ? self::safe_display_name( $user ) : '';
	}

	/**
	 * Reduce a WP_Post (or similar) to a small set of safe fields.
	 *
	 * The full WP_Post object includes `post_content` and other fields that can
	 * easily push the Action Scheduler args payload past its 8000 character
	 * JSON limit. We only keep the metadata fields that are useful for the
	 * Scanfully timeline.
	 *
	 * @param  mixed $post The post to summarize.
	 *
	 * @return array|null
	 */
	private static function summarize_post( $post ): ?array {
		if ( empty( $post ) ) {
			return null;
		}

		$fields = [
			'ID',
			'post_author',
			'post_date',
			'post_date_gmt',
			'post_modified',
			'post_modified_gmt',
			'post_status',
			'post_title',
			'post_name',
			'post_type',
			'post_parent',
			'comment_status',
			'ping_status',
			'menu_order',
			'guid',
		];

		$summary = [];
		foreach ( $fields as $field ) {
			if ( is_object( $post ) && isset( $post->$field ) ) {
				$summary[ $field ] = $post->$field;
			} elseif ( is_array( $post ) && isset( $post[ $field ] ) ) {
				$summary[ $field ] = $post[ $field ];
			}
		}

		// Never send the password itself, only whether the post has one.
		$password                = is_object( $post ) ? ( $post->post_password ?? '' ) : ( $post['post_password'] ?? '' );
		$summary['has_password'] = '' !== (string) $password;

		return $summary;
	}

	/**
	 * Default maximum number of post events per minute, so bulk edits and
	 * similar mass saves can't queue thousands of jobs. Filterable via
	 * `scanfully_post_saved_events_per_minute`.
	 */
	private const MAX_EVENTS_PER_MINUTE = 30;

	/**
	 * Internal post types that are saved in the background and never
	 * represent content an editor changed.
	 */
	private const IGNORED_POST_TYPES = [
		'revision',
		'attachment',
		'nav_menu_item',
		'wp_template',
		'wp_template_part',
		'oembed_cache',
		'customize_changeset',
		'user_request',
		'acf-field',
		'acf-field-group',
	];

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data The event data.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		// The Heartbeat API runs every 15-60 seconds in wp-admin and never
		// represents an edit. Other AJAX saves (Quick Edit, page builders) are
		// real edits and are reported.
		if ( wp_doing_ajax() && isset( $_POST['action'] ) && 'heartbeat' === $_POST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only compared, never used.
			return false;
		}

		// Imports save many posts at once; they aren't individual edits.
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return false;
		}

		$post = $data[1] ?? null;
		if ( ! is_object( $post ) || ! isset( $post->post_status, $post->post_type ) ) {
			return false;
		}

		// only fire if the post status is one of these.
		if ( ! in_array( $post->post_status, [ 'publish', 'draft', 'private', 'trash' ], true ) ) {
			return false;
		}

		if ( ! self::is_tracked_post_type( (string) $post->post_type ) ) {
			return false;
		}

		/**
		 * Filters whether a post save is reported to Scanfully.
		 *
		 * @param bool   $should_fire Whether to report the save.
		 * @param object $post        The saved post.
		 */
		if ( ! apply_filters( 'scanfully_post_saved_should_fire', true, $post ) ) {
			return false;
		}

		// Repeated saves need no deduplication here: the Collector reports a
		// post once per request, and only when something changed.
		return ! self::is_rate_limited();
	}

	/**
	 * Whether saves of this post type are reported. Skips internal types,
	 * types without an admin screen, and every WooCommerce order type: with
	 * HPOS, each order also saves a backup post (a placeholder, or a
	 * `shop_order` draft when sync is on), which would otherwise turn every
	 * checkout into a timeline event.
	 *
	 * @param string $post_type The post type.
	 *
	 * @return bool
	 */
	private static function is_tracked_post_type( string $post_type ): bool {
		if ( in_array( $post_type, self::IGNORED_POST_TYPES, true ) ) {
			return false;
		}

		if ( function_exists( 'wc_get_order_types' ) && in_array( $post_type, wc_get_order_types(), true ) ) {
			return false;
		}

		$type_object = get_post_type_object( $post_type );

		return is_object( $type_object ) && ! empty( $type_object->show_ui );
	}

	/**
	 * Count this event against the per-minute cap. Returns true when the cap
	 * is reached and the event should be dropped.
	 *
	 * @return bool
	 */
	private static function is_rate_limited(): bool {
		$limit = (int) apply_filters( 'scanfully_post_saved_events_per_minute', self::MAX_EVENTS_PER_MINUTE );
		if ( $limit < 1 ) {
			return false;
		}

		$bucket = 'scanfully_post_events_' . (int) floor( time() / MINUTE_IN_SECONDS );
		$count  = (int) get_transient( $bucket );
		if ( $count >= $limit ) {
			return true;
		}

		set_transient( $bucket, $count + 1, 2 * MINUTE_IN_SECONDS );

		return false;
	}
}
