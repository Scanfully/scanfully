<?php
/**
 * PHP error monitoring integration tests.
 *
 * Runs against real WordPress (wp-env) and skips otherwise. Errors that end
 * a request are raised in a child PHP process that loads the same WordPress
 * with a throwaway plugin, so the test run itself survives them.
 *
 * @package Scanfully\Tests\Integration
 */

namespace Scanfully\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Scanfully\Errors\Context;
use Scanfully\Errors\Monitor;
use Scanfully\Errors\MuPlugin;
use Scanfully\Errors\Reporter;
use Scanfully\Errors\Store;
use Scanfully\Health\MuPlugins;

/**
 * Catches real PHP errors and checks what is stored and sent.
 */
final class PhpErrorsTest extends TestCase {

	private const PLUGIN = 'scanfully-test-errors';

	private const CATCHER = 'scanfully-test-catch.php';

	/**
	 * Bodies sent to the errors endpoint by this process.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * The INSERT queries on the store run by this process.
	 *
	 * @var string[]
	 */
	private array $inserts = [];

	/**
	 * The active plugins before the test.
	 *
	 * @var array<int, string>
	 */
	private array $active = [];

	/**
	 * Whether the must-use plugins folder existed before the test.
	 *
	 * @var bool
	 */
	private bool $had_mu_dir = true;

