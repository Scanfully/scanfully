<?php
/**
 * Software lock unit tests: what the plugin keeps from the API, when the
 * lock holds, the capabilities it denies, the filter and Scanfully's jobs.
 *
 * @package Scanfully\Tests\Unit\Manage
 */

namespace Scanfully\Tests\Unit\Manage;

use Brain\Monkey\Filters;
use Scanfully\Cron\Controller as CronController;
use Scanfully\Manage\SoftwareLock;
use Scanfully\Tests\Unit\Connect\NetworkFixture;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Manage\SoftwareLock
 * @covers \Scanfully\Connect\Network::main_option
 * @covers \Scanfully\Connect\Network::network_option
 */
final class SoftwareLockTest extends TestCase {

	use NetworkFixture;

	/**
	 * The capabilities each lock denies.
	 */
	private const DENIED = [
		'plugins'      => [ 'install_plugins', 'upload_plugins', 'update_plugins', 'delete_plugins' ],
		'themes'       => [ 'install_themes', 'upload_themes', 'update_themes', 'delete_themes' ],
		'translations' => [ 'install_languages', 'update_languages' ],
		'core'         => [ 'update_core' ],
	];

	/**
	 * Capabilities no lock touches.
	 */
	private const KEPT = [ 'activate_plugins', 'activate_plugin', 'deactivate_plugin', 'switch_themes', 'edit_theme_options', 'view_site_health_checks', 'update_php', 'update_https', 'edit_plugins', 'edit_themes', 'resume_plugin', 'manage_options', 'manage_network_plugins' ];

	/**
	 * The scopes of a connection with Scanfully Manage on.
	 */
	private const MANAGE = 'wordpress:read wordpress:manage';

	protected function set_up(): void {
		parent::set_up();
		$this->set_up_network();
		$GLOBALS['scanfully_test_action_scheduler'] = [
			'locks'      => [],
			'dispatched' => [],
		];
		// A single site at network.test, connected with Scanfully Manage on;
		// the network tests turn it into a network.
		$this->multisite     = false;
		$this->db->options[1] = array_merge(
			$this->db->options[1],
			[
				'scanfully_connect_is_connected' => 'yes',
				'scanfully_connect_site_id'      => 'site-1',
				'scanfully_connect_flow'         => 'oauth',
				'scanfully_connect_scopes'       => self::MANAGE,
				'scanfully_connect_address'      => hash( 'sha256', 'network.test' ),
			]
		);
		$this->on_blog( 1 );
	}

	protected function tear_down(): void {
		$this->tear_down_network();
		unset( $GLOBALS['scanfully_test_action_scheduler'] );
		parent::tear_down();
	}

	/**
	 * Lock kinds on the single site.
	 *
	 * @param string $kinds The locked kinds, space separated, as stored.
	 *
	 * @return void
	 */
	private function lock( string $kinds ): void {
		$this->db->options[1]['scanfully_connect_software_lock'] = $kinds;
		$this->on_blog( $this->blog );
	}

	/**
	 * The primitive capabilities a check of a capability needs, after the
	 * lock's filter.
	 *
	 * @param string $cap  The capability asked for.
	 * @param int    $user The user's ID.
	 *
	 * @return mixed
	 */
	private function caps( string $cap, int $user = 1 ) {
		return SoftwareLock::map_meta_cap( [ 'manage_options' ], $cap, $user, [] );
	}

	/**
	 * Whether the lock denies a capability.
	 *
	 * @param string $cap  The capability asked for.
	 * @param int    $user The user's ID.
	 *
	 * @return bool
	 */
	private function denied( string $cap, int $user = 1 ): bool {
		return in_array( 'do_not_allow', (array) $this->caps( $cap, $user ), true );
	}

	/**
	 * The capabilities the lock denies, of all it may deny.
	 *
	 * @return string[]
	 */
	private function all_denied(): array {
		return array_values( array_filter( array_keys( SoftwareLock::CAPABILITIES ), [ $this, 'denied' ] ) );
	}

