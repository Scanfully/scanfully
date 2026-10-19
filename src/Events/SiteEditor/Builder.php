<?php
/**
 * The Site Editor builder class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\SiteEditor;

use Scanfully\Events\PostChanges\ContentDiff;

/**
 * Class Builder
 *
 * Completes a Site Editor row in the background job:
 * - styles: the theme's name, each style setting that changed with its
 *   value before and after, a style variation picked, a reset, and the
 *   Additional CSS as added and removed lines;
 * - templates and template parts: the theme's or plugin's own version, so a
 *   first save is told from a new template and a delete from a reset, and a
 *   block outline diff (one line per block).
 *
 * @package Scanfully\Events\SiteEditor
 */
final class Builder {

	/**
	 * The most style settings a row lists.
	 */
	public const MAX_PATHS = 50;

	/**
	 * The longest value kept as is; a longer one is only "changed".
	 */
	public const MAX_VALUE_LENGTH = 200;

	/**
	 * The most lines a block outline has.
	 */
	private const MAX_OUTLINE_LINES = 2000;

	/**
	 * The longest text excerpt of a block in its outline line.
	 */
	private const MAX_TEXT = 80;

	/**
	 * The longest attributes of a block in its outline line.
	 */
	private const MAX_ATTRIBUTES = 150;

	/**
	 * The title core gives the global styles post; any other title is the
	 * style variation picked.
	 */
	private const DEFAULT_STYLES_TITLE = 'Custom Styles';

	/**
	 * Attributes that say which item a block shows, per block; they come
	 * first in its outline line.
	 */
	private const IDENTIFIERS = [
		'core/template-part' => 'slug',
		'core/pattern'       => 'slug',
		'core/block'         => 'ref',
		'core/navigation'    => 'ref',
	];

	/**
	 * The row with what changed; [] when nothing is left to report.
	 *
	 * @param string               $type The type the request reported.
	 * @param array<string, mixed> $data The row as the Collector noted it.
	 *
	 * @return array<string, mixed>
	 */
	public static function resolve( string $type, array $data ): array {
		return 'StylesChanged' === $type ? self::styles( $data ) : self::template( $data );
	}

	/**
	 * A styles row.
	 *
	 * @param array<string, mixed> $data The row as the Collector noted it.
	 *
	 * @return array<string, mixed>
	 */
	private static function styles( array $data ): array {
		$stylesheet = (string) ( $data['stylesheet'] ?? '' );
		$row        = [
			'stylesheet' => $stylesheet,
			'name'       => self::theme_name( $stylesheet ),
		];

		$content = is_array( $data['content'] ?? null ) ? $data['content'] : [];
		if ( ! isset( $content['stash'] ) || ! is_array( $content['stash'] ) ) {
			$row['changes'] = [ 'reason' => ! empty( $content['too_large'] ) ? 'too_large' : 'unavailable' ];
			return $row;
		}

		$old = self::config( (string) ( $content['stash']['old'] ?? '' ) );
		$new = self::config( (string) ( $content['stash']['new'] ?? '' ) );
		// A client may send the style variation's title, which the REST
		// endpoint stores as the post's title.
		if ( isset( $data['title'] ) && is_array( $data['title'] ) ) {
			foreach ( [ 'old', 'new' ] as $side ) {
				$title = (string) ( $data['title'][ $side ] ?? '' );
				if ( '' !== $title && self::DEFAULT_STYLES_TITLE !== $title ) {
					${$side}['title'] = $title;
				}
			}
		}
		// The Site Editor itself stores only the variation's styles and
		// settings; it is found by comparing them with the theme's.
		if ( ! isset( $new['title'] ) && $stylesheet === get_stylesheet() && class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			$variations = \WP_Theme_JSON_Resolver::get_style_variations();
			$variation  = self::matching_variation( $old, $new, $variations );
			if ( '' !== $variation ) {
				$new['title'] = $variation;
				$before       = self::variation_in( $old, $variations );
				if ( '' !== $before && ! isset( $old['title'] ) ) {
					$old['title'] = $before;
				}
			}
		}

		$changes = self::style_changes( $old, $new );
		if ( [] === $changes ) {
			return [];
		}
		$row['changes'] = $changes;

		return $row;
	}

