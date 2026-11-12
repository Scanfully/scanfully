<?php
/**
 * A multisite network for unit tests of the network's children.
 *
 * @package Scanfully\Tests\Unit\Connect
 */

namespace Scanfully\Tests\Unit\Connect;

use Brain\Monkey\Functions;
use Scanfully\Connect\Children;
use Scanfully\Connect\Network;
use Scanfully\Options\Controller as OptionsController;
use Scanfully\Tests\Unit\Health\FakeNetworkDb;

/**
 * A network of sites whose own options live in a FakeNetworkDb (as stored,
 * serialized), so the options API and the direct queries see the same
 * values, with the network's options, switch_to_blog() and Action Scheduler
 * calls recorded.
 */
trait NetworkFixture {

	/**
	 * Whether WordPress runs as a network.
	 *
	 * @var bool
	 */
	protected bool $multisite = true;

	/**
	 * The current site.
	 *
	 * @var int
	 */
	protected int $blog = 1;

	/**
	 * The blog ids switch_to_blog() left, last first.
	 *
	 * @var int[]
	 */
	protected array $switched = [];

	/**
	 * The blogs switch_to_blog() went to.
	 *
	 * @var int[]
	 */
	protected array $switches = [];

	/**
	 * The network's options.
	 *
	 * @var array<string, mixed>
	 */
	protected array $site_options = [];

	/**
	 * Action Scheduler calls: the call, the blog, the hook and the arguments.
	 *
	 * @var array<int, array{string, int, string, mixed}>
	 */
	protected array $as_calls = [];

	/**
	 * What as_next_scheduled_action() answers: a time (one waits), true (one
	 * runs) or false (none).
	 *
	 * @var int|bool
	 */
	protected $next_scheduled = false;

	/**
	 * The database.
	 *
	 * @var FakeNetworkDb
	 */
	protected FakeNetworkDb $db;

