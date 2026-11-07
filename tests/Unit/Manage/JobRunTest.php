<?php
/**
 * Manage job run unit tests: the site is left clean whatever happens, and
 * the update events a job causes carry its id.
 *
 * @package Scanfully\Tests\Unit\Manage
 */

namespace Scanfully\Tests\Unit\Manage;

use Brain\Monkey\Functions;
use Scanfully\Manage\Failure;
use Scanfully\Manage\JobRun;
use Scanfully\Manage\RunLock;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Manage\JobRun
 * @covers \Scanfully\Manage\Failure
 * @covers \Scanfully\Manage\RunLock
 */
final class JobRunTest extends TestCase {

	private const JOB = 'a1a1a1a1-1111-4111-8111-111111111111';

	/**
	 * A folder standing in for the WordPress folder.
	 *
	 * @var string
	 */
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root      = sys_get_temp_dir() . '/scanfully-jobrun-' . uniqid( '', true );
		$GLOBALS['wpdb'] = new FakeWpdb();
		mkdir( $this->root );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_delete_file' )->alias(
			static function ( string $file ): void {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
		);
		Functions\when( 'wp_generate_uuid4' )->alias( fn() => 'e1e1e1e1-1111-4111-8111-' . bin2hex( random_bytes( 6 ) ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$maintenance = $this->root . '/.maintenance';
		if ( is_dir( $maintenance ) ) {
			rmdir( $maintenance );
		} elseif ( is_file( $maintenance ) ) {
			unlink( $maintenance );
		}
		rmdir( $this->root );
		parent::tearDown();
	}

	private function run_for( ?callable $on_stop = null ): JobRun {
		return new JobRun( self::JOB, $on_stop, $this->root . '/.maintenance' );
	}

	public function test_a_maintenance_file_the_job_caused_is_removed(): void {
		$run = $this->run_for();
		file_put_contents( $this->root . '/.maintenance', '<?php $upgrading = 1;' );

		$this->assertNull( $run->clean() );
		$this->assertFileDoesNotExist( $this->root . '/.maintenance' );
		$this->assertStringContainsString( 'removed the maintenance file', implode( ' ', $run->log_lines() ) );
	}

	public function test_a_maintenance_file_that_was_there_before_is_left(): void {
		file_put_contents( $this->root . '/.maintenance', '<?php $upgrading = 1;' );
		$run = $this->run_for();

		$this->assertNull( $run->clean() );
		$this->assertFileExists( $this->root . '/.maintenance', 'someone else\'s maintenance' );
	}

	public function test_a_maintenance_file_that_cannot_be_removed_is_the_problem_reported(): void {
		$run = $this->run_for();
		mkdir( $this->root . '/.maintenance' ); // Exists, and no file delete removes it.

		$problem = $run->clean();

		$this->assertSame( 'maintenance', $problem['class'] ?? '' );
		$this->assertNull( $run->clean(), 'the clean-up runs once' );
	}

	public function test_clean_up_tasks_run_once_and_the_first_problem_is_reported(): void {
		$run   = $this->run_for();
		$calls = 0;
		$run->on_exit(
			function () use ( &$calls ): array {
				++$calls;
				return [
					'class'   => 'fatal',
					'message' => 'The plugin could not be switched on again.',
				];
			}
		);
		$run->on_exit(
			static function (): void {
				throw new \RuntimeException( 'second task' );
			}
		);

		$this->assertSame( 'fatal', $run->clean()['class'] ?? '' );
		$run->release();
		$this->assertSame( 1, $calls );
	}

	public function test_php_stopping_mid_job_cleans_up_reports_and_releases_the_lock(): void {
		$stopped = [];
		$run     = $this->run_for(
			function ( string $class, string $message ) use ( &$stopped ): void {
				$stopped = [ $class, $message ];
			}
		);
		RunLock::take( self::JOB, time() + 600, time() );
		$switched_on = false;
		$run->on_exit(
			function () use ( &$switched_on ): ?array {
				$switched_on = true;
				return null;
			}
		);
		file_put_contents( $this->root . '/.maintenance', '<?php $upgrading = 1;' );

		$run->shutdown();

		$this->assertSame( 'fatal', $stopped[0] ?? '' );
		$this->assertStringContainsString( 'PHP stopped while the job ran', $stopped[1] ?? '' );
		$this->assertTrue( $switched_on, 'the plugin the upgrader switched off is switched on again' );
		$this->assertFileDoesNotExist( $this->root . '/.maintenance' );
		$this->assertNull( RunLock::holder( time() ), 'the lock is released' );

		$stopped = [];
		$run->shutdown();
		$this->assertSame( [], $stopped, 'a released run does nothing on shutdown' );
	}

	public function test_a_run_that_ended_does_nothing_on_shutdown(): void {
		$called = false;
		$run    = $this->run_for(
			function () use ( &$called ): void {
				$called = true;
			}
		);
		$run->release();
		$run->shutdown();

		$this->assertFalse( $called );
	}

	public function test_the_update_events_the_job_causes_carry_its_id(): void {
		$run = $this->run_for();

		$tagged = $run->tag_event( [ 'slug' => 'akismet/akismet.php' ], 'PluginUpdate' );
		$this->assertSame( self::JOB, $tagged['manage_job_id'] );
		$this->assertSame( [ $tagged['activity_id'] ], $run->activity_ids() );

		$this->assertSame( [ 'title' => 'Hello' ], $run->tag_event( [ 'title' => 'Hello' ], 'PostSaved' ), 'other events stay as they are' );
		$failed = $run->tag_event( [ 'slug' => 'twentytwentyfour' ], 'ThemeUpdateFailed' );
		$this->assertSame( self::JOB, $failed['manage_job_id'] );
		$this->assertCount( 2, $run->activity_ids() );

		$run->release();
		$this->assertSame( [ 'version' => '6.9.3' ], $run->tag_event( [ 'version' => '6.9.3' ], 'CoreUpdate' ), 'nothing after the run' );
	}

	/**
	 * @dataProvider provide_wordpress_errors
	 *
	 * @param string $code    The WP_Error code.
	 * @param string $message Its message and detail.
	 * @param string $class   The error class.
	 */
	public function test_wordpress_errors_map_to_the_api_classes( string $code, string $message, string $class ): void {
		$this->assertSame( $class, Failure::class_of( $code, $message ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function provide_wordpress_errors(): array {
		return [
			'credentials needed'    => [ 'fs_unavailable', 'Could not access filesystem.', 'filesystem' ],
			'no plugins folder'     => [ 'fs_no_plugins_dir', 'Unable to locate WordPress plugin directory.', 'filesystem' ],
			'copy failed'           => [ 'copy_failed_copy_dir', 'Could not copy file.', 'filesystem' ],
			'disk full'             => [ 'disk_full_unzip_file', 'Could not copy files. You may have run out of disk space.', 'filesystem' ],
			'mkdir'                 => [ 'mkdir_failed_ziparchive', 'Could not create directory.', 'filesystem' ],
			'not found'             => [ 'download_failed', 'Download failed. Not Found', 'package' ],
			'license'               => [ 'download_failed', 'Download failed. Unauthorized', 'license' ],
			'forbidden'             => [ 'download_failed', 'Download failed. Forbidden', 'license' ],
			'no package'            => [ 'no_package', 'Update package not available.', 'license' ],
			'timeout'               => [ 'download_failed', 'Download failed. cURL error 28: Operation timed out after 300000 milliseconds', 'timeout' ],
			'bad zip'               => [ 'incompatible_archive', 'The package could not be installed. PCLZIP_ERR_BAD_FORMAT', 'package' ],
			'no plugin in the zip'  => [ 'incompatible_archive_no_plugins', 'No valid plugins were found.', 'package' ],
			'requires php'          => [ 'incompatible_php_required_version', 'The PHP version on your server is 7.4, however the uploaded plugin requires 8.1.', 'not_supported' ],
			'core php'              => [ 'php_not_compatible', 'The update cannot be installed because WordPress 7.1 requires PHP version 7.4 or higher.', 'not_supported' ],
			'core lock'             => [ 'locked', 'Another update is currently in progress.', 'locked' ],
			'up to date'            => [ 'up_to_date', 'The plugin is at the latest version.', 'not_available' ],
			'anything else'         => [ 'something_new', 'Something new went wrong.', 'other' ],
		];
	}

	public function test_text_posted_loses_url_secrets_and_server_paths(): void {
		$clean = Failure::clean( 'Downloading update from <span class="code">https://user:pass@updates.scanfully.com/download/pro.zip?license_key=SECRET&site=x#frag</span>&#8230; ' . ABSPATH . 'wp-content/upgrade/pro.tmp' );

		$this->assertStringNotContainsString( 'SECRET', $clean );
		$this->assertStringNotContainsString( 'pass', $clean );
		$this->assertStringNotContainsString( 'frag', $clean );
		$this->assertStringNotContainsString( ABSPATH, $clean );
		$this->assertStringContainsString( 'https://updates.scanfully.com/download/pro.zip', $clean );
		$this->assertStringContainsString( './wp-content/upgrade/pro.tmp', $clean );
	}

	public function test_the_log_keeps_the_last_lines_cleaned_and_cut(): void {
		$lines = [ 'Downloading update from https://downloads.wordpress.org/plugin/akismet.5.3.1.zip?key=abc&#8230;', '', 42, str_repeat( 'y', 500 ) ];

		$log = Failure::log( $lines );

		$this->assertSame( 'Downloading update from https://downloads.wordpress.org/plugin/akismet.5.3.1.zip…', $log[0], 'the query string goes, the ellipsis after it stays' );
		$this->assertSame( 'from https://downloads.wordpress.org/plugin/classic-editor.1.7.0.zip… done', Failure::clean( 'from https://downloads.wordpress.org/plugin/classic-editor.1.7.0.zip&#8230; done' ), 'an ellipsis right after a URL is not part of it' );
		$this->assertCount( 2, $log );
		$this->assertSame( 300, mb_strlen( $log[1] ) );
		$this->assertCount( 3, Failure::log( array_fill( 0, 10, 'line' ), 3 ) );
	}
}