	/**
	 * The title of the theme's style variation the new styles took on: one
	 * whose every setting the new styles have and the old ones didn't all
	 * have. The one with the most settings wins, so a whole variation is
	 * named rather than the colour or typography preset inside it; '' for
	 * none.
	 *
	 * @param array<string, mixed>             $old        The config before.
	 * @param array<string, mixed>             $new        The config after.
	 * @param array<int, array<string, mixed>> $variations The theme's style variations.
	 *
	 * @return string
	 */
	public static function matching_variation( array $old, array $new, array $variations ): string {
		$old_paths = self::paths( $old );
		$new_paths = self::paths( $new );
		$best      = '';
		$most      = 0;
		foreach ( $variations as $variation ) {
			$title = is_array( $variation ) && is_string( $variation['title'] ?? null ) ? $variation['title'] : '';
			$paths = '' !== $title ? self::paths( $variation ) : [];
			if ( [] === $paths || count( $paths ) <= $most ) {
				continue;
			}
			if ( array_intersect_assoc( $paths, $new_paths ) !== $paths || array_intersect_assoc( $paths, $old_paths ) === $paths ) {
				continue;
			}
			$best = $title;
			$most = count( $paths );
		}

		return self::plain( $best );
	}

	/**
	 * The title of the theme's style variation a config holds in full, the
	 * one with the most settings; '' for none.
	 *
	 * @param array<string, mixed>             $config     The config.
	 * @param array<int, array<string, mixed>> $variations The theme's style variations.
	 *
	 * @return string
	 */
	public static function variation_in( array $config, array $variations ): string {
		$paths = self::paths( $config );
		$best  = '';
		$most  = 0;
		foreach ( $variations as $variation ) {
			$title    = is_array( $variation ) && is_string( $variation['title'] ?? null ) ? $variation['title'] : '';
			$settings = '' !== $title ? self::paths( $variation ) : [];
			if ( [] !== $settings && count( $settings ) > $most && array_intersect_assoc( $settings, $paths ) === $settings ) {
				$best = $title;
				$most = count( $settings );
			}
		}

		return self::plain( $best );
	}

	/**
	 * A global styles config decoded; [] for none or not JSON.
	 *
	 * @param string $json The post content.
	 *
	 * @return array<string, mixed>
	 */
	private static function config( string $json ): array {
		$config = json_decode( '' !== $json ? $json : '{}', true );

		return is_array( $config ) ? $config : [];
	}

	/**
	 * What changed between two global styles configs.
	 *
	 * @param array<string, mixed> $old The config before.
	 * @param array<string, mixed> $new The config after.
	 *
	 * @return array<string, mixed>
	 */
	public static function style_changes( array $old, array $new ): array {
		$changes = [];

		$old_title = is_string( $old['title'] ?? null ) ? $old['title'] : '';
		$new_title = is_string( $new['title'] ?? null ) ? $new['title'] : '';
		if ( $old_title !== $new_title && '' !== $new_title ) {
			$changes['variation'] = [
				'old' => self::plain( $old_title ),
				'new' => self::plain( $new_title ),
			];
		}

		if ( Collector::is_empty_styles( $new ) && ! Collector::is_empty_styles( $old ) ) {
			$changes['reset'] = true;
			return $changes;
		}

		$old_css = self::css( $old );
		$new_css = self::css( $new );
		if ( $old_css !== $new_css ) {
			$changes['css'] = ContentDiff::compare_code( $old_css, $new_css );
		}

		$old_paths = self::paths( $old );
		$new_paths = self::paths( $new );
		$styles    = [];
		$more      = 0;
		foreach ( array_keys( $old_paths + $new_paths ) as $path ) {
			$before = $old_paths[ $path ] ?? null;
			$after  = $new_paths[ $path ] ?? null;
			if ( $before === $after ) {
				continue;
			}
			if ( count( $styles ) >= self::MAX_PATHS ) {
				++$more;
				continue;
			}
			$styles[] = self::path_change( (string) $path, $before, $after );
		}
		if ( [] !== $styles ) {
			$changes['styles'] = $styles;
		}
		if ( $more > 0 ) {
			$changes['more'] = $more;
		}

		return $changes;
	}