	/**
	 * Where the child process writes what it sent.
	 *
	 * @var string
	 */
	private string $log = '';

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			$this->markTestSkipped( 'WordPress not available. Run this suite under wp-env.' );
		}

		$this->active     = (array) get_option( 'active_plugins', [] );
		$this->had_mu_dir = is_dir( WPMU_PLUGIN_DIR );
		if ( ! $this->had_mu_dir ) {
			mkdir( WPMU_PLUGIN_DIR, 0777, true );
		}
		$this->log = sys_get_temp_dir() . '/scanfully-errors-sent-' . getmypid() . '.jsonl';

		update_option( 'scanfully_connect_is_connected', 'yes' );
		update_option( 'scanfully_connect_site_id', 'site-errors-test' );
		update_option( 'scanfully_connect_access_token', 'token-errors-test', false );
		delete_option( Monitor::ENABLED_OPTION );
		Store::delete_all();
		Monitor::reset();
		as_unschedule_all_actions( '', [], 'scanfully' );
		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );
		add_filter( 'query', [ $this, 'capture_query' ] );
	}

	protected function tearDown(): void {
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
			remove_filter( 'query', [ $this, 'capture_query' ] );
			Monitor::reset();
			MuPlugin::remove();
			foreach ( [ WPMU_PLUGIN_DIR . '/' . self::CATCHER, WP_PLUGIN_DIR . '/' . self::PLUGIN . '/' . self::PLUGIN . '.php', WPMU_PLUGIN_DIR . '/' . MuPlugin::FILE, $this->log, ABSPATH . '.maintenance' ] as $file ) {
				if ( file_exists( $file ) ) {
					unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
				}
			}
			if ( is_dir( WP_PLUGIN_DIR . '/' . self::PLUGIN ) ) {
				rmdir( WP_PLUGIN_DIR . '/' . self::PLUGIN );
			}
			if ( ! $this->had_mu_dir && is_dir( WPMU_PLUGIN_DIR ) && [ '.', '..' ] === scandir( WPMU_PLUGIN_DIR ) ) {
				rmdir( WPMU_PLUGIN_DIR );
			}
			update_option( 'active_plugins', $this->active );
			Store::delete_all();
			as_unschedule_all_actions( '', [], 'scanfully' );
			delete_option( Monitor::ENABLED_OPTION );
			delete_option( 'scanfully_connect_is_connected' );
			delete_option( 'scanfully_connect_site_id' );
			delete_option( 'scanfully_connect_access_token' );
		}
		parent::tearDown();
	}

	/**
	 * `pre_http_request`: keep what is sent to the errors endpoint and accept it.
	 *
	 * @param mixed  $preempt Whether to preempt the request.
	 * @param array  $args    The request arguments.
	 * @param string $url     The URL.
	 *
	 * @return mixed
	 */
	public function capture_request( $preempt, $args, $url ) {
		if ( false === strpos( (string) $url, '/sites/site-errors-test/errors' ) ) {
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
	 * `query`: keep the writes to the store.
	 *
	 * @param string $query The query.
	 *
	 * @return string
	 */
	public function capture_query( $query ) {
		if ( 0 === strpos( (string) $query, 'INSERT INTO ' . $GLOBALS['wpdb']->options ) && false !== strpos( (string) $query, Store::PREFIX ) ) {
			$this->inserts[] = (string) $query;
		}

		return $query;
	}

	/**
	 * The plugin folder name of the Scanfully plugin under test.
	 *
	 * @return string
	 */
	private function scanfully_basename(): string {
		return MuPlugin::basename();
	}

	public function test_warnings_are_stored_with_one_query_and_sent_by_the_job(): void {
		$file = WP_PLUGIN_DIR . '/' . self::PLUGIN . '/shop.php';
		Monitor::handle( E_WARNING, 'Undefined array key "total" for jane@scanfully.com', $file, 10 );
		Monitor::handle( E_WARNING, 'Undefined array key "tax" for bob@scanfully.com', $file, 10 );
		Monitor::handle( E_USER_WARNING, 'Cache miss for order 12', $file, 20 );
		Monitor::flush();

		$this->assertCount( 1, $this->inserts, 'One write per request.' );
		$stored = Store::read()['errors'];
		$this->assertCount( 2, $stored );
		$counts = array_column( $stored, 'count' );
		sort( $counts );
		$this->assertSame( [ 1, 2 ], $counts );
		$this->assertSame( [], $this->sent, 'Warnings wait for the job.' );

		$next = as_next_scheduled_action( Reporter::ACTION, [ 'trigger' => Reporter::TRIGGER_NEW ], 'scanfully' );
		$this->assertIsInt( $next );
		$this->assertLessThanOrEqual( time() + MINUTE_IN_SECONDS, $next );

		Reporter::send();

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 0, $this->sent[0]['overflow'] );
		$this->assertCount( 2, $this->sent[0]['errors'] );
		$by_count = array_column( $this->sent[0]['errors'], null, 'count' );
		$this->assertSame( 'Undefined array key "total" for [email]', $by_count[2]['message'] );
		$this->assertSame( 'warning', $by_count[2]['level'] );
		$this->assertSame( 'wp-content/plugins/' . self::PLUGIN . '/shop.php', $by_count[2]['file'] );
		$this->assertSame(
			[
				'kind' => 'plugin',
				'slug' => self::PLUGIN,
				'name' => '',
				'version' => '',
			],
			$by_count[2]['source']
		);
		$this->assertSame( 'cli', $by_count[2]['context']['request'] );
		$this->assertSame( $GLOBALS['wp_version'], $by_count[2]['context']['wp'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $by_count[2]['first_seen'] );
		$this->assertSame( [], Store::read()['errors'], 'Sent counts are taken off.' );

		// The same warning again: known, so no new send is planned.
		as_unschedule_all_actions( '', [], 'scanfully' );
		Monitor::handle( E_WARNING, 'Undefined array key "x" for ann@scanfully.com', $file, 10 );
		Monitor::flush();
		$this->assertFalse( as_next_scheduled_action( Reporter::ACTION, [ 'trigger' => Reporter::TRIGGER_NEW ], 'scanfully' ) );
		$this->assertSame( [ 1 ], array_column( Store::read()['errors'], 'count' ) );
	}

	public function test_new_errors_over_the_hourly_cap_are_counted_as_overflow(): void {
		Store::add( [], Store::hour(), Store::MAX_NEW_PER_HOUR );

		Monitor::handle( E_WARNING, 'One too many', WP_PLUGIN_DIR . '/' . self::PLUGIN . '/a.php', 1 );
		Monitor::flush();

		$stored = Store::read();
		$this->assertSame( [], $stored['errors'] );
		$this->assertSame( [ Store::hour() => 1 ], $stored['overflow'] );

		Reporter::send();
		$this->assertSame(
			[
				'errors' => [],
				'overflow' => 1,
			],
			$this->sent[0]
		);
		$this->assertSame( [], Store::read()['overflow'] );
	}

	public function test_a_fatal_error_is_sent_straight_away_once_per_ten_minutes(): void {
		$file    = WP_PLUGIN_DIR . '/' . self::PLUGIN . '/boot.php';
		$message = 'Uncaught Error: Call to undefined function shop_missing() in ' . $file . ":4\nStack trace:\n#0 " . ABSPATH . "wp-settings.php(545): include_once()\n#1 {main}\n  thrown";
		Monitor::record( E_ERROR, $message, $file, 4 );
		Monitor::flush();

		$this->assertCount( 1, $this->sent );
		$error = $this->sent[0]['errors'][0];
		$this->assertSame( 'fatal', $error['level'] );
		$this->assertSame( 'Uncaught Error: Call to undefined function shop_missing()', $error['message'] );
		$this->assertSame(
			[
				[
					'file' => 'wp-settings.php',
					'line' => 545,
					'function' => 'include_once',
				],
			],
			$error['trace']
		);
		$this->assertSame( [], Store::read()['errors'], 'Sent straight away, so not stored.' );

		// Again within ten minutes: stored for the job instead.
		Monitor::record( E_ERROR, $message, $file, 4 );
		Monitor::flush();
		$this->assertCount( 1, $this->sent );
		$this->assertSame( [ 1 ], array_column( Store::read()['errors'], 'count' ) );
	}

	public function test_errors_during_maintenance_are_marked_not_dropped(): void {
		file_put_contents( ABSPATH . '.maintenance', '<?php $upgrading = ' . time() . ';' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$this->assertTrue( Context::current()['maintenance'] );
		Monitor::handle( E_WARNING, 'During the update', WP_PLUGIN_DIR . '/' . self::PLUGIN . '/a.php', 1 );
		Monitor::flush();
		Reporter::send();

		$this->assertTrue( $this->sent[0]['errors'][0]['context']['maintenance'] );
	}

	public function test_switched_off_nothing_is_kept_or_sent(): void {
		update_option( Monitor::ENABLED_OPTION, 'no', true );
		Store::add( [], Store::hour(), 0, 3 );

		$this->assertFalse( Monitor::should_run() );
		Reporter::send();

		$this->assertSame( [], $this->sent );
		$this->assertSame( [], Store::read()['overflow'], 'Turning it off removes what was waiting.' );
	}

	public function test_the_must_use_plugin_is_installed_known_and_removed(): void {
		$this->assertTrue( MuPlugin::install() );
		$this->assertTrue( MuPlugin::is_current() );
		$this->assertStringContainsString( "'" . $this->scanfully_basename() . "'", (string) file_get_contents( MuPlugin::path() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.

		$entries = array_column( MuPlugins::scan(), null, 'file' );
		$this->assertSame( 'Scanfully', $entries[ MuPlugin::FILE ]['known'] ?? null );
		$this->assertSame( 'Scanfully PHP error monitor', $entries[ MuPlugin::FILE ]['name'] );

		MuPlugin::remove();
		$this->assertFileDoesNotExist( MuPlugin::path() );
	}

	public function test_a_file_of_the_same_name_that_is_not_ours_is_left_alone(): void {
		file_put_contents( MuPlugin::path(), "<?php\n// Plugin Name: Something else\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$this->assertFalse( MuPlugin::install() );
		MuPlugin::remove();
		$this->assertFileExists( MuPlugin::path() );
	}

	public function test_the_must_use_plugin_is_not_installed_while_switched_off(): void {
		update_option( Monitor::ENABLED_OPTION, 'no', true );

		$this->assertFalse( MuPlugin::install() );
		$this->assertFileDoesNotExist( MuPlugin::path() );
	}

	public function test_a_read_only_must_use_plugins_folder_is_not_written(): void {
		chmod( WPMU_PLUGIN_DIR, 0555 );
		clearstatcache();
		if ( is_writable( WPMU_PLUGIN_DIR ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Test guard.
			chmod( WPMU_PLUGIN_DIR, 0777 );
			$this->markTestSkipped( 'Folder permissions are not enforced for this user (root).' );
		}
		try {
			$this->assertFalse( MuPlugin::can_install() );
			$this->assertFalse( MuPlugin::install() );
		} finally {
			chmod( WPMU_PLUGIN_DIR, 0777 );
		}
		$this->assertFileDoesNotExist( MuPlugin::path() );
	}

	/**
	 * Write the throwaway plugin, which raises the error a child process
	 * asks for, and the must-use plugin that catches what the child sends.
	 *
	 * @param bool $first Whether the throwaway plugin loads before Scanfully.
	 *
	 * @return void
	 */
	private function install_fixtures( bool $first ): void {
		mkdir( WP_PLUGIN_DIR . '/' . self::PLUGIN, 0777, true );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixtures.
		file_put_contents(
			WP_PLUGIN_DIR . '/' . self::PLUGIN . '/' . self::PLUGIN . '.php',
			<<<'PHP'
<?php
/**
 * Plugin Name: Scanfully test errors
 * Version: 2.3.4
 */
function scanfully_test_errors_warn() {
	return file_get_contents( '/nonexistent/scanfully-missing.txt' );
}
switch ( getenv( 'SCANFULLY_TEST_ERROR' ) ) {
	case 'load':
		scanfully_test_errors_missing_function();
		break;
	case 'init':
		add_action( 'init', static function () { throw new RuntimeException( 'Broken after plugins_loaded for bob@scanfully.com' ); } );
		break;
	case 'warning':
		add_action( 'init', 'scanfully_test_errors_warn' );
		break;
}
PHP
		);
		file_put_contents(
			WPMU_PLUGIN_DIR . '/' . self::CATCHER,
			'<?php
add_filter( "pre_http_request", static function ( $preempt, $args, $url ) {
	if ( false !== strpos( $url, "/errors" ) ) {
		file_put_contents( ' . var_export( $this->log, true ) . ', wp_json_encode( [ "url" => $url, "timeout" => $args["timeout"], "body" => json_decode( $args["body"], true ) ] ) . "\n", FILE_APPEND );
		return [ "headers" => [], "body" => "", "response" => [ "code" => 202, "message" => "Accepted" ], "cookies" => [] ];
	}
	return $preempt;
}, 10, 3 );
'
		);
		// phpcs:enable
		$fixture = self::PLUGIN . '/' . self::PLUGIN . '.php';
		$active  = array_values( array_diff( $this->active, [ $fixture ] ) );
		update_option( 'active_plugins', $first ? array_merge( [ $fixture ], $active ) : array_merge( $active, [ $fixture ] ) );
	}

	/**
	 * Load WordPress in a child PHP process that raises an error.
	 *
	 * @param string $error `load`, `init` or `warning`.
	 *
	 * @return array<int, array<string, mixed>> What the child sent to the errors endpoint.
	 */
	private function run_child( string $error ): array {
		$script = sys_get_temp_dir() . '/scanfully-errors-child-' . getmypid() . '.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $script, '<?php $_SERVER["HTTP_HOST"] = "localhost"; $_SERVER["REQUEST_URI"] = "/shop/?coupon=x"; require ' . var_export( ABSPATH . 'wp-load.php', true ) . ';' );
		$command = 'SCANFULLY_TEST_ERROR=' . escapeshellarg( $error ) . ' ' . escapeshellarg( PHP_BINARY ) . ' -d display_errors=0 -d log_errors=0 ' . escapeshellarg( $script ) . ' 2>&1';
		exec( $command ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Runs WordPress in a child process that is allowed to fail.
		unlink( $script ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.

		$sent = [];
		if ( file_exists( $this->log ) ) {
			foreach ( file( $this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
				$sent[] = json_decode( $line, true );
			}
		}

		return $sent;
	}

	public function test_the_must_use_plugin_catches_a_fatal_error_while_a_plugin_loads(): void {
		$this->install_fixtures( true );
		$this->assertTrue( MuPlugin::install() );

		$sent = $this->run_child( 'load' );

		$this->assertCount( 1, $sent, 'The fatal error is sent straight from the shutdown function.' );
		$this->assertSame( Monitor::DIRECT_TIMEOUT, $sent[0]['timeout'] );
		$error = $sent[0]['body']['errors'][0];
		$this->assertSame( 'fatal', $error['level'] );
		$this->assertSame( 'Uncaught Error: Call to undefined function scanfully_test_errors_missing_function()', $error['message'] );
		$this->assertSame(
			[
				'kind' => 'plugin',
				'slug' => self::PLUGIN,
				'name' => 'Scanfully test errors',
				'version' => '2.3.4',
			],
			$error['source']
		);
		$this->assertSame( 'wp-content/plugins/' . self::PLUGIN . '/' . self::PLUGIN . '.php', $error['file'] );
		$this->assertSame( '/shop/', $error['context']['path'] );
		$this->assertSame( 1, $error['count'] );
	}

	public function test_without_the_must_use_plugin_a_fatal_error_while_an_earlier_plugin_loads_is_missed(): void {
		$this->install_fixtures( true );

		$this->assertSame( [], $this->run_child( 'load' ), 'Coverage is partial without the must-use plugin.' );
	}

	public function test_without_the_must_use_plugin_a_fatal_error_after_plugins_loaded_is_caught(): void {
		$this->install_fixtures( true );

		$sent = $this->run_child( 'init' );

		$this->assertCount( 1, $sent );
		$error = $sent[0]['body']['errors'][0];
		$this->assertSame( 'Uncaught RuntimeException: Broken after plugins_loaded for [email]', $error['message'] );
		$this->assertSame( self::PLUGIN, $error['source']['slug'] );
		$this->assertSame( '{closure}', $error['trace'][0]['function'] );
	}

	public function test_a_warning_in_a_child_request_is_stored_for_the_job(): void {
		$this->install_fixtures( false );
		$this->assertTrue( MuPlugin::install() );

		$this->assertSame( [], $this->run_child( 'warning' ) );

		$stored = array_values( Store::read()['errors'] );
		$this->assertCount( 1, $stored );
		$this->assertStringStartsWith( 'file_get_contents(…/scanfully-missing.txt): ', $stored[0]['sample']['message'] );
		$this->assertSame( 'scanfully_test_errors_warn', $stored[0]['sample']['function'] );
		$this->assertSame( '2.3.4', $stored[0]['sample']['source']['version'] );
	}
}
