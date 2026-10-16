<?php
/**
 * The site option changed event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class SiteOptionChanged
 *
 * Reports changes to a short list of key settings. One instance listens to
 * one option; Main registers one per option in OPTIONS.
 *
 * @package Scanfully\Events
 */
class SiteOptionChanged extends Event {

	/**
	 * The options that are reported.
	 */
	public const OPTIONS = [
		'siteurl',
		'home',
		'blogname',
		'admin_email',
		'users_can_register',
		'default_role',
		'blog_public',
	];

	/**
	 * Options that hold a yes or no. WordPress stores them as '1' or '0' but
	 * saves them as integers, so '0' and 0 must count as the same value.
	 */
	private const BOOLEAN_OPTIONS = [
		'users_can_register',
		'blog_public',
	];

	/**
	 * The option this instance listens to.
	 *
	 * @var string
	 */
	private string $option;

	/**
	 * Constructor.
	 *
	 * @param string $option The option name, one of OPTIONS.
	 */
	public function __construct( string $option ) {
		$this->option = $option;
		parent::__construct( 'SiteOptionChanged', 'update_option_' . $option, 10, 2 );
	}

	/**
	 * Get the post body
	 *
	 * @param  array $data The data to send: old value, new value.
	 *
	 * @return array
	 */
	public function get_post_body( array $data ): array {
		return [
			'option' => $this->option,
			'old'    => $this->to_string( $data[0] ?? null ),
			'new'    => $this->to_string( $data[1] ?? null ),
		];
	}

	/**
	 * A check if a event should fire
	 *
	 * WordPress fires the update hook whenever the stored value differs in
	 * type, such as '0' becoming 0 when the settings page is saved; that is
	 * not a change.
	 *
	 * @param  array $data The event data: old value, new value.
	 *
	 * @return bool
	 */
	public function should_fire( array $data ): bool {
		if ( ! in_array( $this->option, self::OPTIONS, true ) ) {
			return false;
		}

		return $this->to_string( $data[0] ?? null ) !== $this->to_string( $data[1] ?? null );
	}

	/**
	 * An option value as a string.
	 *
	 * @param mixed $value The option value.
	 *
	 * @return string
	 */
	private function to_string( $value ): string {
		if ( in_array( $this->option, self::BOOLEAN_OPTIONS, true ) ) {
			return ! empty( $value ) ? '1' : '0';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( null === $value ) {
			return '';
		}

		return (string) wp_json_encode( $value );
	}
}
