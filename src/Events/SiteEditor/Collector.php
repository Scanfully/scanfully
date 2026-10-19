<?php
/**
 * The Site Editor collector class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\SiteEditor;

use Scanfully\Events\Content\Limiter;
use Scanfully\Events\Content\MinuteStore;
use Scanfully\Events\ContentEvent;
use Scanfully\Events\Controller;
use Scanfully\Options;

/**
 * Class Collector
 *
 * Notes what the Site Editor of a block theme saves during a request: the
 * global styles (`wp_global_styles`), templates and template parts
 * (`wp_template`, `wp_template_part`) and fonts of the Font Library
 * (`wp_font_family`, `wp_font_face`). At the end of the request it reports
 * one row per changed style or template, and leaves the fonts for the
 * minute's job (Fonts), since an install is one request per variant.
 *
 * Only in-memory work on the posts WordPress passes to its hooks. Comparing
 * styles, outlining blocks, reading the theme's own templates and names all
 * happen in the background job (Builder).
 *
 * @package Scanfully\Events\SiteEditor
 */
final class Collector {

	/**
	 * The kind of the fonts' minute entries (MinuteStore).
	 */
	public const KIND_FONTS = 'fonts';

	/**
	 * Content larger than this (per side) is not compared.
	 */
	public const MAX_CONTENT = 256 * 1024;

	/**
	 * The template post types and the kind each is.
	 */
	public const TEMPLATE_TYPES = [
		'wp_template'      => 'template',
		'wp_template_part' => 'template_part',
	];

	/**
	 * Global styles saved in this request, per post: the theme, the content
	 * and title before the first save and after the last. The title is the
	 * style variation picked (the REST endpoint stores it as the post's
	 * title).
	 *
	 * @var array<int, array{stylesheet: string, old: string, new: string, old_title: string, new_title: string}>
	 */
	private static array $styles = [];

	/**
	 * Templates and template parts saved or removed in this request, per
	 * post.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $templates = [];

	/**
	 * Template part areas set in this request, per post: term taxonomy IDs
	 * before the first change and after the last.
	 *
	 * @var array<int, array{old: int[], new: int[]}>
	 */
	private static array $areas = [];

	/**
	 * Font families and variants added or removed in this request, per
	 * family post.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $fonts = [];

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_action( 'wp_after_insert_post', [ self::class, 'note_saved' ], 10, 4 );
		add_action( 'before_delete_post', [ self::class, 'note_deleting' ], 10, 2 );
		add_action( 'set_object_terms', [ self::class, 'note_terms' ], 10, 6 );
		add_action( 'shutdown', [ self::class, 'flush' ], 1, 0 );
	}

	/**
	 * Forget what was noted (tests, and after a flush).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$styles    = [];
		self::$templates = [];
		self::$areas     = [];
		self::$fonts     = [];
	}

	/**
	 * Whether installs, imports or another site of a network are at work.
	 *
	 * @return bool
	 */
	private static function is_setup_work(): bool {
		return ( defined( 'WP_IMPORTING' ) && WP_IMPORTING )
			|| wp_installing()
			|| ( function_exists( 'ms_is_switched' ) && ms_is_switched() );
	}

	/**
	 * `wp_after_insert_post`: a post was saved.
	 *
	 * @param mixed $post_id     The post ID.
	 * @param mixed $post        The post.
	 * @param mixed $update      Whether an existing post was updated.
	 * @param mixed $post_before The post before the save, or null.
	 *
	 * @return void
	 */
	public static function note_saved( $post_id, $post, $update, $post_before ): void {
		if ( ! is_object( $post ) || self::is_setup_work() ) {
			return;
		}

		$post_id = (int) $post_id;
		$type    = (string) ( $post->post_type ?? '' );
		$before  = $update && is_object( $post_before ) ? $post_before : null;

		if ( 'wp_global_styles' === $type ) {
			self::note_styles( $post_id, $post, $before );
		} elseif ( isset( self::TEMPLATE_TYPES[ $type ] ) ) {
			self::note_template( $post_id, $post, $before );
		} elseif ( ! $update && ( 'wp_font_family' === $type || 'wp_font_face' === $type ) ) {
			self::note_font( $post, 'added' );
		}
	}

