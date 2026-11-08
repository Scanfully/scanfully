<?php
/**
 * The Manage job failure class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Manage;

/**
 * Thrown by an action that failed in a way it can name: the class is one of
 * the API's error classes (not_supported, filesystem, package, timeout,
 * fatal, other, and for updates maintenance, multisite_refused, locked,
 * not_available, license, signature, and for installs, toggles and deletes
 * already_installed, not_installed, plugin_active, theme_in_use), the message says what went wrong, and
 * the result holds what the action still knows (its log, the version before,
 * the inventory after).
 */
class JobFailed extends \Exception {

	/**
	 * The error class.
	 *
	 * @var string
	 */
	private string $error_class;

	/**
	 * What the action still knows, for the result.
	 *
	 * @var array<string, mixed>
	 */
	private array $result;

	/**
	 * Constructor.
	 *
	 * @param string               $error_class The error class.
	 * @param string               $message     What went wrong.
	 * @param array<string, mixed> $result      What the action still knows, for the result.
	 */
	public function __construct( string $error_class, string $message, array $result = [] ) {
		parent::__construct( $message );
		$this->error_class = $error_class;
		$this->result      = $result;
	}

	/**
	 * What the action still knows, for the result.
	 *
	 * @return array<string, mixed>
	 */
	public function result(): array {
		return $this->result;
	}

	/**
	 * The error class.
	 *
	 * @return string
	 */
	public function error_class(): string {
		return $this->error_class;
	}
}
