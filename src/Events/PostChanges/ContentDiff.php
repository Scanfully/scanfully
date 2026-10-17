<?php
/**
 * The content diff class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\PostChanges;

/**
 * Class ContentDiff
 *
 * A short, readable diff of a post's content: block markup and HTML become
 * plain text with one line per block or paragraph, the lines are compared
 * with WordPress's bundled Text_Diff, and only the changed lines are kept,
 * with one line of context around each change. Runs in the background job.
 *
 * @package Scanfully\Events\PostChanges
 */
final class ContentDiff {

	/**
	 * Content larger than this (per side, in bytes) is not compared.
	 */
	public const MAX_CONTENT = 300 * 1024;

	/**
	 * Content with more lines than this (per side) is not compared.
	 */
	public const MAX_LINES = 5000;

	/**
	 * The most text kept in the diff lines, in bytes.
	 */
	public const MAX_DIFF = 8192;

	/**
	 * The longest line kept, in characters.
	 */
	public const MAX_LINE = 500;

	/**
	 * Compare two versions of a post's content.
	 *
	 * @param string        $before The content before.
	 * @param string        $after  The content after.
	 * @param callable|null $differ Compares two lists of lines and returns operations
	 *                              (`['=', lines]`, `['-', lines]`, `['+', lines]`);
	 *                              WordPress's Text_Diff when null.
	 *
	 * @return array<string, mixed>
	 */
	public static function compare( string $before, string $after, ?callable $differ = null ): array {
		if ( strlen( $before ) > self::MAX_CONTENT || strlen( $after ) > self::MAX_CONTENT ) {
			return self::unchanged_text( 'too_large' );
		}

		$old_lines = self::text_lines( $before );
		$new_lines = self::text_lines( $after );
		if ( count( $old_lines ) > self::MAX_LINES || count( $new_lines ) > self::MAX_LINES ) {
			return self::unchanged_text( 'too_large' );
		}

		// Only markup changed (a class, an attribute, a block setting).
		if ( $old_lines === $new_lines ) {
			return self::unchanged_text( 'markup' );
		}

		$operations = null === $differ ? self::text_diff( $old_lines, $new_lines ) : $differ( $old_lines, $new_lines );

		return self::summarize( $operations );
	}

	/**
	 * A content change without a diff, and why.
	 *
	 * @param string $reason Why there is no diff.
	 *
	 * @return array<string, mixed>
	 */
	public static function unchanged_text( string $reason ): array {
		return [
			'changed' => true,
			'reason'  => $reason,
		];
	}

