<?php

namespace Scanfully\Events;

/**
 * Class Controller
 *
 * @package Scanfully\Events
 */
class Controller {

	/**
	 * The events
	 *
	 * @var array
	 */
	private static $events = [];

	/**
	 * Register the events
	 *
	 * @param  Event $event The event to register.
	 *
	 * @return void
	 */
	public static function register( Event $event ): void {
		self::$events[] = $event;
	}
}
