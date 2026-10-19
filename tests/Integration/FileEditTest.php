<?php
/**
 * File editor integration tests.
 *
 * Runs against real WordPress (wp-env) and skips otherwise.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Events\Controller as EventsController;
use Scanfully\Events\FileEdits\Collector;

/**
 * Saves files through WordPress's plugin and theme file editors and checks
 * what the activity log is sent.
 */
final class FileEditTest extends TestCase {

	private const PLUGIN = 'scanfully-file-edit-test';

	private const THEME = 'scanfully-file-edit-test-theme';

	/**
	 * Event bodies sent to the API.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * The signed-in user before the test.
	 *
	 * @var int
	 */
	private int $user = 0;

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'wp_insert_post' ) ) {
			$this->markTestSkipped( 'WordPress not available. Run this suite under wp-env.' );
		}
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			$this->markTestSkipped( 'The file editors are turned off on this site.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		update_option( 'scanfully_connect_is_connected', 'yes' );
		as_unschedule_all_actions( '', [], 'scanfully' );
		Collector::reset();
		$this->sent = [];
		$this->user = get_current_user_id();
		wp_set_current_user( 1 );
		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );

		$this->write( WP_PLUGIN_DIR . '/' . self::PLUGIN . '/' . self::PLUGIN . '.php', "<?php\n/**\n * Plugin Name: Scanfully File Edit Test\n */\n" );
		$this->write( WP_PLUGIN_DIR . '/' . self::PLUGIN . '/readme.txt', "=== Scanfully File Edit Test ===\nFirst line\nSecond line\nThird line\n" );
		$this->write( get_theme_root() . '/' . self::THEME . '/style.css', "/*\nTheme Name: Scanfully File Edit Test Theme\n*/\nbody {\n\tcolor: black;\n}\n" );
		$this->write( get_theme_root() . '/' . self::THEME . '/index.php', "<?php\n" );
		wp_clean_plugins_cache( false );
		wp_clean_themes_cache( false );
	}

	protected function tearDown(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
			$this->remove_folder( WP_PLUGIN_DIR . '/' . self::PLUGIN );
			$this->remove_folder( get_theme_root() . '/' . self::THEME );
			wp_clean_plugins_cache( false );
			wp_clean_themes_cache( false );
			Collector::reset();
			as_unschedule_all_actions( '', [], 'scanfully' );
			delete_option( 'scanfully_connect_is_connected' );
			wp_set_current_user( $this->user );
		}
		parent::tearDown();
	}

	/**
	 * Write a test file.
	 *
	 * @param string $path    The file.
	 * @param string $content The content.
	 *
	 * @return void
	 */
	private function write( string $path, string $content ): void {
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- A test plugin or theme.
		}
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A test plugin or theme.
	}

	/**
	 * Remove a test plugin or theme folder.
	 *
	 * @param string $folder The folder.
	 *
	 * @return void
	 */
	private function remove_folder( string $folder ): void {
		foreach ( glob( $folder . '/*' ) ?: [] as $file ) { // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- glob() returns false on an error.
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- A test plugin or theme.
		}
		if ( is_dir( $folder ) ) {
			rmdir( $folder ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- A test plugin or theme.
		}
	}

	/**
	 * `pre_http_request`: keep the event sent to the API and accept it.
	 *
	 * @param mixed  $preempt Whether to preempt the request.
	 * @param array  $args    The request arguments.
	 * @param string $url     The URL.
	 *
	 * @return mixed
	 */
	public function capture_request( $preempt, $args, $url ) {
		if ( false === strpos( (string) $url, '/timeline' ) ) {
			return $preempt;
		}

		$this->sent[] = json_decode( (string) ( $args['body'] ?? '' ), true );

		return [
			'headers'  => [],
			'body'     => '',
			'response' => [
				'code'    => 202,
				'message' => 'Accepted',
			],
			'cookies'  => [],
		];
	}

	/**
	 * Save a file as the editor does: note it, let WordPress save it, end
	 * the request.
	 *
	 * @param array<string, string> $args The posted fields.
	 *
	 * @return true|\WP_Error What WordPress returned.
	 */
	private function save( array $args ) {
		Collector::note( $args );
		$result = wp_edit_theme_plugin_file( $args );
		Collector::flush();

		return $result;
	}

	/**
	 * Run the pending event jobs and return the events sent: type and data.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function run_jobs(): array {
		$actions = as_get_scheduled_actions(
			[
				'hook'     => EventsController::ACTION_BUILD_CONTENT_EVENT,
				'group'    => 'scanfully',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			]
		);
		foreach ( $actions as $action ) {
			do_action_ref_array( EventsController::ACTION_BUILD_CONTENT_EVENT, array_values( $action->get_args() ) );
		}

		return $this->sent;
	}

	/**
	 * The fields the plugin editor posts for a file of the test plugin.
	 *
	 * @param string $file    The file, relative to the plugin folder.
	 * @param string $content The new content.
	 *
	 * @return array<string, string>
	 */
	private function plugin_save( string $file, string $content ): array {
		$path = self::PLUGIN . '/' . $file;

		return [
			'file'       => $path,
			'plugin'     => self::PLUGIN . '/' . self::PLUGIN . '.php',
			'newcontent' => $content,
			'nonce'      => wp_create_nonce( 'edit-plugin_' . $path ),
		];
	}

	public function test_a_plugin_file_edit_is_sent_with_a_diff(): void {
		$result = $this->save( $this->plugin_save( 'readme.txt', "=== Scanfully File Edit Test ===\nFirst line\nSecond line, edited\nThird line\n" ) );
		$this->assertTrue( $result );

		$sent = $this->run_jobs();

		$this->assertCount( 1, $sent );
		$this->assertSame( 'PluginFileEdited', $sent[0]['type'] );
		$this->assertSame( 'Scanfully File Edit Test', $sent[0]['data']['name'] );
		$this->assertSame( self::PLUGIN . '/' . self::PLUGIN . '.php', $sent[0]['data']['slug'] );
		$this->assertSame( self::PLUGIN . '/readme.txt', $sent[0]['data']['file'] );
		$content = $sent[0]['data']['changes']['content'];
		$this->assertSame( 1, $content['added'] );
		$this->assertSame( 1, $content['removed'] );
		$this->assertContains(
			[
				'op'   => '-',
				'text' => 'Second line',
			],
			$content['lines']
		);
		$this->assertContains(
			[
				'op'   => '+',
				'text' => 'Second line, edited',
			],
			$content['lines']
		);
	}

	public function test_a_theme_file_edit_is_sent_with_a_diff(): void {
		$file   = 'style.css';
		$result = $this->save(
			[
				'file'       => $file,
				'theme'      => self::THEME,
				'newcontent' => "/*\nTheme Name: Scanfully File Edit Test Theme\n*/\nbody {\n\tcolor: red;\n}\n",
				'nonce'      => wp_create_nonce( 'edit-theme_' . self::THEME . '_' . $file ),
			]
		);
		$this->assertTrue( $result );

		$sent = $this->run_jobs();

		$this->assertCount( 1, $sent );
		$this->assertSame( 'ThemeFileEdited', $sent[0]['type'] );
		$this->assertSame( 'Scanfully File Edit Test Theme', $sent[0]['data']['name'] );
		$this->assertSame( self::THEME, $sent[0]['data']['slug'] );
		$this->assertSame( 'style.css', $sent[0]['data']['file'] );
		$this->assertContains(
			[
				'op'   => '+',
				'text' => "\tcolor: red;",
			],
			$sent[0]['data']['changes']['content']['lines']
		);
	}

	public function test_the_editor_ajax_save_is_followed_before_wordpress_saves(): void {
		$this->assertSame( 0, has_action( 'wp_ajax_edit-theme-plugin-file', [ Collector::class, 'note_ajax_save' ] ), 'Before the handler WordPress runs at priority 1.' );

		$args  = $this->plugin_save( 'readme.txt', "=== Scanfully File Edit Test ===\nChanged through AJAX\n" );
		$_POST = wp_slash( $args ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The request the editor sends.
		Collector::note_ajax_save();
		$_POST = [];
		$this->assertTrue( wp_edit_theme_plugin_file( $args ) );
		Collector::flush();

		$sent = $this->run_jobs();

		$this->assertCount( 1, $sent );
		$this->assertSame( 1, $sent[0]['data']['changes']['content']['added'] );
		$this->assertSame( 3, $sent[0]['data']['changes']['content']['removed'] );
	}

	public function test_a_save_wordpress_refuses_sends_nothing(): void {
		$args          = $this->plugin_save( 'readme.txt', 'Changed' );
		$args['nonce'] = 'not-a-nonce';

		$this->assertInstanceOf( \WP_Error::class, $this->save( $args ) );
		$this->assertSame( [], $this->run_jobs() );
	}

	public function test_saving_the_same_content_sends_nothing(): void {
		$this->assertTrue( $this->save( $this->plugin_save( 'readme.txt', "=== Scanfully File Edit Test ===\nFirst line\nSecond line\nThird line\n" ) ) );

		$this->assertSame( [], $this->run_jobs() );
	}

	public function test_a_user_who_may_not_edit_plugins_is_not_followed(): void {
		$subscriber = wp_insert_user(
			[
				'user_login' => 'scanfully_file_edit_subscriber',
				'user_pass'  => wp_generate_password(),
				'user_email' => 'file-edit-subscriber@scanfully.com',
				'role'       => 'subscriber',
			]
		);
		$this->assertIsInt( $subscriber );
		wp_set_current_user( $subscriber );

		Collector::note( $this->plugin_save( 'readme.txt', 'Changed' ) );
		// The file changes some other way during the request.
		$this->write( WP_PLUGIN_DIR . '/' . self::PLUGIN . '/readme.txt', 'Changed' );
		Collector::flush();

		wp_set_current_user( 1 );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $subscriber );

		$this->assertSame( [], $this->run_jobs() );
	}
}
