<?php
/**
 * The TemplateCreated event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class TemplateCreated
 *
 * A new custom template or template part was made in the Site Editor. The
 * job of a provisional TemplateChanged row reports it (SiteEditor\Builder).
 *
 * Data: `name`, `kind`, `slug`, `stylesheet`, `theme`, `area`, `source`
 * and `blocks` (how many blocks it has).
 *
 * @package Scanfully\Events
 */
class TemplateCreated extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'TemplateCreated' );
	}
}
