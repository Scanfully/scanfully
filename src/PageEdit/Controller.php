<?php
/**
 * The page edit controller class file.
 *
 * @package Scanfully
 */

namespace Scanfully\PageEdit;

/**
 * Page Edit Controller
 *
 * Adding `?scanfully-edit` to a front-end URL opens the matching edit screen in
 * wp-admin. The Scanfully dashboard links to it from its broken links and
 * broken media reports. Visitors who are not logged in are sent to the login
 * screen first and come back to the editor afterwards.
 */
class Controller {

	/**
	 * Setup hooks
	 *
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
		if ( ! isset( $_GET['scanfully-edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only redirects to screens WordPress protects itself.
			return;
		}

		// The response depends on who is asking, so page caches must never
		// store it for the URL.
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Shared constant page cache plugins read.
		}

		$redirect_url = self::get_redirect_url();
		if ( '' === $redirect_url ) {
			return;
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Where to send the current request: the login screen for visitors who
	 * are not logged in, the edit screen for users who may edit the current
	 * object, or nowhere (an empty string) to show the normal page.
	 *
	 * @return string
	 */
	private static function get_redirect_url(): string {
		if ( ! is_user_logged_in() ) {
			$return_url = self::get_page_url();

			return '' === $return_url ? '' : wp_login_url( add_query_arg( 'scanfully-edit', '1', $return_url ) );
		}

		return self::get_edit_url();
	}

	/**
	 * The edit screen for the current object. WordPress' edit link helpers
	 * check the user's capabilities and return nothing for types without an
	 * admin screen.
	 *
	 * @return string The edit URL, or an empty string when there is nothing the user may edit.
	 */
	private static function get_edit_url(): string {
		$post_id = self::get_queried_post_id();
		if ( $post_id > 0 ) {
			return (string) get_edit_post_link( $post_id, 'raw' );
		}

		if ( is_author() ) {
			return (string) get_edit_user_link( get_queried_object_id() );
		}

		$term = self::get_queried_term();
		if ( null !== $term ) {
			return (string) get_edit_term_link( $term, $term->taxonomy, (string) get_post_type() );
		}

		return '';
	}

	/**
	 * The public URL of the current object, used to return to it after login.
	 * Built from the object rather than the request, so it always points at
	 * this site.
	 *
	 * @return string The URL, or an empty string when there is nothing to edit.
	 */
	private static function get_page_url(): string {
		$post_id = self::get_queried_post_id();
		if ( $post_id > 0 ) {
			return (string) get_permalink( $post_id );
		}

		if ( is_author() ) {
			return (string) get_author_posts_url( get_queried_object_id() );
		}

		$term = self::get_queried_term();
		if ( null !== $term ) {
			$url = get_term_link( $term );
			return is_string( $url ) ? $url : '';
		}

		return '';
	}

	/**
	 * The post being viewed: a post, page, attachment or custom post type,
	 * or the page set as the posts page.
	 *
	 * @return int The post ID, or 0 when the current request is not a post.
	 */
	private static function get_queried_post_id(): int {
		if ( is_singular() ) {
			return (int) get_queried_object_id();
		}

		if ( is_home() && 'page' === get_option( 'show_on_front' ) ) {
			return (int) get_option( 'page_for_posts' );
		}

		return 0;
	}

	/**
	 * The term being viewed on a category, tag or taxonomy archive.
	 *
	 * @return object|null The term, or null when the current request is not a term archive.
	 */
	private static function get_queried_term(): ?object {
		if ( ! is_category() && ! is_tag() && ! is_tax() ) {
			return null;
		}

		$term = get_queried_object();

		return is_object( $term ) && isset( $term->term_id, $term->taxonomy ) ? $term : null;
	}
}