	public function test_an_unlocked_site_denies_nothing_and_leaves_the_capabilities_as_they_were(): void {
		Filters\expectApplied( 'scanfully_software_lock_applies' )->never();

		$this->assertSame( [], $this->all_denied() );
		$this->assertSame( [ 'manage_options' ], $this->caps( 'update_plugins' ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function kinds(): array {
		return [
			'plugins'      => [ 'plugins' ],
			'themes'       => [ 'themes' ],
			'translations' => [ 'translations' ],
			'core'         => [ 'core' ],
		];
	}

	/**
	 * @dataProvider kinds
	 *
	 * @param string $kind The kind.
	 */
	public function test_each_lock_denies_its_own_capabilities_only( string $kind ): void {
		$this->lock( $kind );

		$this->assertSame( self::DENIED[ $kind ], $this->all_denied() );
		$this->assertSame( [ 'manage_options', 'do_not_allow' ], $this->caps( self::DENIED[ $kind ][0] ), 'added to what the capability maps to, as DISALLOW_FILE_MODS does' );
	}

	public function test_all_four_locks_deny_every_capability_they_cover_and_nothing_else(): void {
		$this->lock( 'plugins themes translations core' );

		$this->assertSame( array_keys( SoftwareLock::CAPABILITIES ), $this->all_denied() );
		foreach ( self::KEPT as $cap ) {
			$this->assertFalse( $this->denied( $cap ), $cap . ' keeps working' );
		}
	}

	public function test_a_capability_wordpress_asks_with_odd_arguments_is_left_alone(): void {
		$this->lock( 'plugins' );

		$this->assertSame( 'not-an-array', SoftwareLock::map_meta_cap( 'not-an-array', 'update_plugins', 1, [] ) );
		$this->assertSame( [ 'x' ], SoftwareLock::map_meta_cap( [ 'x' ], null, 1, [] ) );
	}

	public function test_the_lock_holds_only_while_the_connection_allows_scanfully_manage(): void {
		$this->lock( 'plugins' );
		$this->assertTrue( $this->denied( 'update_plugins' ) );

		$this->db->options[1]['scanfully_connect_scopes'] = 'wordpress:read';
		$this->on_blog( 1 );
		$this->assertFalse( $this->denied( 'update_plugins' ), 'Scanfully Manage turned off' );

		$this->db->options[1]['scanfully_connect_scopes']       = self::MANAGE;
		$this->db->options[1]['scanfully_connect_is_connected'] = 'no';
		$this->on_blog( 1 );
		$this->assertFalse( $this->denied( 'update_plugins' ), 'disconnected' );
	}

	public function test_a_paused_scanfully_manage_lifts_the_lock_until_the_grant_changes(): void {
		$this->lock( 'core' );
		$this->db->options[1]['scanfully_manage_paused'] = self::MANAGE;
		$this->on_blog( 1 );
		$this->assertFalse( $this->denied( 'update_core' ) );

		$this->db->options[1]['scanfully_manage_paused'] = 'wordpress:read';
		$this->on_blog( 1 );
		$this->assertTrue( $this->denied( 'update_core' ), 'a pause of an earlier grant has ended' );
	}

	public function test_a_copy_of_the_site_at_another_address_is_never_locked(): void {
		$this->lock( 'plugins themes translations core' );
		$this->db->options[1]['home'] = 'https://staging.network.test';
		$this->on_blog( 1 );

		$this->assertSame( [], $this->all_denied() );
		$this->assertFalse( SoftwareLock::active() );
	}

	public function test_the_filter_exempts_a_user_and_hears_the_kind_and_the_capability(): void {
		$this->lock( 'plugins core' );
		$heard = [];
		Filters\expectApplied( 'scanfully_software_lock_applies' )->andReturnUsing(
			static function ( $applies, $user_id, $kind, $cap ) use ( &$heard ) {
				$heard[] = [ $applies, $user_id, $kind, $cap ];
				return 7 !== $user_id;
			}
		);

		$this->assertFalse( $this->denied( 'update_plugins', 7 ) );
		$this->assertFalse( $this->denied( 'update_core', 7 ) );
		$this->assertTrue( $this->denied( 'update_plugins', 8 ), 'another user stays locked' );
		$this->assertSame( [ true, 7, 'plugins', 'update_plugins' ], $heard[0] );
		$this->assertSame( [ true, 7, 'core', 'update_core' ], $heard[1] );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function not_false(): array {
		return [
			'null'         => [ null ],
			'zero'         => [ 0 ],
			'empty string' => [ '' ],
			'true'         => [ true ],
		];
	}

	/**
	 * @dataProvider not_false
	 *
	 * @param mixed $answer What the callback returns.
	 */
	public function test_only_false_from_the_filter_exempts( $answer ): void {
		$this->lock( 'plugins' );
		Filters\expectApplied( 'scanfully_software_lock_applies' )->andReturn( $answer );

		$this->assertTrue( $this->denied( 'update_plugins' ), 'a callback that forgets to return does not lift the lock' );
	}

	public function test_a_capability_check_inside_the_filter_sees_the_lock_without_asking_again(): void {
		$this->lock( 'plugins' );
		$inside = null;
		Filters\expectApplied( 'scanfully_software_lock_applies' )->once()->andReturnUsing(
			function () use ( &$inside ) {
				$inside = $this->denied( 'install_plugins' );
				return false;
			}
		);

		$this->assertFalse( $this->denied( 'update_plugins' ) );
		$this->assertTrue( $inside );
	}

	public function test_a_scanfully_job_runs_with_the_lock_lifted_in_its_own_process(): void {
		$this->lock( 'plugins themes translations core' );
		$this->assertFalse( SoftwareLock::job_runs() );

		$inside = SoftwareLock::while_job_runs(
			function (): array {
				return [ SoftwareLock::job_runs(), $this->all_denied() ];
			}
		);

		$this->assertSame( [ true, [] ], $inside );
		$this->assertFalse( SoftwareLock::job_runs() );
		$this->assertSame( array_keys( SoftwareLock::CAPABILITIES ), $this->all_denied(), 'locked again once the job returned' );
	}

	public function test_a_job_that_throws_still_ends_the_exemption(): void {
		$this->lock( 'plugins' );

		$thrown = '';
		try {
			SoftwareLock::while_job_runs(
				static function () {
					throw new \RuntimeException( 'the job broke' );
				}
			);
		} catch ( \RuntimeException $e ) {
			$thrown = $e->getMessage();
		}

		$this->assertSame( 'the job broke', $thrown, 'the exception goes on' );
		$this->assertFalse( SoftwareLock::job_runs() );
		$this->assertTrue( $this->denied( 'update_plugins' ) );
	}

	public function test_a_nested_run_keeps_the_exemption_of_the_outer_one(): void {
		$this->lock( 'plugins' );

		$after_inner = SoftwareLock::while_job_runs(
			static function (): bool {
				SoftwareLock::while_job_runs( static fn() => null );
				return SoftwareLock::job_runs();
			}
		);

		$this->assertTrue( $after_inner );
		$this->assertFalse( SoftwareLock::job_runs() );
	}

	public function test_only_true_locks_a_kind_in_an_answer(): void {
		$this->assertSame(
			[
				'plugins'      => false,
				'themes'       => false,
				'translations' => false,
				'core'         => true,
			],
			SoftwareLock::of(
				[
					'plugins'      => 1,
					'themes'       => 'true',
					'translations' => null,
					'core'         => true,
				]
			)
		);
		$none = array_fill_keys( SoftwareLock::KINDS, false );
		$this->assertSame( $none, SoftwareLock::of( null ), 'a missing lock' );
		$this->assertSame( $none, SoftwareLock::of( 'plugins' ), 'a malformed lock' );
		$this->assertSame( $none, SoftwareLock::of( [ true, true, true, true ] ), 'a list' );
	}

	public function test_a_learned_lock_is_kept_and_reported_once(): void {
		$changed = SoftwareLock::learn(
			[
				'plugins'      => true,
				'themes'       => false,
				'translations' => false,
				'core'         => true,
			]
		);

		$this->assertTrue( $changed );
		$this->assertSame( 'plugins core', $this->db->options[1]['scanfully_connect_software_lock'] );
		$this->assertCount( 1, $this->as_calls_of( CronController::ACTION_SYNC_SITE_HEALTH ), 'the site reports the change' );
		$this->assertCount( 1, $GLOBALS['scanfully_test_action_scheduler']['dispatched'], 'at once' );
		$this->assertTrue( $this->denied( 'delete_plugins' ) );
		$this->assertTrue( $this->denied( 'update_core' ) );
		$this->assertFalse( $this->denied( 'update_themes' ) );

		$this->assertFalse(
			SoftwareLock::learn(
				[
					'core'    => true,
					'plugins' => true,
				]
			),
			'the same lock'
		);
		$this->assertCount( 1, $this->as_calls_of( CronController::ACTION_SYNC_SITE_HEALTH ), 'no second report' );
	}

	public function test_an_answer_without_a_lock_lifts_it(): void {
		$this->lock( 'themes' );

		$this->assertTrue( SoftwareLock::learn( null ) );

		$this->assertSame( '', $this->db->options[1]['scanfully_connect_software_lock'] );
		$this->assertSame( array_fill_keys( SoftwareLock::KINDS, false ), SoftwareLock::held() );
		$this->assertCount( 1, $this->as_calls_of( CronController::ACTION_SYNC_SITE_HEALTH ) );
		$this->assertSame( [], $this->all_denied() );
	}

	public function test_a_site_that_never_had_a_lock_stores_nothing(): void {
		$this->assertFalse( SoftwareLock::learn( null ) );
		$this->assertFalse( SoftwareLock::learn( array_fill_keys( SoftwareLock::KINDS, false ) ) );

		$this->assertArrayNotHasKey( 'scanfully_connect_software_lock', $this->db->options[1] );
		$this->assertSame( [], $this->as_calls );
	}

	public function test_the_report_says_what_the_site_keeps_and_whether_it_is_enforced(): void {
		$this->lock( 'translations' );
		$enforced = SoftwareLock::report();
		$this->db->options[1]['scanfully_connect_scopes'] = 'wordpress:read';
		$this->on_blog( 1 );
		$kept = SoftwareLock::report();

		$this->assertSame(
			[
				'active'       => true,
				'plugins'      => false,
				'themes'       => false,
				'translations' => true,
				'core'         => false,
			],
			$enforced
		);
		$this->assertSame( [ false, true ], [ $kept['active'], $kept['translations'] ], 'kept, but not enforced without Scanfully Manage' );
	}

	/**
	 * Turn the site into a network connected as a whole with Scanfully
	 * Manage on, locking kinds; site 1 keeps no connection of its own.
	 *
	 * @param string $kinds The locked kinds, space separated, as stored.
	 *
	 * @return void
	 */
	private function network( string $kinds ): void {
		$this->multisite = true;
		foreach ( [ 'scanfully_connect_is_connected', 'scanfully_connect_site_id', 'scanfully_connect_flow', 'scanfully_connect_scopes', 'scanfully_connect_address' ] as $name ) {
			unset( $this->db->options[1][ $name ] );
		}
		$this->site_options['scanfully_connect_flow']          = 'oauth';
		$this->site_options['scanfully_connect_scopes']        = self::MANAGE;
		$this->site_options['scanfully_connect_software_lock'] = $kinds;
		$this->on_blog( 1 );
	}

	public function test_the_network_lock_holds_in_network_admin_and_on_every_site_of_the_network(): void {
		$this->network( 'plugins themes' );

		foreach ( [ 1, 2, 3 ] as $blog ) {
			$this->on_blog( $blog );
			$this->assertSame( array_merge( self::DENIED['plugins'], self::DENIED['themes'] ), $this->all_denied(), 'site ' . $blog );
		}
	}

	public function test_a_super_admin_s_filter_exemption_works_on_a_subsite_too(): void {
		$this->network( 'core' );
		$this->on_blog( 2 );
		Filters\expectApplied( 'scanfully_software_lock_applies' )->andReturnUsing( static fn( $applies, $user_id ) => 1 !== $user_id );

		$this->assertFalse( $this->denied( 'update_core', 1 ) );
		$this->assertTrue( $this->denied( 'update_core', 2 ) );
	}

	public function test_the_network_learns_its_lock_into_the_network_s_options(): void {
		$this->network( '' );

		$this->assertTrue( SoftwareLock::learn( [ 'themes' => true ] ) );

		$this->assertSame( 'themes', $this->site_options['scanfully_connect_software_lock'] );
		$this->assertArrayNotHasKey( 'scanfully_connect_software_lock', $this->db->options[1] );
		$this->on_blog( 3 );
		$this->assertTrue( $this->denied( 'install_themes' ) );
	}

	public function test_a_subsite_s_own_connection_keeps_no_lock_and_is_not_locked_by_one(): void {
		$this->network( '' );
		$this->connect_own( 2, 'child-2' );
		$this->db->options[2]['scanfully_connect_scopes'] = self::MANAGE;
		$this->on_blog( 2 );

		$this->assertFalse( SoftwareLock::learn( [ 'plugins' => true ] ), 'monitoring only' );
		$this->assertArrayNotHasKey( 'scanfully_connect_software_lock', $this->db->options[2] );

		// A value left in the subsite's own options by anything else.
		$this->db->options[2]['scanfully_connect_software_lock'] = 'plugins';
		$this->on_blog( 2 );
		$this->assertSame( [], $this->all_denied() );
		$this->assertFalse( SoftwareLock::report()['active'] );
	}

	public function test_the_network_lock_needs_the_network_connection_with_scanfully_manage(): void {
		$this->network( 'plugins' );

		$this->site_options['active_sitewide_plugins'] = [];
		$this->on_blog( 2 );
		$this->assertFalse( $this->denied( 'update_plugins' ), 'not network-activated' );

		$this->site_options['active_sitewide_plugins']        = [ 'scanfully/scanfully.php' => 1 ];
		$this->site_options['scanfully_connect_is_connected'] = 'no';
		$this->on_blog( 2 );
		$this->assertFalse( $this->denied( 'update_plugins' ), 'the network is not connected' );

		$this->site_options['scanfully_connect_is_connected'] = 'yes';
		$this->site_options['scanfully_connect_scopes']       = 'wordpress:read';
		$this->on_blog( 2 );
		$this->assertFalse( $this->denied( 'update_plugins' ), 'Scanfully Manage is off' );
		$this->on_blog( 1 );
		$this->assertFalse( $this->denied( 'update_plugins' ), 'nor in Network Admin' );
	}

	public function test_a_paused_network_lifts_the_lock_everywhere(): void {
		$this->network( 'plugins' );
		$this->db->options[1]['scanfully_manage_paused'] = self::MANAGE;

		$this->on_blog( 2 );
		$this->assertFalse( $this->denied( 'update_plugins' ) );
		$this->on_blog( 1 );
		$this->assertFalse( $this->denied( 'update_plugins' ) );
	}

	public function test_a_copy_of_the_network_is_never_locked(): void {
		$this->network( 'plugins' );
		$this->db->options[1]['home'] = 'https://network.copy';

		$this->on_blog( 2 );
		$this->assertFalse( $this->denied( 'update_plugins' ) );
		$this->on_blog( 1 );
		$this->assertFalse( $this->denied( 'update_plugins' ) );
	}

	public function test_a_subsite_reads_the_main_site_s_pause_once_per_request(): void {
		$this->network( 'plugins' );
		$this->on_blog( 2 );
		$before = count( $this->db->option_queries() );

		$this->denied( 'update_plugins' );
		$this->denied( 'install_plugins' );
		$this->denied( 'delete_plugins' );

		$this->assertLessThanOrEqual( 2, count( $this->db->option_queries() ) - $before, 'the main site\'s home and pause, read once' );
	}
}
