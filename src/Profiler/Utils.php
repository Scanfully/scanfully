<?php

namespace Scanfully\Profiler;

/**
 * Utils class.
 * A collection of utility functions, mostly forked from WP-CLI.
 * https://github.com/wp-cli/wp-cli
 */
class Utils {

	// Byte order marks for UTF-8, UTF-16 (BE), and UTF-16 (LE).
	const BYTE_ORDER_MARKS = [
		'UTF-8'       => "\xEF\xBB\xBF",
		'UTF-16 (BE)' => "\xFE\xFF",
		'UTF-16 (LE)' => "\xFF\xFE",
	];

	/**
	 * Regular expression pattern to match __FILE__ and __DIR__ constants.
	 *
	 * We try to be smart and only replace the constants when they are not within quotes.
	 * Regular expressions being stateless, this is probably not 100% correct for edge cases.
	 *
	 * @see https://regex101.com/r/9hXp5d/11
	 * @see https://stackoverflow.com/a/171499/933065
	 *
	 * @var string
	 */
	const FILE_DIR_PATTERN = '%(?>#.*?$)|(?>//.*?$)|(?>/\*.*?\*/)|(?>\'(?:(?=(\\\\?))\1.)*?\')|(?>"(?:(?=(\\\\?))\2.)*?")|(?<file>\b__FILE__\b)|(?<dir>\b__DIR__\b)%ms';


	/**
	 * Gets path to WordPress configuration.
	 *
	 * @return string|null
	 */
	public static function locate_wp_config(): ?string {
		$path = null;
		if ( file_exists( $_SERVER['DOCUMENT_ROOT'] . '/wp-config.php' ) ) {

			/** The config file resides in ABSPATH */
			$path = $_SERVER['DOCUMENT_ROOT'] . '/wp-config.php';

		} elseif ( @file_exists( dirname( $_SERVER['DOCUMENT_ROOT'] ) . '/wp-config.php' ) && ! @file_exists( dirname( $_SERVER['DOCUMENT_ROOT'] ) . '/wp-settings.php' ) ) {

			/** The config file resides one level above ABSPATH but is not part of another installation */
			$path = dirname( $_SERVER['DOCUMENT_ROOT'] ) . '/wp-config.php';
		}

		return $path;
	}

	/**
	 * Returns wp-config.php code, skipping the loading of wp-settings.php.
	 *
	 * @param  string $wp_config_path Optional. Config file path. If left empty, it tries to
	 *                               locate the wp-config.php file automatically.
	 *
	 * @return string
	 */
	public static function get_wp_config_code( $wp_config_path = '' ) {
		if ( empty( $wp_config_path ) ) {
			$wp_config_path = self::locate_wp_config();
		}

		if ( empty( $wp_config_path ) ) {
			die( 'no wp-config.php found' );
		}

		$wp_config_code = explode( "\n", file_get_contents( $wp_config_path ) );

		// Detect and strip byte-order marks (BOMs).
		// This code assumes they can only be found on the first line.
		foreach ( self::BYTE_ORDER_MARKS as $bom_name => $bom_sequence ) {
			$length = strlen( $bom_sequence );
			while ( substr( $wp_config_code[0], 0, $length ) === $bom_sequence ) {
				$wp_config_code[0] = substr( $wp_config_code[0], $length );
			}
		}

		$found_wp_settings = false;

		$lines_to_run = [];

		foreach ( $wp_config_code as $line ) {
			if ( preg_match( '/^\s*require.+wp-settings\.php/', $line ) ) {
				$found_wp_settings = true;
				continue;
			}

			$lines_to_run[] = $line;
		}

		if ( ! $found_wp_settings ) {
			WP_CLI::error( 'Strange wp-config.php file: wp-settings.php is not loaded directly.' );
		}

		$source = implode( "\n", $lines_to_run );

		// @todo: check if this is needed in our runtime env as well, or only in CLI env
		$source = self::replace_path_consts( $source, $wp_config_path );

		return preg_replace( '|^\s*\<\?php\s*|', '', $source );
	}

	/**
	 * Replace magic constants in some PHP source code.
	 *
	 * Replaces the __FILE__ and __DIR__ magic constants with the values they are
	 * supposed to represent at runtime.
	 *
	 * @param string $source The PHP code to manipulate.
	 * @param string $path The path to use instead of the magic constants.
	 * @return string Adapted PHP code.
	 */
	private static function replace_path_consts( $source, $path ) {
		// Solve issue with Windows allowing single quotes in account names.
		$file = addslashes( $path );

		if ( file_exists( $file ) ) {
			$file = realpath( $file );
		}

		$dir = dirname( $file );

		// Replace __FILE__ and __DIR__ constants with value of $file or $dir.
		return preg_replace_callback(
			self::FILE_DIR_PATTERN,
			static function ( $matches ) use ( $file, $dir ) {
				if ( ! empty( $matches['file'] ) ) {
					return "'{$file}'";
				}

				if ( ! empty( $matches['dir'] ) ) {
					return "'{$dir}'";
				}

				return $matches[0];
			},
			$source
		);
	}

}