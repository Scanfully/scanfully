<?php
/**
 * The software lock class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

use Scanfully\Connect;
use Scanfully\Options\Controller as OptionsController;
use Scanfully\Sync;

/**
 * The software lock of Scanfully Manage. From the Scanfully dashboard, the
 * owner of a site with Scanfully Manage on can stop every WordPress user of
 * the site, administrators and super admins included, from installing,
 * updating and deleting plugins and themes, and from updating translations
 * and WordPress itself. There are four locks, each on its own: plugins,
 * themes, translations and core. Activating and deactivating plugins and
 * switching themes stay allowed.
 *
 * The setting lives in Scanfully only. The plugin keeps the API's answer
 * (GET /v1/sites/{site_id}/plugin-connection, which Connect\Scopes reads) in
 * one option and enforces it; it has no screen, notice or field of its own.
 *
 * The lock holds only while Scanfully itself can update the site: the
 * connection allows management actions, Scanfully Manage is not paused and
 * the site runs at the address it was connected from. Otherwise no one could
 * update the site, so turning Scanfully Manage off, a disconnect or a copy of
 * the site lifts it.
 *
 * It works as DISALLOW_FILE_MODS does in WordPress: the capabilities that
 * install, update and delete get do_not_allow, so the menus, buttons, update
 * notices, screens, AJAX actions and REST routes that need them follow, for
 * super admins too. Other capabilities, such as activate_plugins,
 * switch_themes and the Site Health ones, keep working, and
 * wp_is_file_mod_allowed() still answers as before. WordPress's own
 * background updates check no capability and go on, and the update data
 * WordPress keeps is left as it is. Scanfully Manage's own jobs are exempt
 * while they run.
 *
 * On a multisite network only the network connection holds a lock; it
 * applies on every site of the network.
 */
final class SoftwareLock {

	/**
	 * The option that keeps the lock, without the Scanfully prefix: the
	 * locked kinds, space separated (as the grant's scopes are kept).
	 */
	public const OPTION = 'software_lock';

	/**
	 * The option's full name, as the network connection keeps it.
	 */
	private const NETWORK_OPTION = 'scanfully_connect_software_lock';

	/**
	 * The network connection's granted scopes, by the option's full name.
	 */
	private const NETWORK_SCOPES_OPTION = 'scanfully_connect_scopes';

	/**
	 * The kinds of software a lock covers.
	 */
	public const KINDS = [ 'plugins', 'themes', 'translations', 'core' ];

	/**
	 * The capabilities a lock denies, by the kind they belong to. These are
	 * the capabilities WordPress itself denies under DISALLOW_FILE_MODS.
	 */
	public const CAPABILITIES = [
		'install_plugins'   => 'plugins',
		'upload_plugins'    => 'plugins',
		'update_plugins'    => 'plugins',
		'delete_plugins'    => 'plugins',
		'install_themes'    => 'themes',
		'upload_themes'     => 'themes',
		'update_themes'     => 'themes',
		'delete_themes'     => 'themes',
		'install_languages' => 'translations',
		'update_languages'  => 'translations',
		'update_core'       => 'core',
	];

	/**
	 * Whether a Scanfully Manage job runs in this PHP process (see
	 * while_job_runs()). Only code can set it: no request, option or session.
	 *
	 * @var bool
	 */
	private static bool $job = false;

	/**
	 * Whether the scanfully_software_lock_applies filter runs.
	 *
	 * @var bool
	 */
	private static bool $filtering = false;

	/**
	 * Register the capability check.
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_filter( 'map_meta_cap', [ self::class, 'map_meta_cap' ], 10, 4 );
	}

	/**
	 * The lock in an answer of the API, checked: true for a kind only when
	 * the answer says true for it. A missing or malformed lock locks
	 * nothing.
	 *
	 * @param mixed $raw The software_lock field of the answer.
	 *
	 * @return array<string, bool> By kind.
	 */
	public static function of( $raw ): array {
		$lock = [];
		foreach ( self::KINDS as $kind ) {
			$lock[ $kind ] = is_array( $raw ) && true === ( $raw[ $kind ] ?? null );
		}

		return $lock;
	}

	/**
	 * Keep the lock of a successful read of the connection (Connect\Scopes).
	 * An answer without one clears it; a read that failed never gets here,
	 * so the last lock the API confirmed stays. A connection that is
	 * monitoring only keeps none. A change is reported to Scanfully at
	 * once, so the dashboard sees it applied within seconds.
	 *
	 * @param mixed $raw The software_lock field of the answer; null when it has none.
	 *
	 * @return bool Whether the lock changed.
	 */
	public static function learn( $raw ): bool {
		$lock = Connect\Multisite::monitoring_only() ? self::of( null ) : self::of( $raw );
		if ( self::held() === $lock ) {
			return false;
		}
		OptionsController::set_option( self::OPTION, self::encode( $lock ) );
		Sync\Controller::request_report();

		return true;
	}

	/**
	 * The lock the connection that applies here keeps, by kind.
	 *
	 * @return array<string, bool>
	 */
	public static function held(): array {
		return self::decode( OptionsController::get_option( self::OPTION ) );
	}

	/**
	 * Whether the connection that applies here enforces its lock now: it
	 * allows management actions, Scanfully Manage is not paused and the
	 * site runs at the address it was connected from.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return Controller::allowed() && ! Controller::paused() && ! Connect\Address::moved();
	}

	/**
	 * The lock as the site data reports it: whether it is enforced now, and
	 * what the connection keeps.
	 *
	 * @return array{active: bool, plugins: bool, themes: bool, translations: bool, core: bool}
	 */
	public static function report(): array {
		$held = self::held();

		return [
			'active'       => self::active(),
			'plugins'      => $held['plugins'],
			'themes'       => $held['themes'],
			'translations' => $held['translations'],
			'core'         => $held['core'],
		];
	}

