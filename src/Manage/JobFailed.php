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
 * fatal, other), the message says what went wrong.
 */
class JobFailed extends \Exception {

	/**
	 * The error class.
	 *
	 * @var string
	 */
	private string $error_class;

	/**
	 * Constructor.
	 *
	 * @param string $error_class The error class.
	 * @param string $message     What went wrong.
	 */
	public function __construct( string $error_class, string $message ) {
		parent::__construct( $message );
		$this->error_class = $error_class;
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
