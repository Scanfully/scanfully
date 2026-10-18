<?php
/**
 * The widget change collector class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\WidgetChanges;

use Scanfully\Events\Content\Limiter;
use Scanfully\Events\ContentEvent;
use Scanfully\Events\MenuChanges\Collector as MenuCollector;

/**
 * Class Collector
 *
 * Notes the widgets a request adds, changes, moves and removes, and
 * reports them at the end of the request as one WidgetsChanged row.
 * Widgets live in options: `widget_{type}` holds the settings of every
 * widget of a type, `sidebars_widgets` the widgets of each area. Watching
 * those options catches every way widgets are saved: the block widget
 * editor (one REST batch request), the classic Widgets screen, the
 * Customizer and WP-CLI.
 *
 * Left out: read requests (WordPress tidies widgets up when the Widgets
 * screen, the Customizer or the REST API loads them) and the remapping a
 * theme switch does. The request compares arrays WordPress passes;
 * names and content diffs are made by the background job (Builder).
 *
 * @package Scanfully\Events\WidgetChanges
 */
final class Collector {

	/**
	 * The option that maps widget areas to widget IDs.
	 */
	public const AREAS_OPTION = 'sidebars_widgets';

	/**
	 * The area that holds inactive widgets.
	 */
	public const INACTIVE = 'wp_inactive_widgets';

	/**
	 * Settings that hold text compared line by line, as post content is.
	 */
	public const CONTENT_KEYS = [ 'text', 'content' ];

	/**
	 * Values longer than this show as changed, without the value.
	 */
	public const MAX_VALUE = 200;

	/**
	 * At most this many widgets are listed in one row.
	 */
	private const MAX_WIDGETS = 500;

