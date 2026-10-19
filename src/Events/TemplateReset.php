<?php
/**
 * The TemplateReset event class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events;

/**
 * Class TemplateReset
 *
 * A customized template or template part was reset to the theme's or
 * plugin's version. The job of a provisional TemplateDeleted row reports it
 * (SiteEditor\Builder).
 *
 * Data: as TemplateChanged, with `changes.blocks` comparing the customized
 * version with the theme's.
 *
 * @package Scanfully\Events
 */
class TemplateReset extends ContentEvent {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'TemplateReset' );
	}
}