	/**
	 * `before_delete_post`: a post is about to be deleted.
	 *
	 * @param mixed $post_id The post ID.
	 * @param mixed $post    The post.
	 *
	 * @return void
	 */
	public static function note_deleting( $post_id, $post ): void {
		if ( ! is_object( $post ) || self::is_setup_work() ) {
			return;
		}

		$type = (string) ( $post->post_type ?? '' );
		if ( isset( self::TEMPLATE_TYPES[ $type ] ) ) {
			self::note_template_removed( (int) $post_id, $post );
		} elseif ( 'wp_font_family' === $type || 'wp_font_face' === $type ) {
			self::note_font( $post, 'removed' );
		}
	}

	/**
	 * `set_object_terms`: a template part's area changed.
	 *
	 * @param mixed $object_id  The object ID.
	 * @param mixed $terms      The terms asked for.
	 * @param mixed $tt_ids     The term taxonomy IDs set.
	 * @param mixed $taxonomy   The taxonomy.
	 * @param mixed $append     Whether the terms were added to the existing ones.
	 * @param mixed $old_tt_ids The term taxonomy IDs before.
	 *
	 * @return void
	 */
	public static function note_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		if ( 'wp_template_part_area' !== $taxonomy || self::is_setup_work() ) {
			return;
		}

