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
//		$update      = $data[2];
		$post_before = $data[3];

		return [
			'id'          => $post_id,
			'title'       => $post->post_title,
			'post_status' => $post->post_status,
			'post_before' => $post_before,
			'post'        => $post,
		];
	}

	/**
	 * Track post IDs that have already had an event scheduled in this request
	 * to prevent duplicate events when wp_after_insert_post fires multiple times.
	 *
	 * @var array<int, bool>
	 */
	private static array $fired_ids = [];

	/**
	 * Transient TTL in seconds used to deduplicate rapid cross-request saves
	 * (e.g. Gutenberg firing two REST API saves in quick succession).
	 */
	private const DEDUP_TTL = 5;

	/**
	 * A check if a event should fire
	 *
	 * @param  array $data
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return false;
		}

		// only fire if the post status is one of these.
		if ( ! in_array( $data[1]->post_status, [ 'publish', 'draft', 'private', 'trash' ] ) ) {
			return false;
		}

		if ( in_array( $data[1]->post_type, [ 'revision', 'attachment', 'nav_menu_item', 'wp_template', 'wp_template_part' ] ) ) {
			return false;
		}

		$post_id = (int) $data[0];

		// Prevent duplicate events for the same post within a single request.
		if ( isset( self::$fired_ids[ $post_id ] ) ) {
			return false;
		}

		// Prevent duplicate events across rapid successive requests (e.g. Gutenberg's
		// publish flow can fire two REST saves within a second of each other).
		$transient_key = 'scanfully_post_event_' . $post_id;
		if ( get_transient( $transient_key ) ) {
			return false;
		}

		set_transient( $transient_key, 1, self::DEDUP_TTL );
		self::$fired_ids[ $post_id ] = true;

		return true;
	}
}
