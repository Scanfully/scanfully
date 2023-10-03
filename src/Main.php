<?php

namespace Scanfully;


class Main {

	/** @var ?Main */
	private static $instance = null;

	/**
	 * Singleton getter
	 *
	 * @return Main|null
	 */
	public static function get(): ?Main {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function setup(): void {
		/** register all events */
		Events\Controller::register( new Events\ActivatedPlugin() ); // when a plugin is activated
		Events\Controller::register( new Events\DeactivatedPlugin() ); // when a plugin is deactivated
		Events\Controller::register( new Events\RewriteRules() ); // when new rewrite rules are saved

		/** register options */
		Options\Options::register();
		Options\Page::register();

		if(isset($_GET['healthtest'])) {
			Health\Controller::send_health_request();
			wp_die("request sent");
		}
	}

}