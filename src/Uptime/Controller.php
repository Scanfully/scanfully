<?php

namespace Scanfully\Uptime;

class Controller {

	/**
	 * Set up the controller.
	 *
	 * @return void
	 */
	public static function setup(): void {
		if ( ! self::is_uptime_request() ) {
			return;
		}

		self::setup_hook( 'init', 'MAIN:START', false );
		self::setup_hook( 'wp_head', 'HEADER', true );
		self::setup_hook( 'wp_footer', 'FOOTER', true );
		self::setup_hook( 'shutdown', 'MAIN:END', false );
	}

	/**
	 * @param  string $hook
	 * @param  string $tag
	 * @param  bool $dual
	 *
	 * @return void
	 */
	public static function setup_hook( string $hook, string $tag, bool $dual ): void {

		if ( $dual ) {
			add_action( $hook, function () use ( $tag ) {
				echo self::tag( $tag, 'START' );
			}, - 99999 );

			add_action( $hook, function () use ( $tag ) {
				echo self::tag( $tag, 'END' );
			}, 99999 );
		} else {
			add_action( $hook, function () use ( $tag ) {
				echo self::tag( $tag );
			}, - 99999 );
		}

	}

	/**
	 * @return bool
	 */
	private static function is_uptime_request(): bool {
		return isset( $_GET['scanfully_uptime_check'] );
	}

	/**
	 * @param  string $tag
	 * @param  string|null $post_tag
	 *
	 * @return string
	 */
	private static function tag( string $tag, ?string $post_tag = null ): string {
		return "<!-- SCANFULLY:" . strtoupper( $tag ) . ( $post_tag ? ":" . $post_tag : "" ) . " -->\n";
	}
}