	/**
	 * Whether a kind is locked in this request: a Scanfully Manage job does
	 * not run in this process, the lock that applies holds the kind, and it
	 * is enforced. On a site of a network other than the main site, the
	 * network connection's lock applies.
	 *
	 * @param string $kind One of KINDS.
	 *
	 * @return bool
	 */
	public static function locked( string $kind ): bool {
		if ( self::$job || ! in_array( $kind, self::KINDS, true ) ) {
			return false;
		}
		if ( Connect\Network::multisite() && ! OptionsController::network_scope() ) {
			return self::locked_by_network( $kind );
		}

		return self::held()[ $kind ] && self::active();
	}

	/**
	 * Whether the network connection locks a kind, asked from a site of the
	 * network that does not work under it: the plugin is network-activated
	 * and the network connected, its lock holds the kind, and it is enforced
	 * by the same rules as on the main site (see active()), read from the
	 * network's options and the main site's.
	 *
	 * @param string $kind One of KINDS.
	 *
	 * @return bool
	 */
	private static function locked_by_network( string $kind ): bool {
		if ( ! Connect\Network::applies_on( (int) get_current_network_id() ) ) {
			return false;
		}
		if ( ! self::decode( Connect\Network::network_option( self::NETWORK_OPTION ) )[ $kind ] ) {
			return false;
		}
		$scopes = array_values( array_filter( explode( ' ', Connect\Network::network_option( self::NETWORK_SCOPES_OPTION ) ) ) );
		if ( ! in_array( Connect\OAuth::SCOPE_MANAGE, $scopes, true ) || Connect\Network::moved() ) {
			return false;
		}
		// The network's Scanfully Manage keeps its pause in the main site's
		// options, as Controller::paused() reads it there.
		$paused = Connect\Network::main_option( Controller::PAUSED_OPTION );

		return '' === $paused || implode( ' ', $scopes ) !== $paused;
	}

	/**
	 * Run a Scanfully Manage job with the lock lifted for this PHP process,
	 * until it returns or throws. An update a plugin or theme does in its
	 * own way may check the user's capabilities, and a job may run in a
	 * request that carries an administrator's sign-in. Only the job runner
	 * calls this, once the job's signed tokens were checked.
	 *
	 * @template T
	 *
	 * @param callable(): T $run The job.
	 *
	 * @return T
	 */
	public static function while_job_runs( callable $run ) {
		$before    = self::$job;
		self::$job = true;
		try {
			return $run();
		} finally {
			self::$job = $before;
		}
	}

	/**
	 * Whether a Scanfully Manage job runs in this PHP process.
	 *
	 * @return bool
	 */
	public static function job_runs(): bool {
		return self::$job;
	}

	/**
	 * Deny a capability the lock covers, as WordPress does under
	 * DISALLOW_FILE_MODS: add do_not_allow to the capabilities it maps to.
	 * Keyed on the capability asked for, so the primitive capabilities and
	 * every other capability that maps to them keep working.
	 *
	 * @param mixed $caps    The primitive capabilities the user needs.
	 * @param mixed $cap     The capability asked for.
	 * @param mixed $user_id The user's ID.
	 * @param mixed $args    Further arguments, such as an object ID.
	 *
	 * @return mixed The primitive capabilities.
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The filter's arguments.
		if ( ! is_array( $caps ) || ! is_string( $cap ) || ! isset( self::CAPABILITIES[ $cap ] ) ) {
			return $caps;
		}
		$kind = self::CAPABILITIES[ $cap ];
		if ( ! self::locked( $kind ) ) {
			return $caps;
		}
		// A capability check inside the filter is answered with the lock in
		// place, without asking the filter again.
		if ( ! self::$filtering ) {
			self::$filtering = true;
			try {
				/**
				 * Filters whether the software lock applies to a user, for one
				 * capability it covers. Return false to exempt the user, for
				 * example from a must-use plugin for one agency account. The
				 * lock is set in the Scanfully dashboard, and only applies
				 * while it is enforced on the site.
				 *
				 * A capability check of the locked capabilities from inside a
				 * callback is answered with the lock in place.
				 *
				 * @param bool   $applies Whether the lock applies. Default true.
				 * @param int    $user_id The user's ID.
				 * @param string $kind    What the lock covers: plugins, themes, translations or core.
				 * @param string $cap     The capability asked for, such as update_plugins.
				 */
				$applies = apply_filters( 'scanfully_software_lock_applies', true, (int) $user_id, $kind, $cap );
			} finally {
				self::$filtering = false;
			}
			if ( false === $applies ) {
				return $caps;
			}
		}
		$caps[] = 'do_not_allow';

		return $caps;
	}

	/**
	 * A lock as the option keeps it: the locked kinds, space separated.
	 *
	 * @param array<string, bool> $lock By kind.
	 *
	 * @return string
	 */
	private static function encode( array $lock ): string {
		return implode( ' ', array_keys( array_filter( $lock ) ) );
	}

	/**
	 * A lock as the option keeps it, by kind.
	 *
	 * @param string $stored The stored value.
	 *
	 * @return array<string, bool>
	 */
	private static function decode( string $stored ): array {
		$names = explode( ' ', $stored );
		$lock  = [];
		foreach ( self::KINDS as $kind ) {
			$lock[ $kind ] = in_array( $kind, $names, true );
		}

		return $lock;
	}
}
