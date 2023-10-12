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
		if ( in_array( $data[1]->post_status, [ "publish", "draft", "private", "trash" ] ) && ! in_array( $data[1]->post_type, [ 'revision', 'attachment', 'nav_menu_item', 'wp_template', 'wp_template_part' ] ) ) {
			return true;
		}

		return false;
	}
}
