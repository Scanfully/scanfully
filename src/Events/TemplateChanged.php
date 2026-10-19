<?php
/**
 * The TemplateChanged event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class TemplateChanged
 *
 * A template or template part of a block theme was saved, including its
 * first customization, compared with the theme's or plugin's own version
 * (SiteEditor\Collector). The job also turns the provisional rows of a
 * first save and a delete into TemplateCreated, TemplateReset or
 * TemplateDeleted.
 *
 * Data: `name` (the title), `kind` (`template` or `template_part`), `slug`,
 * `stylesheet`, `theme`, `area`, `source`, `first_customization`, and
 * `changes` with `title`, `description`, `area` and `blocks` (a block
 * outline diff). See SiteEditor\Builder.
 *
 * @package Scanfully\Events
 */
class TemplateChanged extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'TemplateChanged' );
	}
}
