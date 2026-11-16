<?php
/**
 * Manage own update unit tests: the Scanfully plugin updating itself loads
 * every class first, and never updates a folder that is a symbolic link.
 *
 * @package Scanfully\Tests\Unit\Manage
 */

namespace Scanfully\Tests\Unit\Manage;

use Brain\Monkey\Functions;
use Scanfully\Manage\JobFailed;
use Scanfully\Manage\JobRun;
use Scanfully\Manage\OwnUpdate;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Manage\OwnUpdate
 * @covers \Scanfully\Manage\JobFailed
 */
final class OwnUpdateTest extends TestCase {

	/**
	 * A folder for the fixture classes and plugin folders.
	 *
	 * @var string
	 */
	private string $tmp;

	/**
	 * The autoloader of the fixture classes.
	 *
	 * @var callable|null
	 */
	private $loader = null;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp = sys_get_temp_dir() . '/scanfully-own-update-' . uniqid( '', true );
		mkdir( $this->tmp, 0777, true );
	}

	protected function tearDown(): void {
		OwnUpdate::$classes  = null;
		OwnUpdate::$scraper  = null;
		OwnUpdate::$restorer = null;
		if ( null !== $this->loader ) {
			spl_autoload_unregister( $this->loader );
		}
		$this->remove( $this->tmp );
		parent::tearDown();
	}

	/**
	 * Remove a folder and what is in it, never following a link.
	 *
	 * @param string $dir The folder.
	 */
	private function remove( string $dir ): void {
		if ( is_link( $dir ) ) {
			unlink( $dir );
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$path = $dir . '/' . $name;
				is_dir( $path ) && ! is_link( $path ) ? $this->remove( $path ) : unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Fixture classes under a namespace of their own, with an autoloader
	 * that maps it to the folder as Composer does.
	 *
	 * @param array<string, string> $files The files, by path under the folder, with their code after the namespace.
	 *
	 * @return string The namespace prefix.
	 */
	private function fixture( array $files ): string {
		$namespace = 'ScanfullyOwnUpdateFixture\\F' . bin2hex( random_bytes( 6 ) );
		$root      = $this->tmp . '/classes';
		foreach ( $files as $path => $code ) {
			if ( ! is_dir( dirname( $root . '/' . $path ) ) ) {
				mkdir( dirname( $root . '/' . $path ), 0777, true );
			}
			$sub = str_replace( '/', '\\', dirname( $path ) );
			file_put_contents( $root . '/' . $path, "<?php\nnamespace $namespace" . ( '.' === $sub ? '' : '\\' . $sub ) . ";\n$code\n" );
		}
		$prefix       = $namespace . '\\';
		$this->loader = static function ( string $name ) use ( $prefix, $root ): void {
			if ( 0 === strpos( $name, $prefix ) ) {
				$file = $root . '/' . str_replace( '\\', '/', substr( $name, strlen( $prefix ) ) ) . '.php';
				if ( is_file( $file ) ) {
					require $file;
				}
			}
		};
		spl_autoload_register( $this->loader );
		OwnUpdate::$classes = [ $root, $prefix ];

		return $prefix;
	}

	public function test_every_class_is_loaded_but_files_without_one_and_classes_whose_outside_parent_is_missing(): void {
		$prefix = $this->fixture(
			[
				'Plain.php'      => 'class Plain {}',
				'Contract.php'   => 'interface Contract {}',
				'Shared.php'     => 'trait Shared {}',
				'Deep/Inner.php' => 'final class Inner extends \\ArrayObject {}',
				'Gateway.php'    => 'class Gateway extends \\Scanfully_Missing_Outside_Gateway {}',
				'Imported.php'   => "use Scanfully_Missing\\Outside\\Base;\nuse Scanfully_Missing\\Outside\\Other as Renamed;\nclass Imported extends Base {}",
				'Aliased.php'    => "use Scanfully_Missing\\Outside\\Other as Renamed;\nclass Aliased extends Renamed {}",
				'Sibling.php'    => "use Scanfully_Missing\\Outside\\Base;\nclass Sibling extends Plain {}",
				'helpers.php'    => 'define( "SCANFULLY_OWN_UPDATE_HELPERS_RAN", true );',
				'Elsewhere.php'  => 'class SomethingElse {}',
			]
		);

		$problems = OwnUpdate::preload();

		$this->assertTrue( class_exists( $prefix . 'Plain', false ) );
		$this->assertTrue( interface_exists( $prefix . 'Contract', false ) );
		$this->assertTrue( trait_exists( $prefix . 'Shared', false ) );
		$this->assertTrue( class_exists( $prefix . 'Deep\\Inner', false ), 'a parent from outside the plugin that is there is fine' );
		$this->assertFalse( class_exists( $prefix . 'Gateway', false ), 'a class whose outside parent is missing is left alone' );
		$this->assertFalse( class_exists( $prefix . 'Imported', false ), 'also when the parent is imported with use' );
		$this->assertFalse( class_exists( $prefix . 'Aliased', false ), 'also when the import has an alias' );
		$this->assertTrue( class_exists( $prefix . 'Sibling', false ), 'a parent of its own namespace loads' );
		$this->assertFalse( defined( 'SCANFULLY_OWN_UPDATE_HELPERS_RAN' ), 'a file without a class is never included' );
		$this->assertCount( 1, $problems, 'a file that does not declare the class of its name is a problem' );
		$this->assertStringContainsString( $prefix . 'Elsewhere', $problems[0] );
	}

	public function test_classes_loaded_already_are_skipped(): void {
		$prefix = $this->fixture( [ 'Plain.php' => 'class Plain {}' ] );
		$this->assertTrue( class_exists( $prefix . 'Plain' ) );

		$this->assertSame( [], OwnUpdate::preload() );
	}

	public function test_every_class_of_the_plugin_loads(): void {
		$this->assertSame( [], OwnUpdate::preload() );
		$this->assertTrue( class_exists( \Scanfully\Manage\Updates::class, false ) );
		$this->assertTrue( class_exists( \Scanfully\Sync\Controller::class, false ) );
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			$this->assertFalse( class_exists( \Scanfully\WooCheckout\ProbeGateway::class, false ), 'needs WooCommerce' );
		}
	}

	public function test_a_folder_that_is_a_symbolic_link_is_never_updated(): void {
		mkdir( $this->tmp . '/checkout' );
		$plugins = WP_PLUGIN_DIR;
		if ( ! is_dir( $plugins ) ) {
			mkdir( $plugins, 0777, true );
		}
		$link = $plugins . '/own-update-linked-' . bin2hex( random_bytes( 4 ) );
		symlink( $this->tmp . '/checkout', $link );
		try {
			OwnUpdate::check( basename( $link ) . '/scanfully.php' );
			$this->fail( 'A linked folder was not refused.' );
		} catch ( JobFailed $e ) {
			$this->assertSame( 'not_supported', $e->error_class() );
			$this->assertStringContainsString( 'symbolic link', $e->getMessage() );
		} finally {
			unlink( $link );
		}
		$this->assertDirectoryExists( $this->tmp . '/checkout', 'the linked folder is left alone' );
	}

	public function test_a_real_folder_is_updated(): void {
		$plugins = WP_PLUGIN_DIR;
		if ( ! is_dir( $plugins ) ) {
			mkdir( $plugins, 0777, true );
		}
		$folder = $plugins . '/own-update-real-' . bin2hex( random_bytes( 4 ) );
		mkdir( $folder );
		try {
			OwnUpdate::check( basename( $folder ) . '/scanfully.php' );
			$this->addToAssertionCount( 1 );
		} finally {
			rmdir( $folder );
		}
	}

	/**
	 * The site answers the check with a body, given the scrape key the
	 * request carries.
	 *
	 * @param callable $body Takes the key, gives the body; or a WP_Error.
	 */
	private function site_answers( $body ): void {
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://scanfully.com/' );
		Functions\when( 'add_query_arg' )->alias( static fn( array $query, string $url ): string => $url . '?' . http_build_query( $query ) );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ): bool => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( array $response ): string => $response['body'] );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $response ): int => $response['code'] );
		Functions\when( 'wp_remote_get' )->alias(
			static function ( string $url ) use ( $body ) {
				parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
				$answer = $body( (string) $query['wp_scrape_key'] );
				return $answer instanceof \WP_Error ? $answer : [
					'code' => 200,
					'body' => $answer,
				];
			}
		);
	}

	public function test_the_check_reads_wordpress_s_scrape_result(): void {
		$this->site_answers( static fn( string $key ): string => "<html>\n###### wp_scraping_result_start:$key ######\ntrue\n###### wp_scraping_result_end:$key ######\n" );
		$this->assertSame( [ 'state' => 'ok' ], OwnUpdate::scrape() );

		$this->site_answers(
			static fn( string $key ): string => "###### wp_scraping_result_start:$key ######\n" . json_encode(
				[
					'type' => E_ERROR,
					'message' => 'Uncaught Error: boom',
					'file' => 'wp-content/plugins/scanfully/src/Main.php',
					'line' => 12,
				]
			) . "\n###### wp_scraping_result_end:$key ######"
		);
		$this->assertSame(
			[
				'state' => 'fatal',
				'error' => [
					'message' => 'Uncaught Error: boom',
					'file'    => 'wp-content/plugins/scanfully/src/Main.php',
					'line'    => 12,
				],
			],
			OwnUpdate::scrape()
		);

		$this->site_answers( static fn(): string => '<html>a cached page</html>' );
		$this->assertSame( 'unknown', OwnUpdate::scrape()['state'], 'a page without the check, as from a page cache' );

		$this->site_answers( static fn() => new \WP_Error( 'http_request_failed', 'cURL error 7' ) );
		$this->assertSame(
			[
				'state'  => 'unknown',
				'reason' => 'the request to the site failed: cURL error 7',
			],
			OwnUpdate::scrape()
		);
	}

	/**
	 * Checks answer in turn.
	 *
	 * @param array ...$answers The answers, as scrape() gives them.
	 */
	private function checks( array ...$answers ): void {
		OwnUpdate::$scraper = static function () use ( &$answers ): array {
			return array_shift( $answers ) ?? [ 'state' => 'ok' ];
		};
	}

	private function job_run(): JobRun {
		return new JobRun( 'a1a1a1a1-1111-4111-8111-111111111111', null, $this->tmp . '/.maintenance' );
	}

	private const FATAL = [
		'state' => 'fatal',
		'error' => [
			'message' => 'Uncaught Error: Class "Scanfully\\Gone" not found',
			'file'    => 'wp-content/plugins/scanfully/src/Main.php',
			'line'    => 40,
		],
	];

	public function test_a_new_version_that_loads_stands(): void {
		$this->checks( [ 'state' => 'ok' ] );
		OwnUpdate::$restorer = function (): ?string {
			$this->fail( 'Nothing to put back.' );
		};
		$run = $this->job_run();

		$this->assertNull( OwnUpdate::check_after( [ 'state' => 'ok' ], 'scanfully/scanfully.php', '1.10.0', '1.12.0', $run ) );
		$this->assertSame( [ 'The site loads with the new version of Scanfully.' ], $run->log_lines() );
	}

	public function test_a_site_that_cannot_be_checked_keeps_the_new_version(): void {
		OwnUpdate::$restorer = function (): ?string {
			$this->fail( 'Nothing is known: nothing changes.' );
		};

		$this->checks( [ 'state' => 'fatal' ] );
		$run = $this->job_run();
		$this->assertNull( OwnUpdate::check_after( self::FATAL, 'scanfully/scanfully.php', '1.10.0', '1.12.0', $run ), 'broken before the update too' );
		$this->assertStringContainsString( 'before the update too', $run->log_lines()[0] );

		$run = $this->job_run();
		$this->assertNull(
			OwnUpdate::check_after(
				[
					'state'  => 'unknown',
					'reason' => 'the request to the site failed: cURL error 7',
				],
				'scanfully/scanfully.php',
				'1.10.0',
				'1.12.0',
				$run
			)
		);
		$this->assertStringContainsString( 'could not check it before the update (the request to the site failed: cURL error 7)', $run->log_lines()[0] );

		$cached = [
			'state'  => 'unknown',
			'code'   => 200,
			'reason' => 'the site answered 200 without WordPress\'s check, for example from a page cache',
		];
		$this->checks( $cached, $cached );
		$run = $this->job_run();
		$this->assertNull( OwnUpdate::check_after( [ 'state' => 'ok' ], 'scanfully/scanfully.php', '1.10.0', '1.12.0', $run ) );
		$this->assertStringContainsString( 'could not check the site with the new version', $run->log_lines()[0] );
	}

	public function test_a_check_without_an_answer_is_tried_once_more(): void {
		$this->checks(
			[
				'state'  => 'unknown',
				'code'   => 500,
				'reason' => 'the site answered 500 without WordPress\'s check, for example from a page cache',
			],
			[ 'state' => 'ok' ]
		);
		OwnUpdate::$restorer = function (): ?string {
			$this->fail( 'The second check passed.' );
		};
		$run = $this->job_run();

		$this->assertNull( OwnUpdate::check_after( [ 'state' => 'ok' ], 'scanfully/scanfully.php', '1.10.0', '1.12.0', $run ) );
		$this->assertSame( [ 'The site loads with the new version of Scanfully.' ], $run->log_lines() );
	}

	public function test_a_server_error_on_every_page_load_is_rolled_back(): void {
		$error = [
			'state'  => 'unknown',
			'code'   => 500,
			'reason' => 'the site answered 500 without WordPress\'s check, for example from a page cache',
		];
		$this->checks( $error, $error, [ 'state' => 'ok' ] );
		$restored            = 0;
		OwnUpdate::$restorer = static function () use ( &$restored ): ?string {
			++$restored;
			return null;
		};

		$crash = OwnUpdate::check_after( [ 'state' => 'ok' ], 'scanfully/scanfully.php', '1.10.0', '1.12.0', $this->job_run() );

		$this->assertSame( 1, $restored );
		$this->assertTrue( $crash['settled'] );
		$this->assertSame( 'With version 1.12.0 of the Scanfully plugin the site answered 500 (a server error) to every page load, where it loaded before the update. Scanfully put version 1.10.0 back. The site loads again.', $crash['message'] );
	}

	public function test_a_new_version_that_stops_with_a_php_error_is_rolled_back(): void {
		$this->checks( self::FATAL, [ 'state' => 'ok' ] );
		$restored            = [];
		OwnUpdate::$restorer = static function ( string $file ) use ( &$restored ): ?string {
			$restored[] = $file;
			return null;
		};

		$crash = OwnUpdate::check_after( [ 'state' => 'ok' ], 'scanfully/scanfully.php', '1.10.0', '1.12.0', $this->job_run() );

		$this->assertSame( [ 'scanfully/scanfully.php' ], $restored );
		$this->assertSame( 'fatal', $crash['class'] );
		$this->assertTrue( $crash['settled'] );
		$this->assertSame( 'Version 1.12.0 of the Scanfully plugin stopped with a PHP error when the site loaded it: Uncaught Error: Class "Scanfully\\Gone" not found (in wp-content/plugins/scanfully/src/Main.php:40). Scanfully put version 1.10.0 back. The site loads again.', $crash['message'] );
	}

	public function test_a_rollback_that_fails_leaves_it_to_the_guard(): void {
		$this->checks( self::FATAL );
		OwnUpdate::$restorer = static fn(): string => 'WordPress\'s backup of it is not there';

		$crash = OwnUpdate::check_after( [ 'state' => 'ok' ], 'scanfully/scanfully.php', '1.10.0', '1.12.0', $this->job_run() );

		$this->assertFalse( $crash['settled'] );
		$this->assertStringContainsString( 'Scanfully could not put version 1.10.0 back (WordPress\'s backup of it is not there); its update guard tries again', $crash['message'] );
	}

	public function test_a_site_still_broken_after_the_rollback_is_not_settled(): void {
		$this->checks( self::FATAL, self::FATAL );
		OwnUpdate::$restorer = static fn(): ?string => null;

		$crash = OwnUpdate::check_after( [ 'state' => 'ok' ], 'scanfully/scanfully.php', '1.10.0', '1.12.0', $this->job_run() );

		$this->assertFalse( $crash['settled'] );
		$this->assertStringContainsString( 'Scanfully put version 1.10.0 back. The site still stops with a PHP error: Uncaught Error', $crash['message'] );
	}
}
