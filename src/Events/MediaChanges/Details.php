<?php
/**
 * The media details class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\MediaChanges;

use Scanfully\Events\Content\Summary;

/**
 * Class Details
 *
 * What a media event says about a file: its name and place in the uploads
 * folder, title, type, size, dimensions and length, and the post it is
 * attached to. Read in the background job, never while the file is saved.
 *
 * @package Scanfully\Events\MediaChanges
 */
final class Details {

	/**
	 * Load the posts and meta of many files in two queries, before they are
	 * described one by one.
	 *
	 * @param array<int, int> $ids The attachment IDs.
	 *
	 * @return void
	 */
	public static function prime( array $ids ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( [] === $ids ) {
			return;
		}

		_prime_post_caches( $ids, false, true );
	}

	/**
	 * A file as it is now; null when it no longer exists or isn't a file in
	 * the media library.
	 *
	 * @param int $id The attachment ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function describe( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return null;
		}

		$file = (string) get_post_meta( $id, '_wp_attached_file', true );
		$data = [
			'id'        => $id,
			'name'      => self::name( $file, (string) $post->guid ),
			'file'      => $file,
			'title'     => self::plain( (string) $post->post_title ),
			'mime_type' => (string) $post->post_mime_type,
		];

		$meta  = wp_get_attachment_metadata( $id, true );
		$facts = is_array( $meta ) ? self::facts( $meta ) : [];
		if ( ! isset( $facts['filesize'] ) ) {
			$path = '' !== $file ? get_attached_file( $id, true ) : false;
			if ( is_string( $path ) && is_file( $path ) ) {
				$facts['filesize'] = (int) wp_filesize( $path );
			}
		}

		return array_merge( $data, $facts );
	}

	/**
	 * The size, dimensions and length of a file from its metadata, as far
	 * as WordPress knows them.
	 *
	 * @param array<string, mixed> $meta The attachment metadata.
	 *
	 * @return array<string, int>
	 */
	public static function facts( array $meta ): array {
		$facts = [];
		foreach ( [ 'filesize', 'width', 'height', 'length' ] as $key ) {
			if ( isset( $meta[ $key ] ) && is_numeric( $meta[ $key ] ) && (int) $meta[ $key ] > 0 ) {
				$facts[ $key ] = (int) $meta[ $key ];
			}
		}

		return $facts;
	}

	/**
	 * The file name of a file in the uploads folder, or else the one in its
	 * URL.
	 *
	 * @param string $file The file, relative to the uploads folder.
	 * @param string $url  The file's URL.
	 *
	 * @return string
	 */
	public static function name( string $file, string $url = '' ): string {
		if ( '' !== $file ) {
			return wp_basename( $file );
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return '' !== $path ? rawurldecode( wp_basename( $path ) ) : '';
	}

	/**
	 * A post files are attached to: ID, title and the post type's name on
	 * the site. The title is '' for none (0) or a post that is gone.
	 *
	 * @param int $id The post ID.
	 *
	 * @return array{id: int, title: string, type_label?: string}
	 */
	public static function parent( int $id ): array {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return [
				'id'    => $id,
				'title' => '',
			];
		}

		$type = get_post_type_object( $post->post_type );

		return [
			'id'         => $id,
			'title'      => Summary::title( (string) $post->post_title ),
			'type_label' => is_object( $type ) && isset( $type->labels->singular_name ) ? self::plain( (string) $type->labels->singular_name ) : (string) $post->post_type,
		];
	}

	/**
	 * Plain text from a value WordPress stores HTML-encoded.
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 */
	private static function plain( string $value ): string {
		return html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
	}
}
