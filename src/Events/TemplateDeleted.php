<?php
/**
 * The TemplateDeleted event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class TemplateDeleted
 *
 * A custom template or template part was deleted (or moved to the trash).
 * Its request reports a provisional row that the job turns into
 * TemplateReset when the theme or a plugin still has the template
 * (SiteEditor\Builder).
 *
 * Data: `name`, `kind`, `slug`, `stylesheet`, `theme`, `area`, `source`.
 *
 * @package Scanfully\Events
 */
class TemplateDeleted extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'TemplateDeleted' );
	}
}
