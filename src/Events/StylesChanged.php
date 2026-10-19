<?php
/**
 * The StylesChanged event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class StylesChanged
 *
 * The global styles of a block theme were saved in the Site Editor: a
 * change, a style variation picked, a reset to the theme's styles or a
 * restored revision. One row per save (SiteEditor\Collector).
 *
 * Data: `stylesheet` and `name` (the theme), and `changes` with `styles`
 * (each `path`, `change`, `old`, `new`, `large`), `variation` (old and new
 * title), `reset` and `css` (added and removed lines, as for file edits).
 * See SiteEditor\Builder.
 *
 * @package Scanfully\Events
 */
class StylesChanged extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'StylesChanged' );
	}
}
