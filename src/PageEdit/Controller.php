<?php
namespace Scanfully\PageEdit;

/**
 * Page Edit Controller
 */
class Controller {

	/**
	 * Setup hooks
	 * @return void
	 */
	public static function setup(): void {
		add_action( 'template_redirect', [ self::class, 'catch_edit_page_request' ] );
	}

	/**
	 * Catch edit page request and redirect to admin edit page
	 * 
	 * Forked from Slash Edit plugin by Ronald Huereca - https://wordpress.org/plugins/slash-edit/
	 * 
	 * @return void
	 */
	public static function catch_edit_page_request(): void {
		if ( isset( $_GET['scanfully-edit'] ) ) {

			$edit_url = false;
			if ( is_attachment() || is_single() || is_page() ) { /* Post, page, attachment, or CPTs */
				// Get the post, page, or cpt id
				$post = get_queried_object();
				$post_id = isset( $post->ID ) ? $post->ID : false;
				if ( $post_id === false )
					return;

				// Build the url
				$edit_url = add_query_arg( array( 'post' => absint( $post_id ), 'action' => 'edit' ), admin_url( 'post.php' ) );
			} elseif ( is_author() ) { /* Author Page */
				$user_data = get_queried_object();
				if ( is_a( $user_data, 'WP_User' ) ) {
					$user_id = $user_data->ID;
					// Build the url
					$edit_url = add_query_arg( array( 'user_id' => absint( $user_id ), 'action' => 'edit' ), admin_url( 'user-edit.php' ) );
				}
			} elseif ( is_category() || is_tag() || is_tax() ) {
				$tax_data = get_queried_object();
				if ( is_object( $tax_data ) && isset( $tax_data->term_id ) ) {
					$term_id = $tax_data->term_id;
					$taxonomy = $tax_data->taxonomy;
					// Build the url
					$edit_url = add_query_arg( array( 'tag_ID' => absint( $term_id ), 'taxonomy' => $taxonomy, 'action' => 'edit', 'post_type' => get_post_type() ), admin_url( 'edit-tags.php' ) );
				}
			}
			// Fail safe for home_url/edit/
			if ( $edit_url === false && 'page' == get_option( 'show_on_front' ) && get_option( 'page_on_front' ) && 'frontpage' == get_query_var( 'edit' ) ) {
				// Build the url
				$edit_url = add_query_arg( array( 'post' => get_option( 'page_on_front' ), 'action' => 'edit' ), admin_url( 'post.php' ) );
			} elseif ( 'frontpage' == get_query_var( 'edit' ) ) {
				// No front page set - so redirect back to homepage
				$edit_url = home_url();
			}

			// Return if nothing to redirect to
			if ( $edit_url === false ) {
				return;
			}

			// Redirect to edit page
			wp_safe_redirect( esc_url_raw( $edit_url ) );
			exit;
		}

	}

}