	/**
	 * Post content as plain text, one line per block, paragraph or list
	 * item. Images are named by their file.
	 *
	 * @param string $content The content.
	 *
	 * @return string[]
	 */
	public static function text_lines( string $content ): array {
		$content = (string) preg_replace( '/<!--\s*\/?wp:.*?-->/s', "\n", $content );
		$content = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $content );
		$content = (string) preg_replace_callback( '/<img\b[^>]*>/i', [ self::class, 'image_text' ], $content );
		$content = (string) preg_replace( '#<br\s*/?>|</(p|div|h[1-6]|li|blockquote|figure|figcaption|tr|td|th|pre|section|article|ul|ol|table|dt|dd)\s*>#i', "\n", $content );
		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );

		$lines = [];
		$split = preg_split( '/\R/u', $content );
		foreach ( false !== $split ? $split : [] as $line ) {
			$line = trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return $lines;
	}

	/**
	 * An image tag as text: `[image: photo.jpg]`.
	 *
	 * @param array<int, string> $matches The image tag.
	 *
	 * @return string
	 */
	private static function image_text( array $matches ): string {
		if ( preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/i', $matches[0], $src ) ) {
			$path = (string) wp_parse_url( $src[2], PHP_URL_PATH );
			$file = '' !== $path ? basename( $path ) : '';
			if ( '' !== $file ) {
				return "\n[image: " . $file . "]\n";
			}
		}

		return "\n[image]\n";
	}

	/**
	 * Compare two lists of lines with WordPress's Text_Diff.
	 *
	 * @param string[] $old_lines The lines before.
	 * @param string[] $new_lines The lines after.
	 *
	 * @return array<int, array{0: string, 1: string[]}>
	 */
	private static function text_diff( array $old_lines, array $new_lines ): array {
		if ( ! class_exists( 'Text_Diff', false ) ) {
			require_once ABSPATH . 'wp-includes/wp-diff.php';
		}

		$operations = [];
		$diff       = new \Text_Diff( 'auto', [ $old_lines, $new_lines ] );
		foreach ( $diff->getDiff() as $edit ) {
			if ( $edit instanceof \Text_Diff_Op_copy ) {
				$operations[] = [ '=', (array) $edit->orig ];
			} elseif ( $edit instanceof \Text_Diff_Op_delete ) {
				$operations[] = [ '-', (array) $edit->orig ];
			} elseif ( $edit instanceof \Text_Diff_Op_add ) {
				$operations[] = [ '+', (array) $edit->final ];
			} elseif ( $edit instanceof \Text_Diff_Op_change ) {
				$operations[] = [ '-', (array) $edit->orig ];
				$operations[] = [ '+', (array) $edit->final ];
			}
		}

		return $operations;
	}

	/**
	 * The changed lines with one line of context around each change, the
	 * unchanged lines in between as a count, and the number of lines added
	 * and removed.
	 *
	 * @param array<int, array{0: string, 1: string[]}> $operations The diff.
	 *
	 * @return array<string, mixed>
	 */
	public static function summarize( array $operations ): array {
		$added     = 0;
		$removed   = 0;
		$lines     = [];
		$size      = 0;
		$truncated = false;
		$last      = count( $operations ) - 1;

		$keep = static function ( array $line ) use ( &$lines, &$size, &$truncated ): void {
			if ( $truncated ) {
				return;
			}
			$length = isset( $line['text'] ) ? strlen( $line['text'] ) : 0;
			if ( $size + $length > self::MAX_DIFF ) {
				$truncated = true;
				return;
			}
			$size   += $length;
			$lines[] = $line;
		};

		foreach ( $operations as $index => $operation ) {
			[ $op, $chunk ] = $operation;
			$chunk          = array_values( $chunk );

			if ( '=' !== $op ) {
				foreach ( $chunk as $text ) {
					if ( '+' === $op ) {
						++$added;
					} else {
						++$removed;
					}
					$keep(
						[
							'op'   => $op,
							'text' => self::cut( (string) $text ),
						]
					);
				}
				continue;
			}

			// One line of context after the change before and before the next.
			$count = count( $chunk );
			$head  = $index > 0 ? 1 : 0;
			$tail  = $index < $last ? 1 : 0;
			if ( $count <= $head + $tail ) {
				$head = $count;
				$tail = 0;
			}

			for ( $i = 0; $i < $head; $i++ ) {
				$keep(
					[
						'op'   => '=',
						'text' => self::cut( (string) $chunk[ $i ] ),
					]
				);
			}
			$skipped = $count - $head - $tail;
			if ( $skipped > 0 ) {
				$keep(
					[
						'op'    => 'skip',
						'count' => $skipped,
					]
				);
			}
			if ( $tail > 0 ) {
				$keep(
					[
						'op'   => '=',
						'text' => self::cut( (string) $chunk[ $count - 1 ] ),
					]
				);
			}
		}

		return [
			'added'     => $added,
			'removed'   => $removed,
			'lines'     => $lines,
			'truncated' => $truncated,
		];
	}

	/**
	 * A line cut to the longest kept.
	 *
	 * @param string $text The line.
	 *
	 * @return string
	 */
	private static function cut( string $text ): string {
		return mb_strlen( $text ) > self::MAX_LINE ? mb_substr( $text, 0, self::MAX_LINE ) . '…' : $text;
	}
}
