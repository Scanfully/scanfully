<?php

namespace Scanfully\Events;

class Controller {

	private static $events = [];

	public static function register( Event $event ) : void {
		self::$events[] = $event;
	}

}