	/**
	 * One style setting's change.
	 *
	 * @param string      $path   The setting's path.
	 * @param string|null $before The value before; null when it wasn't set.
	 * @param string|null $after  The value after; null when it was removed.
	 *
	 * @return array<string, mixed>
	 */
	private static function path_change( string $path, ?string $before, ?string $after ): array {
		$change = [
			'path'   => $path,
			'change' => null === $before ? 'added' : ( null === $after ? 'removed' : 'changed' ),
		];
		foreach ( [
			'old' => $before,
			'new' => $after,
		] as $side => $value ) {
			if ( null === $value ) {
				continue;
			}
			if ( mb_strlen( $value ) > self::MAX_VALUE_LENGTH ) {
				$change['large'] = true;
			} else {
				$change[ $side ] = $value;
			}
		}

		return $change;
	}

	/**
	 * Every style setting of a config as a path and its value, without the
	 * Additional CSS (compared on its own). Lists, such as a colour palette,
	 * are one value.
	 *
	 * @param array<string, mixed> $config The config.
	 *
	 * @return array<string, string>
	 */
	private static function paths( array $config ): array {
		$paths = [];
		foreach ( [ 'styles', 'settings' ] as $root ) {
			if ( isset( $config[ $root ] ) && is_array( $config[ $root ] ) ) {
				self::flatten( $config[ $root ], $root, $paths );
			}
		}

		return $paths;
	}

	/**
	 * Add the leaves of a tree to the paths.
	 *
	 * @param array<mixed>          $tree   The tree.
	 * @param string                $prefix The path so far.
	 * @param array<string, string> $paths  The paths found.
	 *
	 * @return void
	 */
	private static function flatten( array $tree, string $prefix, array &$paths ): void {
		foreach ( $tree as $key => $value ) {
			$path = $prefix . '.' . $key;
			if ( 'css' === $key && 0 === strpos( $prefix, 'styles' ) ) {
				continue;
			}
			if ( is_array( $value ) && [] !== $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				self::flatten( $value, $path, $paths );
				continue;
			}
			if ( is_bool( $value ) ) {
				$paths[ $path ] = $value ? 'true' : 'false';
			} elseif ( is_scalar( $value ) || null === $value ) {
				$paths[ $path ] = (string) $value;
			} else {
				$paths[ $path ] = (string) wp_json_encode( $value );
			}
		}
	}

