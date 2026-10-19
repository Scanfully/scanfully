<?php
/**
 * The ThemeFileEdited event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class ThemeFileEdited
 *
 * A file of a theme was changed in WordPress's theme file editor
 * (FileEdits\Collector). An edit WordPress put back because it broke the
 * site is not reported.
 *
 * Data: `name`, `slug`, `file` and `changes.content`, the content diff
 * (see PostChanges\ContentDiff::compare_code()).
 *
 * @package Scanfully\Events
 */
class ThemeFileEdited extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'ThemeFileEdited' );
	}
}
