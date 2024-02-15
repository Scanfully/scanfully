<?php

namespace Scanfully\Options;

class Options {
	public bool $is_connected;
	public string $site_id;
	public string $access_token;
	public string $refresh_token;
	public string $expires;
	public string $last_used;
	public string $date_connected;

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