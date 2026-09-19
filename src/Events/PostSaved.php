<?php
/**
 * The activated plugin event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class ActivatedPlugin
 *
 * @package Scanfully\Events
 */
class PostSaved extends Event {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'PostSaved', 'wp_after_insert_post', 10, 4 );
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		$post_id = $data[0];
		$post    = $data[1];
		// $update      = $data[2];
		$post_before = $data[3];

		return [
			'id'          => $post_id,
			'title'       => $post->post_title,
			'post_status' => $post->post_status,
			'post_before' => self::summarize_post( $post_before ),
			'post'        => self::summarize_post( $post ),
		];
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
		$password                 = is_object( $post ) ? ( $post->post_password ?? '' ) : ( $post['post_password'] ?? '' );
		$summary['has_password'] = '' !== (string) $password;

		return $summary;
	}

	/**
	 * Track post ID and status pairs that have already had an event scheduled
	 * in this request, to prevent duplicate events when wp_after_insert_post
	 * fires multiple times for the same save.
	 *
	 * @var array<string, bool>
	 */
	private static array $fired_ids = [];

	/**
	 * Transient TTL in seconds used to deduplicate rapid cross-request saves
	 * (e.g. Gutenberg firing two REST API saves in quick succession).
	 */
	private const DEDUP_TTL = 5;

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

		$post_id = (int) $data[0];

		// Duplicates are tracked per post and status: a save that changes the
		// status (draft, then publish) is always reported, while repeated
		// saves with the same status collapse into one event.
		$dedup_key = $post_id . '_' . $post->post_status;

		// Prevent duplicate events for the same save within a single request.
		if ( isset( self::$fired_ids[ $dedup_key ] ) ) {
			return false;
		}

		// Prevent duplicate events across rapid successive requests (e.g. Gutenberg's
		// publish flow can fire two REST saves within a second of each other).
		$transient_key = 'scanfully_post_event_' . $dedup_key;
		if ( get_transient( $transient_key ) ) {
			return false;
		}

		if ( self::is_rate_limited() ) {
			return false;
		}

		set_transient( $transient_key, 1, self::DEDUP_TTL );
		self::$fired_ids[ $dedup_key ] = true;

		return true;
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