		$post_id = (int) $object_id;
		$new     = array_map( 'intval', is_array( $tt_ids ) ? $tt_ids : [] );
		$old     = array_map( 'intval', is_array( $old_tt_ids ) ? $old_tt_ids : [] );
		if ( ! isset( self::$areas[ $post_id ] ) ) {
			self::$areas[ $post_id ] = [
				'old' => $old,
				'new' => $new,
			];
			return;
		}
		self::$areas[ $post_id ]['new'] = $new;
	}

	/**
	 * Note a global styles save.
	 *
	 * @param int         $post_id The post ID.
	 * @param object      $post    The post.
	 * @param object|null $before  The post before, or null for a new post.
	 *
	 * @return void
	 */
	private static function note_styles( int $post_id, object $post, ?object $before ): void {
		$new       = (string) ( $post->post_content ?? '' );
		$new_title = (string) ( $post->post_title ?? '' );
		if ( isset( self::$styles[ $post_id ] ) ) {
			self::$styles[ $post_id ]['new']       = $new;
			self::$styles[ $post_id ]['new_title'] = $new_title;
			return;
		}

		self::$styles[ $post_id ] = [
			'stylesheet' => self::styles_stylesheet( $post ),
			'old'        => null !== $before ? (string) ( $before->post_content ?? '' ) : '',
			'new'        => $new,
			'old_title'  => null !== $before ? (string) ( $before->post_title ?? '' ) : $new_title,
			'new_title'  => $new_title,
		];
	}

	/**
	 * The theme of a global styles post, from its name
	 * (`wp-global-styles-{stylesheet}`), without a query.
	 *
	 * @param object $post The post.
	 *
	 * @return string
	 */
	public static function styles_stylesheet( object $post ): string {
		$name   = (string) ( $post->post_name ?? '' );
		$prefix = 'wp-global-styles-';

		return 0 === strpos( $name, $prefix ) ? urldecode( substr( $name, strlen( $prefix ) ) ) : (string) get_stylesheet();
	}

	/**
	 * Note a template or template part save.
	 *
	 * @param int         $post_id The post ID.
	 * @param object      $post    The post.
	 * @param object|null $before  The post before, or null for a new post.
	 *
	 * @return void
	 */
	private static function note_template( int $post_id, object $post, ?object $before ): void {
		if ( 'auto-draft' === ( $post->post_status ?? '' ) ) {
			return;
		}

		if ( ! isset( self::$templates[ $post_id ] ) ) {
			self::$templates[ $post_id ] = [
				'kind'     => self::TEMPLATE_TYPES[ (string) $post->post_type ],
				'inserted' => null === $before,
				'old'      => null !== $before ? self::template_fields( $before ) : null,
			];
		}
		self::$templates[ $post_id ]['slug'] = (string) ( $post->post_name ?? '' );
		self::$templates[ $post_id ]['new']  = self::template_fields( $post );
	}

	/**
	 * Note a template or template part about to be deleted: a reset to the
	 * theme's version, or a custom one removed. Its theme and area are read
	 * now, from the term cache the delete has just filled.
	 *
	 * @param int    $post_id The post ID.
	 * @param object $post    The post.
	 *
	 * @return void
	 */
	private static function note_template_removed( int $post_id, object $post ): void {
		self::$templates[ $post_id ] = [
			'kind'       => self::TEMPLATE_TYPES[ (string) $post->post_type ],
			'slug'       => (string) ( $post->post_name ?? '' ),
			'removed'    => self::template_fields( $post ),
			'stylesheet' => self::term_name( $post_id, 'wp_theme' ),
			'area'       => 'wp_template_part' === $post->post_type ? self::term_name( $post_id, 'wp_template_part_area' ) : '',
		];
	}

	/**
	 * The fields of a template the row compares.
	 *
	 * @param object $post The template's post.
	 *
	 * @return array{title: string, description: string, content: string, status: string}
	 */
	private static function template_fields( object $post ): array {
		return [
			'title'       => (string) ( $post->post_title ?? '' ),
			'description' => (string) ( $post->post_excerpt ?? '' ),
			'content'     => (string) ( $post->post_content ?? '' ),
			'status'      => (string) ( $post->post_status ?? '' ),
		];
	}

	/**
	 * The name of a post's first term in a taxonomy; '' for none.
	 *
	 * @param int    $post_id  The post ID.
	 * @param string $taxonomy The taxonomy.
	 *
	 * @return string
	 */
	private static function term_name( int $post_id, string $taxonomy ): string {
		$terms = get_the_terms( $post_id, $taxonomy );

		return is_array( $terms ) && isset( $terms[0]->name ) ? (string) $terms[0]->name : '';
	}

	/**
	 * Note a font family or variant added or removed.
	 *
	 * @param object $post   The family's or variant's post.
	 * @param string $action `added` or `removed`.
	 *
	 * @return void
	 */
	private static function note_font( object $post, string $action ): void {
		if ( 'wp_font_family' === $post->post_type ) {
			$family_id = (int) ( $post->ID ?? 0 );
			self::font_family( $family_id, $post );
			self::$fonts[ $family_id ]['family'] = $action;
			return;
		}

		$family_id = (int) ( $post->post_parent ?? 0 );
		if ( $family_id < 1 ) {
			return;
		}
		if ( ! isset( self::$fonts[ $family_id ] ) ) {
			// The family's post is in the cache: the REST request loaded it.
			$family = get_post( $family_id );
			if ( ! $family instanceof \WP_Post ) {
				return;
			}
			self::font_family( $family_id, $family );
		}

		$settings                             = json_decode( (string) ( $post->post_content ?? '' ), true );
		self::$fonts[ $family_id ]['faces'][] = [
			'action' => $action,
			'style'  => is_array( $settings ) ? (string) ( $settings['fontStyle'] ?? 'normal' ) : 'normal',
			'weight' => is_array( $settings ) ? (string) ( $settings['fontWeight'] ?? '400' ) : '400',
		];
	}

	/**
	 * The note of a font family, made when missing.
	 *
	 * @param int    $family_id The family's post ID.
	 * @param object $family    The family's post.
	 *
	 * @return void
	 */
	private static function font_family( int $family_id, object $family ): void {
		if ( isset( self::$fonts[ $family_id ] ) ) {
			return;
		}

		self::$fonts[ $family_id ] = [
			'id'     => $family_id,
			'name'   => (string) ( $family->post_title ?? '' ),
			'slug'   => (string) ( $family->post_name ?? '' ),
			'family' => '',
			'faces'  => [],
		];
	}

	/**
	 * Whether a global styles config holds nothing the user changed.
	 *
	 * @param mixed $config The decoded config.
	 *
	 * @return bool
	 */
	public static function is_empty_styles( $config ): bool {
		return ! is_array( $config ) || ( empty( $config['styles'] ) && empty( $config['settings'] ) );
	}

	/**
	 * The rows of the styles saved in this request.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function styles_rows(): array {
		$rows = [];
		foreach ( self::$styles as $styles ) {
			$title_changed = $styles['old_title'] !== $styles['new_title'];
			if ( $styles['old'] === $styles['new'] && ! $title_changed ) {
				continue;
			}
			// Core makes the post with empty styles when an editor first asks
			// for it; that is nobody's change.
			if ( ! $title_changed && self::is_empty_styles( json_decode( $styles['new'], true ) ) && self::is_empty_styles( json_decode( '' !== $styles['old'] ? $styles['old'] : '{}', true ) ) ) {
				continue;
			}

			$row = [
				'stylesheet' => $styles['stylesheet'],
				'content'    => self::content( $styles['old'], $styles['new'] ),
			];
			if ( $title_changed ) {
				$row['title'] = [
					'old' => $styles['old_title'],
					'new' => $styles['new_title'],
				];
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * The rows of the templates saved or removed in this request: the type
	 * to report and the data. A first save is a provisional TemplateChanged
	 * and a delete a provisional TemplateDeleted; the job tells a first
	 * customization from a new template and a reset from a delete.
	 *
	 * @return array<int, array{0: string, 1: array<string, mixed>}>
	 */
	public static function template_rows(): array {
		$rows = [];
		foreach ( self::$templates as $post_id => $template ) {
			$row = [
				'post_id' => (int) $post_id,
				'kind'    => (string) $template['kind'],
				'slug'    => (string) ( $template['slug'] ?? '' ),
			];

			$removed = $template['removed'] ?? null;
			$new     = $template['new'] ?? null;
			if ( null === $removed && is_array( $new ) && 'trash' === $new['status'] ) {
				$removed = $new;
			}

			if ( is_array( $removed ) ) {
				$row['removed'] = true;
				$row['title']   = $removed['title'];
				$row['content'] = self::content( $removed['content'], '' );
				foreach ( [ 'stylesheet', 'area' ] as $key ) {
					if ( '' !== (string) ( $template[ $key ] ?? '' ) ) {
						$row[ $key ] = (string) $template[ $key ];
					}
				}
				$rows[] = [ 'TemplateDeleted', $row ];
				continue;
			}

			if ( ! is_array( $new ) ) {
				continue;
			}
			$row['title'] = $new['title'];

			if ( ! empty( $template['inserted'] ) ) {
				$row['inserted'] = true;
				$row['content']  = self::content( '', $new['content'] );
				$rows[]          = [ 'TemplateChanged', $row ];
				continue;
			}

			$old     = is_array( $template['old'] ?? null ) ? $template['old'] : $new;
			$changes = [];
			foreach ( [ 'title', 'description' ] as $field ) {
				if ( $old[ $field ] !== $new[ $field ] ) {
					$changes[ $field ] = [
						'old' => $old[ $field ],
						'new' => $new[ $field ],
					];
				}
			}
			$area = self::$areas[ $post_id ] ?? null;
			if ( null !== $area && ( [] !== array_diff( $area['old'], $area['new'] ) || [] !== array_diff( $area['new'], $area['old'] ) ) ) {
				$changes['area'] = $area;
			}
			if ( $old['content'] !== $new['content'] ) {
				$row['content'] = self::content( $old['content'], $new['content'] );
			}
			if ( [] === $changes && ! isset( $row['content'] ) ) {
				continue;
			}
			if ( [] !== $changes ) {
				$row['changes'] = $changes;
			}
			$rows[] = [ 'TemplateChanged', $row ];
		}

		return $rows;
	}

	/**
	 * The content before and after for the job, or that it is too large.
	 *
	 * @param string $old The content before.
	 * @param string $new The content after.
	 *
	 * @return array<string, mixed>
	 */
	private static function content( string $old, string $new ): array {
		if ( strlen( $old ) > self::MAX_CONTENT || strlen( $new ) > self::MAX_CONTENT ) {
			return [ 'too_large' => true ];
		}

		return [
			'stash' => [
				'old' => $old,
				'new' => $new,
			],
		];
	}

	/**
	 * The font notes of this request, as the minute's job reads them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function font_items(): array {
		$items = [];
		foreach ( self::$fonts as $font ) {
			if ( '' !== $font['family'] || [] !== $font['faces'] ) {
				$items[] = $font;
			}
		}

		return $items;
	}

	/**
	 * Report what this request changed, once, at the end of the request.
	 *
	 * @return void
	 */
	public static function flush(): void {
		$styles    = self::styles_rows();
		$templates = self::template_rows();
		$fonts     = self::font_items();
		self::reset();

		if ( [] !== $fonts && Options\Controller::get_options()->is_connected ) {
			$user = wp_get_current_user();
			MinuteStore::add(
				self::KIND_FONTS,
				[
					'user'  => [
						'id'   => (int) $user->ID,
						'name' => ContentEvent::user_name( $user ),
					],
					'items' => $fonts,
				],
				Controller::ACTION_SEND_FONTS
			);
		}

		$rows = [];
		foreach ( $styles as $row ) {
			$rows[] = [ 'StylesChanged', $row ];
		}
		$rows = array_merge( $rows, $templates );

		$room = Limiter::take( Limiter::KIND_SITE_EDITOR, count( $rows ) );
		foreach ( array_slice( $rows, 0, $room ) as [ $type, $data ] ) {
			do_action( ContentEvent::hook( $type ), $data ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The hooks start with scanfully_.
		}
	}
}
