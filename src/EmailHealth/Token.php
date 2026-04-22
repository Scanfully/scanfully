<?php
/**
 * HMAC token computation for email-deliverability attempts.
 *
 * Mirrors the API's internal/app/service/emailhealth/deliverability_secret.go
 * computeHMAC: hex hmac-sha256 over (siteID || nonce || timestamp).
 *
 * @package Scanfully
 */

namespace Scanfully\EmailHealth;

/**
 * Pure helper; no WordPress dependencies.
 */
class Token {

	/**
	 * Compute the hex hmac-sha256 token used to bind an attempt + ping.
	 *
	 * @param string $secret    Per-site HMAC secret (raw string from /provision).
	 * @param string $site_id   Canonical hyphenated site UUID.
	 * @param string $nonce     Canonical hyphenated nonce UUID.
	 * @param string $timestamp RFC 3339 UTC timestamp.
	 *
	 * @return string Lowercase hex digest.
	 */
	public static function compute( string $secret, string $site_id, string $nonce, string $timestamp ): string {
		return hash_hmac( 'sha256', $site_id . $nonce . $timestamp, $secret );
	}
}