	/**
	 * A config's Additional CSS: the global CSS and each block's, under a
	 * comment naming the block.
	 *
	 * @param array<string, mixed> $config The config.
	 *
	 * @return string
	 */
	private static function css( array $config ): string {
		$parts = [];
		if ( is_string( $config['styles']['css'] ?? null ) && '' !== trim( $config['styles']['css'] ) ) {
			$parts[] = $config['styles']['css'];
		}
		$blocks = $config['styles']['blocks'] ?? null;
		if ( is_array( $blocks ) ) {
			foreach ( $blocks as $block => $styles ) {
				if ( is_array( $styles ) && is_string( $styles['css'] ?? null ) && '' !== trim( $styles['css'] ) ) {
					$parts[] = '/* ' . $block . " */\n" . $styles['css'];
				}
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * A template row: what the request saw, completed with the theme's or
	 * plugin's own version. A first save of a template the theme or a plugin
	 * has is its first customization; of any other a new template
	 * (TemplateCreated). A delete of one the theme or a plugin still has is
	 * a reset (TemplateReset); of any other a delete.
	 *
	 * @param array<string, mixed> $data The row as the Collector noted it.
	 *
	 * @return array<string, mixed>
	 */
	private static function template( array $data ): array {
		$kind       = 'template_part' === ( $data['kind'] ?? '' ) ? 'template_part' : 'template';
		$post_type  = 'template_part' === $kind ? 'wp_template_part' : 'wp_template';
		$slug       = (string) ( $data['slug'] ?? '' );
		$post_id    = (int) ( $data['post_id'] ?? 0 );
		$post       = $post_id > 0 ? get_post( $post_id ) : null;
		$post       = $post instanceof \WP_Post ? $post : null;
		$stylesheet = (string) ( $data['stylesheet'] ?? '' );
		if ( '' === $stylesheet ) {
			$stylesheet = self::term_name( $post, 'wp_theme' );
		}
		if ( '' === $stylesheet ) {
			$stylesheet = (string) get_stylesheet();
		}

		$provided = self::provided( $stylesheet, $slug, $post_type );
		$title    = trim( (string) ( $data['title'] ?? '' ) );
		if ( '' === $title && null !== $provided ) {
			$title = $provided['title'];
		}

		$row = [
			'name'       => self::plain( '' !== $title ? $title : $slug ),
			'kind'       => $kind,
			'slug'       => $slug,
			'stylesheet' => $stylesheet,
			'theme'      => self::theme_name( $stylesheet ),
			'source'     => null !== $provided ? $provided['source'] : 'custom',
		];
		if ( 'template_part' === $kind ) {
			$area = (string) ( $data['area'] ?? '' );
			if ( '' === $area ) {
				$area = self::term_name( $post, 'wp_template_part_area' );
			}
			if ( '' !== $area ) {
				$row['area'] = $area;
			}
		}

		$content = is_array( $data['content'] ?? null ) ? $data['content'] : [];
		$stash   = is_array( $content['stash'] ?? null ) ? $content['stash'] : null;
		$old     = null !== $stash ? (string) ( $stash['old'] ?? '' ) : null;
		$new     = null !== $stash ? (string) ( $stash['new'] ?? '' ) : null;

		if ( ! empty( $data['removed'] ) ) {
			if ( null === $provided ) {
				return $row;
			}
			$row['_type']   = 'TemplateReset';
			$row['changes'] = [ 'blocks' => null !== $old ? self::blocks_diff( $old, $provided['content'] ) : self::no_diff( $content ) ];
			return $row;
		}

		if ( ! empty( $data['inserted'] ) ) {
			if ( null === $provided ) {
				$row['_type']  = 'TemplateCreated';
				$row['blocks'] = null !== $new ? count( self::outline( $new, 0 ) ) : 0;
				return $row;
			}
			$row['first_customization'] = true;
			$changes                    = [ 'blocks' => null !== $new ? self::blocks_diff( $provided['content'], $new ) : self::no_diff( $content ) ];
			if ( '' !== $title && $title !== $provided['title'] ) {
				$changes['title'] = [
					'old' => self::plain( $provided['title'] ),
					'new' => self::plain( $title ),
				];
			}
			$row['changes'] = $changes;
			return $row;
		}

		$changes = [];
		$noted   = is_array( $data['changes'] ?? null ) ? $data['changes'] : [];
		foreach ( [ 'title', 'description' ] as $field ) {
			if ( isset( $noted[ $field ] ) && is_array( $noted[ $field ] ) ) {
				$changes[ $field ] = [
					'old' => self::plain( $noted[ $field ]['old'] ?? '' ),
					'new' => self::plain( $noted[ $field ]['new'] ?? '' ),
				];
			}
		}
		if ( isset( $noted['area'] ) && is_array( $noted['area'] ) ) {
			$changes['area'] = [
				'old' => self::area_name( (array) ( $noted['area']['old'] ?? [] ) ),
				'new' => self::area_name( (array) ( $noted['area']['new'] ?? [] ) ),
			];
		}
		if ( [] !== $content ) {
			$changes['blocks'] = null !== $stash ? self::blocks_diff( (string) $old, (string) $new ) : self::no_diff( $content );
		}
		if ( [] === $changes ) {
			return [];
		}
		$row['changes'] = $changes;

		return $row;
	}

	/**
	 * The template or template part a theme or plugin has for a slug, with
	 * where it comes from, its title and its content; null when there is
	 * none (a custom template, or a theme that isn't active).
	 *
	 * @param string $stylesheet The theme.
	 * @param string $slug       The slug.
	 * @param string $post_type  `wp_template` or `wp_template_part`.
	 *
	 * @return array{source: string, title: string, content: string}|null
	 */
	private static function provided( string $stylesheet, string $slug, string $post_type ): ?array {
		if ( '' === $slug || ! function_exists( 'get_block_file_template' ) ) {
			return null;
		}

		$template = get_block_file_template( $stylesheet . '//' . $slug, $post_type );
		if ( $template instanceof \WP_Block_Template ) {
			return [
				'source'  => 'theme' === $template->source ? 'theme' : 'plugin',
				'title'   => (string) $template->title,
				'content' => (string) $template->content,
			];
		}

		if ( 'wp_template' === $post_type && class_exists( '\WP_Block_Templates_Registry' ) ) {
			$registered = \WP_Block_Templates_Registry::get_instance()->get_by_slug( $slug );
			if ( $registered instanceof \WP_Block_Template ) {
				return [
					'source'  => 'plugin',
					'title'   => (string) $registered->title,
					'content' => (string) $registered->content,
				];
			}
		}

		return null;
	}

	/**
	 * Why a block diff couldn't be made.
	 *
	 * @param array<string, mixed> $content What the Collector noted of the content.
	 *
	 * @return array<string, mixed>
	 */
	private static function no_diff( array $content ): array {
		return ContentDiff::unchanged_text( ! empty( $content['too_large'] ) ? 'too_large' : 'unavailable' );
	}

	/**
	 * The blocks added and removed between two versions of a template, as
	 * outline lines; `markup` when only something the outline doesn't show
	 * changed.
	 *
	 * @param string $before The content before.
	 * @param string $after  The content after.
	 *
	 * @return array<string, mixed>
	 */
	public static function blocks_diff( string $before, string $after ): array {
		$old = self::outline( $before, 0 );
		$new = self::outline( $after, 0 );
		if ( $old === $new ) {
			return ContentDiff::unchanged_text( 'markup' );
		}

		return ContentDiff::compare_code( implode( "\n", $old ), implode( "\n", $new ) );
	}

	/**
	 * A template's blocks as an outline: one line per block, indented by
	 * depth, with the block's name, what it shows (a template part's slug, a
	 * pattern, a menu's title, a query's post type, an image's file), its
	 * text and its other attributes.
	 *
	 * @param string $content The block markup.
	 * @param int    $depth   The depth to start at.
	 *
	 * @return string[]
	 */
	public static function outline( string $content, int $depth ): array {
		if ( '' === trim( $content ) || ! function_exists( 'parse_blocks' ) ) {
			return [];
		}

		$blocks = parse_blocks( $content );
		// The editor saves a template with its patterns written out, as the
		// REST endpoint gives them; a theme's file names them.
		if ( function_exists( 'resolve_pattern_blocks' ) ) {
			$blocks = resolve_pattern_blocks( $blocks );
		}

		$lines = [];
		self::walk( $blocks, $depth, $lines );

		return $lines;
	}

	/**
	 * Add the outline lines of blocks.
	 *
	 * @param array<int, mixed> $blocks The parsed blocks.
	 * @param int               $depth  Their depth.
	 * @param string[]          $lines  The lines so far.
	 *
	 * @return void
	 */
	private static function walk( array $blocks, int $depth, array &$lines ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || count( $lines ) >= self::MAX_OUTLINE_LINES ) {
				return;
			}

			$indent = str_repeat( '  ', $depth );
			$name   = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
			$html   = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
			$inner  = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];

			if ( '' === $name ) {
				$text = self::text( $html );
				if ( '' !== $text ) {
					$lines[] = $indent . 'html: ' . $text;
				}
				continue;
			}

			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$line  = $indent . ( 0 === strpos( $name, 'core/' ) ? substr( $name, 5 ) : $name );

			$identifier = self::identifier( $name, $attrs, $html );
			if ( '' !== $identifier ) {
				$line .= ' ' . $identifier;
			}
			// A block's own name (List View) says which it is; the rest of its
			// metadata is where a pattern came from.
			if ( is_string( $attrs['metadata']['name'] ?? null ) && '' !== $attrs['metadata']['name'] ) {
				$line .= ' “' . self::plain( $attrs['metadata']['name'] ) . '”';
			}
			unset( $attrs['metadata'] );
			if ( [] === $inner ) {
				$text = self::text( $html );
				if ( '' !== $text ) {
					$line .= ': ' . $text;
				}
			}

			$key = self::IDENTIFIERS[ $name ] ?? '';
			if ( '' !== $key ) {
				unset( $attrs[ $key ] );
			}
			if ( 'core/query' === $name && isset( $attrs['query']['postType'] ) ) {
				unset( $attrs['query']['postType'] );
				if ( [] === $attrs['query'] ) {
					unset( $attrs['query'] );
				}
			}
			if ( [] !== $attrs ) {
				$encoded = (string) wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				$line   .= ' · ' . ( mb_strlen( $encoded ) > self::MAX_ATTRIBUTES ? mb_substr( $encoded, 0, self::MAX_ATTRIBUTES ) . '…' : $encoded );
			}

			$lines[] = $line;
			self::walk( $inner, $depth + 1, $lines );
		}
	}