	/**
	 * The options noted: first old and last new value.
	 *
	 * @var array<string, array{old: mixed, new: mixed}>
	 */
	private static array $options = [];

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_action( 'updated_option', [ self::class, 'note_updated' ], 10, 3 );
		add_action( 'added_option', [ self::class, 'note_added' ], 10, 2 );
		add_action( 'shutdown', [ self::class, 'flush' ], 1, 0 );
	}

	/**
	 * Forget what was noted (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$options = [];
	}

	/**
	 * Whether an option holds widgets.
	 *
	 * @param string $option The option name.
	 *
	 * @return bool
	 */
	private static function is_widget_option( string $option ): bool {
		return self::AREAS_OPTION === $option || 0 === strpos( $option, 'widget_' );
	}

	/**
	 * Whether this request's widget changes are left out.
	 *
	 * @return bool
	 */
	private static function is_quiet(): bool {
		return MenuCollector::is_quiet() || MenuCollector::is_read_request();
	}

	/**
	 * `updated_option`: widget settings or areas changed.
	 *
	 * @param mixed $option    The option name.
	 * @param mixed $old_value The value before.
	 * @param mixed $value     The value after.
	 *
	 * @return void
	 */
	public static function note_updated( $option, $old_value, $value ): void {
		if ( ! is_string( $option ) || ! self::is_widget_option( $option ) || self::is_quiet() ) {
			return;
		}

		if ( ! isset( self::$options[ $option ] ) ) {
			self::$options[ $option ] = [
				'old' => $old_value,
				'new' => $value,
			];
		}
		self::$options[ $option ]['new'] = $value;
	}

	/**
	 * `added_option`: the first widget of a type.
	 *
	 * @param mixed $option The option name.
	 * @param mixed $value  The value.
	 *
	 * @return void
	 */
	public static function note_added( $option, $value ): void {
		if ( ! is_string( $option ) || ! self::is_widget_option( $option ) || self::is_quiet() ) {
			return;
		}

		self::$options[ $option ] = [
			'old' => [],
			'new' => $value,
		];
	}

	/**
	 * The row's data for what this request changed (the job completes it),
	 * or [] when no widget changed.
	 *
	 * @return array<string, mixed>
	 */
	public static function row(): array {
		if ( [] === self::$options ) {
			return [];
		}

		$areas      = self::$options[ self::AREAS_OPTION ] ?? null;
		$current    = null === $areas ? get_option( self::AREAS_OPTION ) : null;
		$old_places = self::places( null === $areas ? $current : $areas['old'] );
		$new_places = self::places( null === $areas ? $current : $areas['new'] );

		// Settings changes per widget ID.
		$instances = [];
		foreach ( self::$options as $option => $values ) {
			if ( self::AREAS_OPTION === $option ) {
				continue;
			}
			$type   = substr( $option, strlen( 'widget_' ) );
			$before = is_array( $values['old'] ) ? $values['old'] : [];
			$after  = is_array( $values['new'] ) ? $values['new'] : [];
			foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $number ) {
				if ( ! is_int( $number ) && ! ctype_digit( (string) $number ) ) {
					// `_multiwidget` and the like.
					continue;
				}
				$old = isset( $before[ $number ] ) && is_array( $before[ $number ] ) ? $before[ $number ] : null;
				$new = isset( $after[ $number ] ) && is_array( $after[ $number ] ) ? $after[ $number ] : null;
				if ( $old === $new ) {
					continue;
				}
				$instances[ $type . '-' . $number ] = [
					'type' => $type,
					'old'  => $old,
					'new'  => $new,
				];
			}
		}

		$widgets = [];
		$ids     = array_unique( array_merge( array_keys( $instances ), array_keys( $old_places ), array_keys( $new_places ) ) );
		foreach ( $ids as $id ) {
			$widget = self::widget( (string) $id, $old_places[ $id ] ?? null, $new_places[ $id ] ?? null, $instances[ $id ] ?? null );
			if ( null !== $widget ) {
				$widgets[] = $widget;
			}
			if ( count( $widgets ) >= self::MAX_WIDGETS ) {
				break;
			}
		}

		$reordered = self::reordered( $old_places, $new_places, $widgets );
		if ( [] === $widgets && [] === $reordered ) {
			return [];
		}

		$data = [ 'widgets' => $widgets ];
		if ( [] !== $reordered ) {
			$data['order_changed'] = $reordered;
		}

		return $data;
	}

	/**
	 * What happened to one widget, or null when nothing a visitor could see
	 * changed.
	 *
	 * @param string                        $id       The widget ID (`text-3`).
	 * @param array{0: string, 1: int}|null $before Its area and place before.
	 * @param array{0: string, 1: int}|null $after  Its area and place after.
	 * @param array<string, mixed>|null     $instance Its settings change.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function widget( string $id, ?array $before, ?array $after, ?array $instance ): ?array {
		$was_active = null !== $before && self::is_active( $before[0] );
		$is_active  = null !== $after && self::is_active( $after[0] );
		$exists     = null === $instance || null !== $instance['new'];

		if ( ! $was_active && $is_active ) {
			$action = 'added';
		} elseif ( $was_active && ! $is_active ) {
			$action = $exists ? 'removed' : 'deleted';
		} elseif ( $was_active && $is_active && $before[0] !== $after[0] ) {
			$action = 'moved';
		} elseif ( $was_active && null !== $instance && null !== $instance['old'] && null !== $instance['new'] ) {
			$action = 'changed';
		} elseif ( ! $was_active && ! $is_active && null !== $instance && null !== $instance['old'] && null === $instance['new'] && null !== $before ) {
			// Deleted from the inactive widgets.
			$action = 'deleted';
		} else {
			return null;
		}

		$type   = null !== $instance ? (string) $instance['type'] : (string) preg_replace( '/-\d+$/', '', $id );
		$values = null !== $instance ? ( $instance['new'] ?? $instance['old'] ) : null;
		$widget = [
			'id'     => $id,
			'type'   => $type,
			'action' => $action,
		];
		if ( is_array( $values ) && isset( $values['title'] ) && is_scalar( $values['title'] ) ) {
			$widget['title'] = (string) $values['title'];
		}
		if ( in_array( $action, [ 'added', 'moved', 'changed' ], true ) && null !== $after ) {
			$widget['area'] = $after[0];
		}
		if ( in_array( $action, [ 'moved', 'removed', 'deleted' ], true ) && null !== $before ) {
			$widget['old_area'] = $before[0];
		}

		if ( null !== $instance && is_array( $instance['old'] ) && is_array( $instance['new'] ) ) {
			$widget += self::setting_changes( $instance['old'], $instance['new'] );
			if ( 'changed' === $action && ! isset( $widget['changed'] ) ) {
				// Only the stored form of a value changed.
				return null;
			}
		}
		// For the job: a block widget has no title; its label comes from
		// its content (read from the option when the request didn't touch
		// its settings).
		if ( 'block' === $type && is_array( $values ) && isset( $values['content'] ) && is_string( $values['content'] ) ) {
			$widget['_content'] = $values['content'];
		}

		return $widget;
	}

	/**
	 * The settings that changed: their keys, the title before, short values
	 * before and after, and the text before and after for the job's diff.
	 *
	 * @param array<string, mixed> $old_settings The settings before.
	 * @param array<string, mixed> $new_settings The settings after.
	 *
	 * @return array<string, mixed>
	 */
	public static function setting_changes( array $old_settings, array $new_settings ): array {
		$out     = [];
		$changed = [];
		foreach ( array_unique( array_merge( array_keys( $old_settings ), array_keys( $new_settings ) ) ) as $key ) {
			$key    = (string) $key;
			$before = $old_settings[ $key ] ?? null;
			$after  = $new_settings[ $key ] ?? null;
			if ( self::value_string( $before ) === self::value_string( $after ) ) {
				continue;
			}
			$changed[] = $key;

			if ( 'title' === $key ) {
				$out['title_old'] = self::value_string( $before );
				continue;
			}
			if ( in_array( $key, self::CONTENT_KEYS, true ) && ( is_string( $before ) || is_string( $after ) ) ) {
				$out['_text'] = [
					'old' => is_string( $before ) ? $before : '',
					'new' => is_string( $after ) ? $after : '',
				];
				continue;
			}

			$old_text = self::value_string( $before );
			$new_text = self::value_string( $after );
			if ( strlen( $old_text ) <= self::MAX_VALUE && strlen( $new_text ) <= self::MAX_VALUE ) {
				$out['values'][ $key ] = [
					'old' => $old_text,
					'new' => $new_text,
				];
			}
		}

		if ( [] !== $changed ) {
			$out['changed'] = $changed;
		}

		return $out;
	}

	/**
	 * A setting's value as a string, so '1' and 1 or a reordered list of
	 * equal values aren't a change.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function value_string( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( null === $value ) {
			return '';
		}

		return (string) wp_json_encode( $value );
	}

	/**
	 * Every widget's area and place in it.
	 *
	 * @param mixed $sidebars The `sidebars_widgets` option.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	private static function places( $sidebars ): array {
		$places = [];
		if ( ! is_array( $sidebars ) ) {
			return $places;
		}
		foreach ( $sidebars as $area => $ids ) {
			if ( 'array_version' === $area || ! is_array( $ids ) ) {
				continue;
			}
			foreach ( array_values( $ids ) as $place => $id ) {
				if ( is_string( $id ) && ! isset( $places[ $id ] ) ) {
					$places[ $id ] = [ (string) $area, $place ];
				}
			}
		}

		return $places;
	}

	/**
	 * Whether an area shows on the site (not the inactive or orphaned ones).
	 *
	 * @param string $area The area ID.
	 *
	 * @return bool
	 */
	private static function is_active( string $area ): bool {
		return self::INACTIVE !== $area && 0 !== strpos( $area, 'orphaned_widgets' );
	}

	/**
	 * The areas whose widgets kept their area but changed order among
	 * themselves.
	 *
	 * @param array<string, array{0: string, 1: int}> $before  Places before.
	 * @param array<string, array{0: string, 1: int}> $after   Places after.
	 * @param array<int, array<string, mixed>>        $widgets The widgets reported.
	 *
	 * @return array<int, string>
	 */
	private static function reordered( array $before, array $after, array $widgets ): array {
		$listed = array_column( $widgets, 'action', 'id' );
		$orders = [];
		foreach ( $after as $id => [ $area, $place ] ) {
			if ( ! self::is_active( $area ) || ! isset( $before[ $id ] ) || $before[ $id ][0] !== $area || in_array( $listed[ $id ] ?? '', [ 'added', 'moved' ], true ) ) {
				continue;
			}
			$orders[ $area ][ $place ] = $before[ $id ][1];
		}

		$areas = [];
		foreach ( $orders as $area => $places ) {
			ksort( $places );
			$values = array_values( $places );
			$sorted = $values;
			sort( $sorted );
			if ( $values !== $sorted ) {
				$areas[] = (string) $area;
			}
		}

		return $areas;
	}

	/**
	 * Report what this request changed, once, at the end of the request.
	 *
	 * @return void
	 */
	public static function flush(): void {
		$row = self::row();
		self::reset();
		if ( [] === $row || Limiter::take( Limiter::KIND_WIDGET, 1 ) < 1 ) {
			return;
		}

		do_action( ContentEvent::hook( 'WidgetsChanged' ), $row ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The hooks start with scanfully_.
	}
}
