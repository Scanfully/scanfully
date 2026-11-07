<?php
/**
 * The Manage upgrader skin class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

/**
 * The silent skin a job runs WordPress's upgraders with: WordPress's own
 * skin for background updates, which prints nothing, collects the
 * upgrader's messages for the result's log and asks for no filesystem
 * credentials (it uses the ones wp-config.php defines, or fails), plus a
 * heartbeat at every step, sent at once before and after the long ones.
 *
 * Only loaded after WordPress's upgrader classes (Updates::load()).
 */
class UpgraderSkin extends \Automatic_Upgrader_Skin {

	/**
	 * The upgrader messages that start or end a long step: a heartbeat goes
	 * at once, so the lease never passes during a slow download, unzip or
	 * copy, or while the site is in maintenance mode.
	 */
	public const STEPS = [
		'downloading_package',
		'unpack_package',
		'installing_package',
		'maintenance_start',
		'maintenance_end',
		'remove_old',
		'process_success',
		'process_failed',
		'starting_upgrade',
		'start_rollback',
	];

	/**
	 * The run.
	 *
	 * @var JobRun
	 */
	private JobRun $run;

	/**
	 * Constructor.
	 *
	 * @param JobRun $run The run.
	 */
	public function __construct( JobRun $run ) {
		parent::__construct();
		$this->run = $run;
	}

	/**
	 * Keep a message, and send a heartbeat: at once at a long step, else
	 * when one is due.
	 *
	 * @param string|array|\WP_Error $feedback The message, or its key in the upgrader's strings.
	 * @param mixed                  ...$args  Replacements.
	 *
	 * @return void
	 */
	public function feedback( $feedback, ...$args ) {
		$this->run->beat( is_string( $feedback ) && in_array( $feedback, self::STEPS, true ) );
		parent::feedback( $feedback, ...$args );
	}

	/**
	 * The messages collected.
	 *
	 * @return string[]
	 */
	public function messages(): array {
		return (array) $this->get_upgrade_messages();
	}

	/**
	 * The error the upgrader reported through the skin, if any.
	 *
	 * @return \WP_Error|null
	 */
	public function error_result(): ?\WP_Error {
		return $this->result instanceof \WP_Error ? $this->result : null;
	}
}