	/**
	 * What a block shows, in words: a template part's or pattern's slug, the
	 * title of a synced pattern or menu, a query's post type, an image's
	 * file; '' for other blocks.
	 *
	 * @param string               $name  The block name.
	 * @param array<string, mixed> $attrs The block's attributes.
	 * @param string               $html  The block's HTML.
	 *
	 * @return string
	 */
	private static function identifier( string $name, array $attrs, string $html ): string {
		switch ( $name ) {
			case 'core/template-part':
			case 'core/pattern':
				return is_string( $attrs['slug'] ?? null ) ? $attrs['slug'] : '';
			case 'core/block':
			case 'core/navigation':
				$id = (int) ( $attrs['ref'] ?? 0 );
				if ( $id < 1 ) {
					return '';
				}
				$title = get_the_title( $id );
				return '' !== $title ? '“' . self::plain( $title ) . '”' : '#' . $id;
			case 'core/query':
				return is_string( $attrs['query']['postType'] ?? null ) ? $attrs['query']['postType'] : '';
			case 'core/image':
			case 'core/cover':
			case 'core/media-text':
				if ( 1 === preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/i', $html, $match ) ) {
					$path = (string) wp_parse_url( $match[2], PHP_URL_PATH );
					return '' !== $path ? wp_basename( $path ) : '';
				}
				return '';
			default:
				return '';
		}
	}

