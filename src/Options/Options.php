<?php
/**
 * The options value object file.
 *
 * @package Scanfully
 */

namespace Scanfully\Options;

/**
 * Value object holding the Scanfully connection options.
 */
class Options {

	/**
	 * Whether the site is connected to Scanfully.
	 *
	 * @var bool
	 */
	public bool $is_connected;

	/**
	 * The Scanfully site ID.
	 *
	 * @var string
	 */
	public string $site_id;

	/**
	 * The API access token.
	 *
	 * @var string
	 */
	public string $access_token;

	/**
	 * The API refresh token.
	 *
	 * @var string
	 */
	public string $refresh_token;

	/**
	 * The access token expiry date.
	 *
	 * @var string
	 */
	public string $expires;

	/**
	 * The date the connection was last used.
	 *
	 * @var string
	 */
	public string $last_used;

	/**
	 * The date the site was connected.
	 *
	 * @var string
	 */
	public string $date_connected;

	/**
	 * Constructor.
	 *
	 * @param bool   $is_connected   Whether the site is connected to Scanfully.
	 * @param string $site_id        The Scanfully site ID.
	 * @param string $access_token   The API access token.
	 * @param string $refresh_token  The API refresh token.
	 * @param string $expires        The access token expiry date.
	 * @param string $last_used      The date the connection was last used.
	 * @param string $date_connected The date the site was connected.
	 */
	public function __construct( bool $is_connected, string $site_id, string $access_token, string $refresh_token, string $expires, string $last_used, string $date_connected ) {
		$this->is_connected   = $is_connected;
		$this->site_id        = $site_id;
		$this->access_token   = $access_token;
		$this->refresh_token  = $refresh_token;
		$this->expires        = $expires;
		$this->last_used      = $last_used;
		$this->date_connected = $date_connected;
	}
}
