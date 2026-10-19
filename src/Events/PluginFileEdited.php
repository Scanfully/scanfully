<?php
/**
 * The PluginFileEdited event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class PluginFileEdited
 *
 * A file of a plugin was changed in WordPress's plugin file editor
 * (FileEdits\Collector). An edit WordPress put back because it broke the
 * site is not reported.
 *
 * Data: `name`, `slug`, `file` and `changes.content`, the content diff
 * (see PostChanges\ContentDiff::compare_code()).
 *
 * @package Scanfully\Events
 */
class PluginFileEdited extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'PluginFileEdited' );
	}
}
