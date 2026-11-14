<?php
/**
 * What Scanfully's must-use plugin starts before other plugins load.
 *
 * Included by `wp-content/mu-plugins/scanfully-mu.php` (MustUse\MuPlugin),
 * which calls the function it returns with the Scanfully plugin's basename.
 * Start anything new that has to run early here, and add its check to
 * MuPlugin::needed(), so the file on the site never has to change.
 *
 * @package Scanfully
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return static function ( string $basename ): void {
	// PHP errors: catch them from before any plugin loads. The monitor
	// checks itself that the site is connected and the Scanfully plugin is
	// active.
	require_once __DIR__ . '/Errors/load.php';
	\Scanfully\Errors\Monitor::boot( 'mu', $basename );
};
