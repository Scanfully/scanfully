<?php
/**
 * PageEdit redirect integration tests.
 *
 * Runs against a real WordPress query and real users (wp-env) and skips
 * otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Scanfully\PageEdit\Controller;

/**
 * Verifies where ?scanfully-edit sends each kind of visitor.
 */
final class PageEditTest extends TestCase {

	/**
	 * IDs of posts, terms and users created by a test.
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $created = [
		'posts' => [],
		'terms' => [],
		'users' => [],
	];

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wp_insert_post' ) ) {
			$this->markTestSkipped( 'WordPress runtime not available. Run this suite under wp-env.' );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}

	protected function tearDown(): void {
		if ( function_exists( 'wp_delete_post' ) ) {
			foreach ( $this->created['posts'] as $id ) {
				wp_delete_post( $id, true );
			}
			foreach ( $this->created['terms'] as $id ) {
				wp_delete_term( $id, 'category' );
			}
			foreach ( $this->created['users'] as $id ) {
				wp_delete_user( $id );
			}
			wp_set_current_user( 0 );
			wp_reset_query();
		}
		parent::tearDown();
	}

	/**
	 * Create a user with a role and log in as them (0 logs out).
	 *
	 * @param string $role Role, or an empty string to log out.
	 *
	 * @return int The user ID.
	 */
	private function log_in_as( string $role ): int {
		if ( '' === $role ) {
			wp_set_current_user( 0 );
			return 0;
		}
		$id                       = wp_insert_user(
			[
				'user_login' => 'scanfully_pe_' . $role . '_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => $role,
			]
		);
		$this->created['users'][] = $id;
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * Point the main query at the given query vars, as a front-end request would.
	 *
	 * @param array<string, mixed> $query_vars Query vars.
	 */
	private function visit( array $query_vars ): void {
		$GLOBALS['wp_query']     = new \WP_Query( $query_vars );
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	}

	/**
	 * Create a published post.
	 *
	 * @param string $post_type Post type.
	 *
	 * @return int
	 */
	private function make_post( string $post_type = 'post' ): int {
		$id                       = wp_insert_post(
			[
				'post_title'  => 'PageEdit test',
				'post_status' => 'publish',
				'post_type'   => $post_type,
			]
		);
		$this->created['posts'][] = $id;
		return $id;
	}

	/**
	 * Where the current request would be redirected.
	 *
	 * @return string
	 */
	private function redirect_url(): string {
		$method = new ReflectionMethod( Controller::class, 'get_redirect_url' );
		$method->setAccessible( true );
		return $method->invoke( null );
	}

	public function test_a_logged_out_visitor_is_sent_to_login_and_back_to_the_editor(): void {
		$post_id = $this->make_post();
		$this->log_in_as( '' );
		$this->visit( [ 'p' => $post_id ] );

		$url = $this->redirect_url();

		$this->assertStringStartsWith( wp_login_url(), $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( add_query_arg( 'scanfully-edit', '1', get_permalink( $post_id ) ), $query['redirect_to'] );
	}

	public function test_a_logged_out_visitor_on_a_missing_page_is_not_redirected(): void {
		$this->log_in_as( '' );
		$this->visit( [ 'p' => 999999 ] );

		$this->assertSame( '', $this->redirect_url() );
	}

	public function test_an_editor_is_sent_to_the_post_edit_screen(): void {
		$post_id = $this->make_post();
		$this->log_in_as( 'editor' );
		$this->visit( [ 'p' => $post_id ] );

		$this->assertSame( get_edit_post_link( $post_id, 'raw' ), $this->redirect_url() );
	}

	public function test_a_subscriber_just_sees_the_page(): void {
		$post_id = $this->make_post();
		$this->log_in_as( 'subscriber' );
		$this->visit( [ 'p' => $post_id ] );

		$this->assertSame( '', $this->redirect_url() );
	}

	public function test_a_post_type_without_an_admin_screen_is_not_redirected(): void {
		register_post_type(
			'scanfully_hidden',
			[
				'public'  => true,
				'show_ui' => false,
			]
		);
		$post_id = $this->make_post( 'scanfully_hidden' );
		$this->log_in_as( 'administrator' );
		$this->visit(
			[
				'p'         => $post_id,
				'post_type' => 'scanfully_hidden',
			]
		);

		$this->assertSame( '', $this->redirect_url() );
		unregister_post_type( 'scanfully_hidden' );
	}

	public function test_an_editor_on_a_category_archive_is_sent_to_the_term_edit_screen(): void {
		$term                     = wp_insert_term( 'PageEdit test ' . wp_generate_password( 4, false ), 'category' );
		$this->created['terms'][] = $term['term_id'];
		$this->log_in_as( 'editor' );
		$this->visit( [ 'cat' => $term['term_id'] ] );

		$url = $this->redirect_url();

		// The edit link may carry the current post type so the admin menu
		// highlights the right section; the term and taxonomy must match.
		$this->assertStringStartsWith( admin_url( 'term.php' ), $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( (string) $term['term_id'], $query['tag_ID'] );
		$this->assertSame( 'category', $query['taxonomy'] );
	}

	public function test_an_administrator_on_an_author_archive_is_sent_to_the_user_edit_screen(): void {
		$author_id                = wp_insert_user(
			[
				'user_login' => 'scanfully_pe_author_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'author',
			]
		);
		$this->created['users'][] = $author_id;
		$this->log_in_as( 'administrator' );
		$this->visit( [ 'author' => $author_id ] );

		$this->assertSame( get_edit_user_link( $author_id ), $this->redirect_url() );
	}
}