	/**
	 * A block's text: no tags, one line, cut short.
	 *
	 * @param string $html The block's HTML.
	 *
	 * @return string
	 */
	private static function text( string $html ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', self::plain( wp_strip_all_tags( $html ) ) ) );

		return mb_strlen( $text ) > self::MAX_TEXT ? mb_substr( $text, 0, self::MAX_TEXT ) . '…' : $text;
	}

	/**
	 * The name of a template part area from term taxonomy IDs; '' for none.
	 *
	 * @param array<int, mixed> $ids Term taxonomy IDs.
	 *
	 * @return string
	 */
	private static function area_name( array $ids ): string {
		foreach ( $ids as $id ) {
			$term = get_term_by( 'term_taxonomy_id', (int) $id, 'wp_template_part_area' );
			if ( $term instanceof \WP_Term ) {
				return (string) $term->name;
			}
		}

		return '';
	}

	/**
	 * The name of a post's first term in a taxonomy; '' for none.
	 *
	 * @param \WP_Post|null $post     The post.
	 * @param string        $taxonomy The taxonomy.
	 *
	 * @return string
	 */
	private static function term_name( ?\WP_Post $post, string $taxonomy ): string {
		if ( null === $post ) {
			return '';
		}
		$terms = get_the_terms( $post, $taxonomy );

		return is_array( $terms ) && isset( $terms[0]->name ) ? (string) $terms[0]->name : '';
	}

	/**
	 * A theme's name; its stylesheet when it isn't installed.
	 *
	 * @param string $stylesheet The theme.
	 *
	 * @return string
	 */
	private static function theme_name( string $stylesheet ): string {
		$theme = '' !== $stylesheet ? wp_get_theme( $stylesheet ) : null;
		$name  = is_object( $theme ) && $theme->exists() ? self::plain( $theme->get( 'Name' ) ) : '';

		return '' !== $name ? $name : $stylesheet;
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
