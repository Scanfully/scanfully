<?php
/**
 * The theme settings change builder class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\ThemeSettings;

use Scanfully\Events\PostChanges\ContentDiff;

/**
 * Class Builder
 *
 * Completes a ThemeSettingsChanged row in the background job: the theme's
 * name, the file names of the logo and of images and videos, and the
 * Additional CSS as added and removed lines.
 *
 * @package Scanfully\Events\ThemeSettings
 */
final class Builder {

	/**
	 * The row with names and the CSS diff; [] when nothing is left.
	 *
	 * @param array<string, mixed> $data The row as the Collector noted it.
	 *
	 * @return array<string, mixed>
	 */
	public static function resolve( array $data ): array {
		$stylesheet = (string) ( $data['stylesheet'] ?? '' );
		$theme      = '' !== $stylesheet ? wp_get_theme( $stylesheet ) : null;
		$name       = is_object( $theme ) && $theme->exists() ? self::plain( $theme->get( 'Name' ) ) : '';
		$data['name'] = '' !== $name ? $name : $stylesheet;

		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			foreach ( $data['settings'] as $index => $setting ) {
				if ( is_array( $setting ) && 'media' === ( $setting['kind'] ?? '' ) ) {
					$data['settings'][ $index ] = self::with_files( $setting );
				} elseif ( is_array( $setting ) && 'page' === ( $setting['kind'] ?? '' ) ) {
					$data['settings'][ $index ] = self::with_page_titles( $setting );
				}
			}
		}

		if ( isset( $data['logo'] ) && is_array( $data['logo'] ) ) {
			$data['logo'] = [
				'old' => self::attachment( (string) ( $data['logo']['old'] ?? '' ) ),
				'new' => self::attachment( (string) ( $data['logo']['new'] ?? '' ) ),
			];
		}

		if ( isset( $data['css'] ) && is_array( $data['css'] ) ) {
			$css = $data['css'];
			if ( isset( $css['stash'] ) && is_array( $css['stash'] ) ) {
				$data['css'] = ContentDiff::compare_code( (string) ( $css['stash']['old'] ?? '' ), (string) ( $css['stash']['new'] ?? '' ) );
			} else {
				$data['css'] = ContentDiff::unchanged_text( ! empty( $css['too_large'] ) ? 'too_large' : 'unavailable' );
			}
		}

		return isset( $data['settings'] ) || isset( $data['logo'] ) || isset( $data['css'] ) ? $data : [];
	}

	/**
	 * A setting with the file names of its old and new image or video.
	 *
	 * @param array<string, mixed> $setting The setting's row.
	 *
	 * @return array<string, mixed>
	 */
	private static function with_files( array $setting ): array {
		foreach ( [ 'old', 'new' ] as $side ) {
			if ( ! isset( $setting[ $side ] ) ) {
				continue;
			}
			$file = self::file_name( (string) $setting[ $side ] );
			if ( '' !== $file ) {
				$setting[ $side . '_file' ] = $file;
			}
		}

		return $setting;
	}

	/**
	 * A setting with the titles of its old and new page (a page dropdown;
	 * 0 is no page).
	 *
	 * @param array<string, mixed> $setting The setting's row.
	 *
	 * @return array<string, mixed>
	 */
	private static function with_page_titles( array $setting ): array {
		foreach ( [ 'old', 'new' ] as $side ) {
			$id   = (string) ( $setting[ $side ] ?? '' );
			$post = ctype_digit( $id ) && (int) $id > 0 ? get_post( (int) $id ) : null;
			if ( $post instanceof \WP_Post ) {
				$setting[ $side . '_name' ] = self::plain( $post->post_title );
			}
		}

		return $setting;
	}

	/**
	 * An attachment by ID and file name; empty for none.
	 *
	 * @param string $id The attachment ID.
	 *
	 * @return array{id: string, file: string}
	 */
	private static function attachment( string $id ): array {
		return [
			'id'   => $id,
			'file' => ctype_digit( $id ) && (int) $id > 0 ? self::file_name( $id ) : '',
		];
	}

	/**
	 * The file name of an attachment ID or an upload URL; '' for anything
	 * else (`remove-header`, a random header).
	 *
	 * @param string $value The ID or URL.
	 *
	 * @return string
	 */
	public static function file_name( string $value ): string {
		if ( ctype_digit( $value ) && (int) $value > 0 ) {
			$file = get_attached_file( (int) $value );

			return is_string( $file ) && '' !== $file ? wp_basename( $file ) : '';
		}

		if ( 1 !== preg_match( '#^https?://#i', $value ) ) {
			return '';
		}
		$path = (string) wp_parse_url( $value, PHP_URL_PATH );

		return '' !== $path ? wp_basename( $path ) : '';
	}

	/**
	 * Plain text from a value WordPress stores HTML-encoded.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function plain( $value ): string {
		return html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}
