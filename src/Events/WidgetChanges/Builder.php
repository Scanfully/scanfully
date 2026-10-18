<?php
/**
 * The widget event builder class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\WidgetChanges;

use Scanfully\Events\PostChanges\ContentDiff;

/**
 * Class Builder
 *
 * The background job's part of a WidgetsChanged event: names each widget's
 * type and area, labels block widgets by their content, reads the title of
 * a widget that was only moved or removed, and compares the text of text,
 * HTML and block widgets line by line, as post content is.
 *
 * Data sent: `name` (the widget's title or type when the row is about one
 * widget, else the area when they are all in one), `area` (`id`, `name`)
 * when they are all in one, `widgets`: each with `id`, `type`,
 * `type_label`, `title`, `excerpt` (a block widget's first line of text, for
 * the dashboard), `action` (`added`, `changed`, `moved`, `removed`
 * to the inactive widgets, `deleted`), `area` and `old_area` (`id`,
 * `name`), `changed` (setting keys), `title_old`, `values` (`old` and
 * `new` of settings of 200 characters or less) and `content` (the added
 * and removed lines); and `order_changed` (areas whose widgets changed
 * order).
 *
 * @package Scanfully\Events\WidgetChanges
 */
final class Builder {

	/**
	 * Labels longer than this are cut.
	 */
	private const MAX_LABEL = 200;

	/**
	 * The event data with names and content diffs added.
	 *
	 * @param array<string, mixed> $data The data the request noted.
	 *
	 * @return array<string, mixed>
	 */
	public static function resolve( array $data ): array {
		$widgets = [];
		$areas   = [];
		foreach ( (array) ( $data['widgets'] ?? [] ) as $widget ) {
			if ( ! is_array( $widget ) ) {
				continue;
			}
			$widget    = self::widget( $widget );
			$widgets[] = $widget;
			foreach ( [ 'area', 'old_area' ] as $side ) {
				if ( isset( $widget[ $side ]['id'] ) ) {
					$areas[ $widget[ $side ]['id'] ] = $widget[ $side ];
				}
			}
		}

		$order = [];
		foreach ( (array) ( $data['order_changed'] ?? [] ) as $area ) {
			$order[]                 = self::area( (string) $area );
			$areas[ (string) $area ] = end( $order );
		}

		$out = [];
		if ( 1 === count( $widgets ) && [] === $order ) {
			$out['name'] = '' !== ( $widgets[0]['title'] ?? '' ) ? $widgets[0]['title'] : $widgets[0]['type_label'];
		} elseif ( 1 === count( $areas ) ) {
			$out['name'] = (string) reset( $areas )['name'];
		}
		if ( 1 === count( $areas ) ) {
			$out['area'] = reset( $areas );
		}
		$out['widgets'] = $widgets;
		if ( [] !== $order ) {
			$out['order_changed'] = $order;
		}

		return $out;
	}

	/**
	 * One widget with its names and content diff.
	 *
	 * @param array<string, mixed> $widget The widget as noted.
	 *
	 * @return array<string, mixed>
	 */
	private static function widget( array $widget ): array {
		$type    = (string) ( $widget['type'] ?? '' );
		$id      = (string) ( $widget['id'] ?? '' );
		$content = $widget['_content'] ?? ( $widget['_text']['new'] ?? null );
		$title   = isset( $widget['title'] ) ? (string) $widget['title'] : null;
		// A widget moved or removed without a change to its settings: the
		// request had none of them, so they are read as stored now.
		if ( 'block' === $type && ! is_string( $content ) ) {
			$stored  = self::stored_instance( $type, $id );
			$content = isset( $stored['content'] ) && is_string( $stored['content'] ) ? $stored['content'] : null;
		} elseif ( 'block' !== $type && null === $title ) {
			$stored = self::stored_instance( $type, $id );
			$title  = isset( $stored['title'] ) && is_scalar( $stored['title'] ) ? (string) $stored['title'] : null;
		}

		$out = [
			'id'         => $id,
			'type'       => $type,
			'type_label' => 'block' === $type && is_string( $content ) ? self::block_label( $content ) : self::type_label( $type ),
			'title'      => null !== $title ? self::plain( $title ) : '',
			'action'     => (string) ( $widget['action'] ?? '' ),
		];
		// A block widget has no title. The dashboard shows its first line of
		// text to tell it apart; that is text of the widget, so it is not its
		// title (the subject, search and the public API never get it).
		if ( '' === $out['title'] && 'block' === $type && is_string( $content ) ) {
			$lines = ContentDiff::text_lines( $content );
			$first = self::plain( (string) ( $lines[0] ?? '' ) );
			if ( '' !== $first ) {
				$out['excerpt'] = function_exists( 'mb_substr' ) ? mb_substr( $first, 0, 80 ) : substr( $first, 0, 80 );
			}
		}
		foreach ( [ 'area', 'old_area' ] as $side ) {
			if ( isset( $widget[ $side ] ) ) {
				$out[ $side ] = self::area( (string) $widget[ $side ] );
			}
		}
		foreach ( [ 'changed', 'title_old', 'values' ] as $key ) {
			if ( isset( $widget[ $key ] ) ) {
				$out[ $key ] = 'title_old' === $key ? self::plain( (string) $widget[ $key ] ) : $widget[ $key ];
			}
		}
		if ( isset( $widget['_text'] ) && is_array( $widget['_text'] ) ) {
			$out['content'] = ContentDiff::compare( (string) ( $widget['_text']['old'] ?? '' ), (string) ( $widget['_text']['new'] ?? '' ) );
		}

		return $out;
	}

