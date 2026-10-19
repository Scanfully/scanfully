<?php
/**
 * Failed login counter unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\FailedLogins
 */

namespace Scanfully\Tests\Unit\Events\FailedLogins;

use Brain\Monkey\Functions;
use Mockery;
use Scanfully\Events\FailedLogins\Counter;
use Scanfully\Events\FailedLogins\Store;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\FailedLogins\Counter
 * @covers \Scanfully\Events\FailedLogins\Store
 */
final class CounterTest extends TestCase {

	/**
	 * The counters.
	 *
	 * @var FakeCounterTable
	 */
	private FakeCounterTable $table;

	/**
	 * The accounts, by login and email.
	 *
	 * @var array<string, object>
	 */
	private array $users = [];

	protected function setUp(): void {
		parent::setUp();
		Counter::reset();
		$this->table = FakeCounterTable::install();
		unset( $GLOBALS['pagenow'] );

		$admin = Mockery::mock( 'WP_User' );
		$admin->ID    = 7;
		$admin->roles = [ 'administrator' ];
		$customer = Mockery::mock( 'WP_User' );
		$customer->ID    = 8;
		$customer->roles = [ 'customer' ];
		$this->users = [
			'login:bob'                => $admin,
			'email:bob@scanfully.com'  => $admin,
			'login:carol'              => $customer,
		];

		Functions\when( 'get_option' )->justReturn( 'yes' );
		Functions\when( 'is_email' )->alias( static fn( $text ) => false !== filter_var( $text, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'get_user_by' )->alias( fn( $field, $value ) => $this->users[ $field . ':' . $value ] ?? false );
		Functions\when( 'user_can' )->alias( static fn( $user ) => in_array( 'administrator', (array) $user->roles, true ) );
	}

	protected function tearDown(): void {
		Counter::reset();
		unset( $GLOBALS['pagenow'] );
		parent::tearDown();
	}

	/**
	 * A login error.
	 *
	 * @param string $code The error code.
	 * @param mixed  $data The error data.
	 *
	 * @return \WP_Error
	 */
	private function error( string $code, $data = null ) {
		$error = Mockery::mock( 'WP_Error' );
		$error->shouldReceive( 'get_error_code' )->andReturn( $code );
		$error->shouldReceive( 'get_error_data' )->andReturn( $data );

		return $error;
	}

	/**
	 * The counters of this hour, without the prefix.
	 *
	 * @return array<string, int>
	 */
	private function counted(): array {
		$prefix  = 'scanfully_lf_' . Store::hour() . '_';
		$counted = [];
		foreach ( $this->table->rows as $name => $count ) {
			$counted[ substr( $name, strlen( $prefix ) ) ] = $count;
		}
		ksort( $counted );

		return $counted;
	}

	public function test_a_wrong_password_on_an_admin_counts_the_account(): void {
		$GLOBALS['pagenow'] = 'wp-login.php';

		Counter::note_failure( 'bob', $this->error( 'incorrect_password' ) );

		$this->assertSame(
			[
				'c_login_page'     => 1,
				'r_wrong_password' => 1,
				'total'            => 1,
				'u_7'              => 1,
			],
			$this->counted()
		);
		$this->assertCount( 1, $this->table->queries, 'One query per failed login.' );
	}

	public function test_a_wrong_password_with_an_email_address_finds_the_account(): void {
		Counter::note_failure( 'bob@scanfully.com', $this->error( 'incorrect_password' ) );

		$this->assertArrayHasKey( 'u_7', $this->counted() );
	}

	public function test_a_wrong_password_on_a_customer_names_no_account(): void {
		Counter::note_failure( 'carol', $this->error( 'incorrect_password' ) );

		$this->assertSame(
			[
				'c_other'          => 1,
				'r_wrong_password' => 1,
				'total'            => 1,
			],
			$this->counted()
		);
	}

	/**
	 * @dataProvider provide_reasons
	 *
	 * @param string $code   The error code.
	 * @param string $reason The reason counted.
	 */
	public function test_the_reason_comes_from_the_error_code( string $code, string $reason ): void {
		Counter::note_failure( 'nobody', $this->error( $code ) );

		$this->assertArrayHasKey( 'r_' . $reason, $this->counted() );
		$this->assertArrayNotHasKey( 'u_7', $this->counted() );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function provide_reasons(): array {
		return [
			'unknown username'         => [ 'invalid_username', 'unknown_account' ],
			'unknown email address'    => [ 'invalid_email', 'unknown_account' ],
			'a security plugin blocks' => [ 'too_many_retries', 'blocked' ],
		];
	}

	public function test_an_unknown_username_is_never_kept(): void {
		Counter::note_failure( 'admin-attacker-guess', $this->error( 'invalid_username' ) );

		foreach ( array_merge( array_keys( $this->table->rows ), $this->table->queries ) as $text ) {
			$this->assertStringNotContainsString( 'attacker', $text );
		}
	}

	public function test_a_wrong_application_password_counts_as_its_own_way_in(): void {
		Counter::note_attempt( null, 'bob' );
		Counter::note_application_password_failure( $this->error( 'incorrect_password' ) );

		$this->assertSame(
			[
				'c_app_password'   => 1,
				'r_wrong_password' => 1,
				'total'            => 1,
				'u_7'              => 1,
			],
			$this->counted()
		);
	}

	public function test_a_wrong_application_password_over_the_rest_api_finds_the_account_from_the_header(): void {
		$_SERVER['PHP_AUTH_USER'] = 'bob';
		Functions\when( 'wp_unslash' )->returnArg();

		Counter::note_application_password_failure( $this->error( 'incorrect_password' ) );
		unset( $_SERVER['PHP_AUTH_USER'] );

		$this->assertArrayHasKey( 'u_7', $this->counted() );
	}

	public function test_one_login_attempt_counts_once_when_two_hooks_report_it(): void {
		Counter::note_attempt( null, 'bob' );
		Counter::note_application_password_failure( $this->error( 'incorrect_password' ) );
		Counter::note_failure( 'bob', $this->error( 'incorrect_password' ) );

		$this->assertSame( 1, $this->counted()['total'] );
	}

	public function test_nothing_is_counted_while_the_site_is_not_connected(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		Counter::note_failure( 'bob', $this->error( 'incorrect_password' ) );

		$this->assertSame( [], $this->table->rows );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_xml_rpc_is_its_own_way_in(): void {
		define( 'XMLRPC_REQUEST', true );

		$this->assertSame( 'xmlrpc', Counter::channel() );
	}
}