	/**
	 * Set up a network: the main site (1) at network.test, connected as a
	 * whole, the plugin network-activated, and two subsites.
	 *
	 * @return void
	 */
	protected function set_up_network(): void {
		$this->db        = new FakeNetworkDb();
		$GLOBALS['wpdb'] = $this->db;
		$this->db->add_site( 1, 'network.test', '/' );
		$this->db->add_site( 2, 'network.test', '/shop/' );
		$this->db->add_site( 3, 'network.test', '/news/' );
		$this->site_options = [
			'active_sitewide_plugins'        => [ 'scanfully/scanfully.php' => 1 ],
			'scanfully_connect_is_connected' => 'yes',
			'scanfully_connect_site_id'      => 'net-site',
			'scanfully_connect_access_token' => 'net-token',
			'scanfully_connect_address'      => hash( 'sha256', 'network.test' ),
		];

		Functions\when( 'is_multisite' )->alias( fn(): bool => $this->multisite );
		Functions\when( 'is_main_site' )->alias( fn(): bool => 1 === $this->blog );
		Functions\when( 'get_main_site_id' )->justReturn( 1 );
		Functions\when( 'get_current_blog_id' )->alias( fn(): int => $this->blog );
		Functions\when( 'get_current_network_id' )->justReturn( 1 );
		Functions\when( 'plugin_basename' )->justReturn( 'scanfully/scanfully.php' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'untrailingslashit' )->alias( static fn( string $url ): string => rtrim( $url, '/\\' ) );
		Functions\when( 'maybe_serialize' )->alias( static fn( $value ) => is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- As WordPress stores it.
		Functions\when( 'maybe_unserialize' )->alias(
			static function ( $value ) {
				if ( ! is_string( $value ) || 1 !== preg_match( '/^[aOsibd]:/', $value ) ) {
					return $value;
				}
				$out = @unserialize( $value, [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- As WordPress reads it.

				return false === $out ? $value : $out;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				$raw = $this->db->options[ $this->blog ][ $name ] ?? null;

				return null === $raw ? $fallback : maybe_unserialize( $raw );
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->db->options[ $this->blog ][ $name ] = maybe_serialize( $value );
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				$had = isset( $this->db->options[ $this->blog ][ $name ] );
				unset( $this->db->options[ $this->blog ][ $name ] );
				return $had;
			}
		);
		Functions\when( 'wp_load_alloptions' )->alias( fn(): array => $this->db->options[ $this->blog ] ?? [] );
		Functions\when( 'get_site_option' )->alias( fn( string $name, $fallback = false ) => $this->site_options[ $name ] ?? $fallback );
		Functions\when( 'get_network_option' )->alias( fn( $network, string $name, $fallback = false ) => $this->site_options[ $name ] ?? $fallback );
		Functions\when( 'update_site_option' )->alias(
			function ( string $name, $value ): bool {
				$this->site_options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'update_network_option' )->alias(
			function ( $network, string $name, $value ): bool {
				$this->site_options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_site_option' )->alias(
			function ( string $name ): bool {
				unset( $this->site_options[ $name ] );
				return true;
			}
		);
		Functions\when( 'delete_network_option' )->alias(
			function ( $network, string $name ): bool {
				unset( $this->site_options[ $name ] );
				return true;
			}
		);
		// As WordPress does, with the plugin's switch_blog hook (Main::setup()).
		Functions\when( 'switch_to_blog' )->alias(
			function ( int $id ): bool {
				$this->switched[] = $this->blog;
				$this->switches[] = $id;
				$this->blog       = $id;
				OptionsController::detect_scope();
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function (): bool {
				$this->blog = (int) array_pop( $this->switched );
				OptionsController::detect_scope();
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'did_action' )->alias( static fn( string $hook ): int => 'action_scheduler_init' === $hook ? 1 : 0 );
		foreach ( [ 'as_enqueue_async_action', 'as_schedule_single_action', 'as_schedule_recurring_action', 'as_unschedule_all_actions' ] as $call ) {
			Functions\when( $call )->alias(
				function ( ...$args ) use ( $call ) {
					if ( 'as_schedule_single_action' === $call || 'as_schedule_recurring_action' === $call ) {
						$this->as_calls[] = [ $call, $this->blog, (string) ( $args[ 'as_schedule_recurring_action' === $call ? 2 : 1 ] ?? '' ), $args[ 'as_schedule_recurring_action' === $call ? 3 : 2 ] ?? [] ];
					} else {
						$this->as_calls[] = [ $call, $this->blog, (string) ( $args[0] ?? '' ), $args[1] ?? [] ];
					}
					return 1;
				}
			);
		}
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'as_next_scheduled_action' )->alias( fn() => $this->next_scheduled );

		Network::forget();
		OptionsController::end_forced_scope();
		OptionsController::detect_scope();
	}

	/**
	 * Leave no detected network scope to the next test.
	 *
	 * @return void
	 */
	protected function tear_down_network(): void {
		OptionsController::end_forced_scope();
		$this->multisite = false;
		OptionsController::detect_scope();
		Network::forget();
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * Go to a site, as WordPress does for a request to it.
	 *
	 * @param int $blog The blog id.
	 *
	 * @return void
	 */
	protected function on_blog( int $blog ): void {
		$this->blog = $blog;
		Network::forget();
		OptionsController::detect_scope();
	}

	/**
	 * Give a subsite its marker, as the main site writes it.
	 *
	 * @param int    $blog The blog id.
	 * @param string $site The child's site id.
	 * @param string $home The address the marker is for; the site's own when ''.
	 *
	 * @return void
	 */
	protected function mark( int $blog, string $site, string $home = '' ): void {
		$home = '' !== $home ? $home : (string) $this->db->options[ $blog ]['home'];
		$this->db->options[ $blog ][ Children::MARKER_OPTION ] = serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- As WordPress stores it.
			[
				'site_id' => $site,
				'blog_id' => $blog,
				'home'    => hash( 'sha256', strtolower( (string) preg_replace( '#^https?://#', '', rtrim( $home, '/' ) ) ) ),
			]
		);
	}

	/**
	 * Monitor subsites: the mapping and their markers.
	 *
	 * @param array<int, string> $mapping Blog id to child site id.
	 *
	 * @return void
	 */
	protected function monitor( array $mapping ): void {
		$this->site_options[ Children::MAPPING_OPTION ] = $mapping;
		foreach ( $mapping as $blog => $site ) {
			$this->mark( $blog, $site );
		}
	}

	/**
	 * Connect a site on its own.
	 *
	 * @param int    $blog The blog id.
	 * @param string $site Its Scanfully site id.
	 *
	 * @return void
	 */
	protected function connect_own( int $blog, string $site ): void {
		$this->db->options[ $blog ]['scanfully_connect_is_connected'] = 'yes';
		$this->db->options[ $blog ]['scanfully_connect_site_id']      = $site;
		$this->db->options[ $blog ]['scanfully_connect_access_token'] = 'own-token-' . $blog;
	}

	/**
	 * The Action Scheduler calls of a hook.
	 *
	 * @param string $hook The hook.
	 *
	 * @return array<int, array{string, int, string, mixed}>
	 */
	protected function as_calls_of( string $hook ): array {
		return array_values( array_filter( $this->as_calls, static fn( array $call ): bool => $call[2] === $hook ) );
	}
}