	/**
	 * The settings of a widget as stored now; null when it is gone.
	 *
	 * @param string $type The widget's ID base (`text`).
	 * @param string $id   The widget ID (`text-3`).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function stored_instance( string $type, string $id ): ?array {
		if ( '' === $type || 0 !== strpos( $id, $type . '-' ) || ! ctype_digit( substr( $id, strlen( $type ) + 1 ) ) ) {
			return null;
		}
		$instances = get_option( 'widget_' . $type );
		$instance  = is_array( $instances ) ? ( $instances[ (int) substr( $id, strlen( $type ) + 1 ) ] ?? null ) : null;

		return is_array( $instance ) ? $instance : null;
	}

	/**
	 * The title of a block widget's first block, such as "Paragraph".
	 *
	 * @param string $content The widget's content.
	 *
	 * @return string
	 */
	private static function block_label( string $content ): string {
		foreach ( parse_blocks( $content ) as $block ) {
			$name = $block['blockName'] ?? null;
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}
			$type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );

			return $type instanceof \WP_Block_Type && '' !== (string) $type->title ? (string) $type->title : $name;
		}

		return __( 'Block', 'scanfully' );
	}

	/**
	 * A classic widget type's name, such as "Recent Posts"; its ID base when
	 * it isn't registered.
	 *
	 * @param string $type The widget's ID base.
	 *
	 * @return string
	 */
	private static function type_label( string $type ): string {
		global $wp_widget_factory;

		if ( is_object( $wp_widget_factory ) && isset( $wp_widget_factory->widgets ) && is_array( $wp_widget_factory->widgets ) ) {
			foreach ( $wp_widget_factory->widgets as $widget ) {
				if ( is_object( $widget ) && isset( $widget->id_base, $widget->name ) && $type === $widget->id_base ) {
					return self::plain( (string) $widget->name );
				}
			}
		}

		return $type;
	}

	/**
	 * A widget area by ID and name.
	 *
	 * @param string $id The area ID.
	 *
	 * @return array{id: string, name: string}
	 */
	private static function area( string $id ): array {
		global $wp_registered_sidebars;

		if ( Collector::INACTIVE === $id ) {
			$name = __( 'Inactive widgets', 'scanfully' );
		} elseif ( is_array( $wp_registered_sidebars ) && isset( $wp_registered_sidebars[ $id ]['name'] ) ) {
			$name = self::plain( (string) $wp_registered_sidebars[ $id ]['name'] );
		} else {
			$name = $id;
		}

		return [
			'id'   => $id,
			'name' => $name,
		];
	}

	/**
	 * Text as plain text, cut to MAX_LABEL characters.
	 *
	 * @param string $text The text, possibly with HTML and entities.
	 *
	 * @return string
	 */
	private static function plain( string $text ): string {
		$text = trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_LABEL ) : substr( $text, 0, self::MAX_LABEL );
	}
}
