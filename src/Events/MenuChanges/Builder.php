<?php
/**
 * The menu event builder class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\MenuChanges;

/**
 * Class Builder
 *
 * The background job's part of a menu event: names the menu, its items and
 * theme locations, tells real moves from renumbering, and reads the items
 * of a block menu from its blocks, so a block menu reads like a classic
 * one. Returns [] when nothing is left to report (only numbers changed).
 *
 * Data sent: `id`, `name`, `kind` (`classic` or `block`), `items` (how
 * many), `locations` (`slug` and `name`, where a new menu shows or a
 * deleted one showed), and for MenuChanged `changes`: `name` and `status`
 * (`old`, `new`), `auto_add` (`old`, `new`), `added`, `removed` (with
 * `reason` `object_deleted` when its page or term was deleted), `renamed`
 * (`old`, `new`), `relinked` (`old`, `new`: URL or linked title), `moved`
 * (`parent`: the label of the item it is under now, '' for the top),
 * `details` (`changed`: the other fields), each item with `id`, `label` and
 * `type`, and `locations` (`added`, `removed`).
 *
 * @package Scanfully\Events\MenuChanges
 */
final class Builder {

	/**
	 * At most this many items are listed per change.
	 */
	private const MAX_LISTED = 2000;

	/**
	 * Labels longer than this are cut.
	 */
	private const MAX_LABEL = 200;

	/**
	 * Item fields that aren't the label, link or place.
	 */
	private const DETAIL_FIELDS = [ 'target', 'classes', 'xfn', 'attr_title', 'description' ];

	/**
	 * The event data with names added, or [] when nothing is left to report.
	 *
	 * @param string               $type The event type.
	 * @param array<string, mixed> $data The data the request noted.
	 *
	 * @return array<string, mixed>
	 */
	public static function resolve( string $type, array $data ): array {
		return 'block' === ( $data['kind'] ?? '' ) ? self::block( $type, $data ) : self::classic( $type, $data );
	}

	/**
	 * A classic menu's event.
	 *
	 * @param string               $type The event type.
	 * @param array<string, mixed> $data The data the request noted.
	 *
	 * @return array<string, mixed>
	 */
	private static function classic( string $type, array $data ): array {
		$menu_id = (int) ( $data['id'] ?? 0 );
		if ( 0 === $menu_id && isset( $data['_tt'] ) ) {
			// The request knew the menu by its term taxonomy ID only.
			$term       = get_term_by( 'term_taxonomy_id', (int) $data['_tt'], 'nav_menu' );
			$menu_id    = $term instanceof \WP_Term ? (int) $term->term_id : 0;
			$data['id'] = $menu_id;
		} elseif ( 0 === $menu_id && isset( $data['_item'] ) ) {
			// Or by one of the items: the menu it is on.
			$terms      = wp_get_object_terms( (int) $data['_item'], 'nav_menu', [ 'fields' => 'ids' ] );
			$menu_id    = is_array( $terms ) && [] !== $terms ? (int) reset( $terms ) : 0;
			$data['id'] = $menu_id;
		}
		unset( $data['_tt'], $data['_item'] );
		if ( ! isset( $data['name'] ) ) {
			$term         = get_term( $menu_id, 'nav_menu' );
			$data['name'] = $term instanceof \WP_Term ? self::plain( (string) $term->name ) : '';
		} else {
			$data['name'] = self::plain( (string) $data['name'] );
		}

		$locations = $data['_locations'] ?? [];
		$noted     = is_array( $data['_items'] ?? null ) ? $data['_items'] : [];
		unset( $data['_locations'], $data['_items'] );

		if ( 'MenuDeleted' === $type || 'MenuCreated' === $type ) {
			if ( 'MenuCreated' === $type ) {
				$data['items'] = count( self::current_items( $menu_id ) );
			}
			if ( is_array( $locations ) && [] !== $locations ) {
				$data['locations'] = self::locations( $locations );
			}

			return $data;
		}

		$current = self::current_items( $menu_id );
		$changes = is_array( $data['changes'] ?? null ) ? $data['changes'] : [];
		$moved   = [];
		if ( isset( $changes['name'] ) && is_array( $changes['name'] ) ) {
			$changes['name'] = [
				'old' => self::plain( (string) ( $changes['name']['old'] ?? '' ) ),
				'new' => self::plain( (string) ( $changes['name']['new'] ?? '' ) ),
			];
		}

		foreach ( $noted as $item_id => $entry ) {
			$item_id = (int) $item_id;
			$item    = $current[ $item_id ] ?? null;

			if ( ! empty( $entry['added'] ) ) {
				if ( null !== $item ) {
					$changes['added'][] = self::describe( $item );
				} elseif ( is_array( $entry['added'] ) ) {
					// Gone again or filtered out by now: as the request saw it.
					$changes['added'][] = self::describe_removed( $item_id, $entry['added'] );
				}
				continue;
			}

			if ( isset( $entry['removed'] ) ) {
				$removed = self::describe_removed( $item_id, (array) $entry['removed'] );
				if ( '' !== (string) ( $entry['removed']['reason'] ?? '' ) ) {
					$removed['reason'] = (string) $entry['removed']['reason'];
				}
				$changes['removed'][] = $removed;
				continue;
			}

			if ( null === $item ) {
				continue;
			}
			$old   = (array) ( $entry['old'] ?? [] );
			$new   = (array) ( $entry['new'] ?? [] );
			$label = self::describe( $item );

			if ( array_key_exists( 'title', $new ) ) {
				$before = '' !== $old['title'] ? self::plain( (string) $old['title'] ) : self::object_title( $item );
				if ( $before !== $label['label'] ) {
					$changes['renamed'][] = $label + [
						'old' => $before,
						'new' => $label['label'],
					];
				}
			}
			if ( array_key_exists( 'url', $new ) && 'custom' === (string) $item->type ) {
				$changes['relinked'][] = $label + [
					'old' => (string) $old['url'],
					'new' => (string) $new['url'],
				];
			} elseif ( array_key_exists( 'object_id', $new ) || array_key_exists( 'object', $new ) || array_key_exists( 'type', $new ) ) {
				$changes['relinked'][] = $label + [
					'old' => self::linked_title( (string) ( $old['type'] ?? $item->type ), (string) ( $old['object'] ?? $item->object ), (int) ( $old['object_id'] ?? $item->object_id ) ),
					'new' => self::object_title( $item ),
				];
			}
			if ( array_key_exists( 'parent', $new ) ) {
				$parent             = (int) $new['parent'];
				$moved[ $item_id ]  = true;
				$changes['moved'][] = $label + [ 'parent' => $parent > 0 && isset( $current[ $parent ] ) ? self::label( $current[ $parent ] ) : '' ];
			}
			$details = array_values( array_intersect( self::DETAIL_FIELDS, array_keys( $new ) ) );
			if ( [] !== $details ) {
				$changes['details'][] = $label + [ 'changed' => $details ];
			}
		}

		// Moves: the items whose place among the others under the same
		// parent changed, not every item renumbered because one above it
		// moved, was added or removed. The Menus screen numbers a whole menu,
		// the Customizer each parent's items, REST gives new items 1: places
		// are compared within a parent, with ties broken by ID.
		$groups = [];
		foreach ( $current as $item_id => $item ) {
			if ( ! empty( $noted[ $item_id ]['added'] ) || isset( $moved[ $item_id ] ) ) {
				continue;
			}
			$before = isset( $noted[ $item_id ]['old']['order'] ) ? (int) $noted[ $item_id ]['old']['order'] : (int) $item->menu_order;
			$groups[ (int) $item->menu_item_parent ][ $item_id ] = $before * 1000000000000 + $item_id;
		}
		foreach ( $groups as $sequence ) {
			foreach ( self::out_of_order( $sequence ) as $item_id ) {
				$parent             = (int) $current[ $item_id ]->menu_item_parent;
				$changes['moved'][] = self::describe( $current[ $item_id ] ) + [ 'parent' => $parent > 0 && isset( $current[ $parent ] ) ? self::label( $current[ $parent ] ) : '' ];
			}
		}

		if ( is_array( $locations ) && ( [] !== ( $locations['added'] ?? [] ) || [] !== ( $locations['removed'] ?? [] ) ) ) {
			$changes['locations'] = [
				'added'   => self::locations( (array) ( $locations['added'] ?? [] ) ),
				'removed' => self::locations( (array) ( $locations['removed'] ?? [] ) ),
			];
		}

		if ( [] === $changes ) {
			return [];
		}

		$data['items']   = count( $current );
		$data['changes'] = self::cap_lists( $changes );

		return $data;
	}

	/**
	 * A block menu's event.
	 *
	 * @param string               $type The event type.
	 * @param array<string, mixed> $data The data the request noted.
	 *
	 * @return array<string, mixed>
	 */
	private static function block( string $type, array $data ): array {
		$data['name'] = self::plain( (string) ( $data['name'] ?? '' ) );

		if ( 'MenuCreated' === $type || 'MenuDeleted' === $type ) {
			$data['items'] = count( self::block_items( (string) ( $data['_content'] ?? '' ) ) );
			unset( $data['_content'] );

			return $data;
		}

		$changes = is_array( $data['changes'] ?? null ) ? $data['changes'] : [];
		$after   = self::block_items( (string) ( $data['_after'] ?? '' ) );
		if ( isset( $data['_before'] ) ) {
			$changes = array_merge( $changes, self::block_changes( self::block_items( (string) $data['_before'] ), $after ) );
		}
		unset( $data['_before'], $data['_after'] );

		if ( [] === $changes ) {
			return [];
		}

		$data['items']   = count( $after );
		$data['changes'] = self::cap_lists( $changes );

		return $data;
	}

	/**
	 * What changed between two versions of a block menu's items.
	 *
	 * @param array<int, array<string, mixed>> $before The items before.
	 * @param array<int, array<string, mixed>> $after  The items after.
	 *
	 * @return array<string, mixed>
	 */
	public static function block_changes( array $before, array $after ): array {
		// Pair the items: the same linked post or term, else the same URL,
		// else the same block with the same label.
		$unmatched = [];
		foreach ( $before as $index => $item ) {
			$unmatched[ $item['key'] ][] = $index;
		}
		$pairs = [];
		foreach ( $after as $index => $item ) {
			if ( ! empty( $unmatched[ $item['key'] ] ) ) {
				$pairs[ $index ] = array_shift( $unmatched[ $item['key'] ] );
			}
		}
		// A link that now goes somewhere else: the same label in the same
		// submenu.
		$left = [];
		foreach ( $unmatched as $indexes ) {
			foreach ( $indexes as $old_index ) {
				$left[ $before[ $old_index ]['parent'] . "\n" . $before[ $old_index ]['label'] ][] = $old_index;
			}
		}
		foreach ( $after as $index => $item ) {
			$key = $item['parent'] . "\n" . $item['label'];
			if ( isset( $pairs[ $index ] ) || empty( $left[ $key ] ) || '' === $item['url'] ) {
				continue;
			}
			$old_index       = array_shift( $left[ $key ] );
			$pairs[ $index ] = $old_index;
			foreach ( $unmatched as $unmatched_key => $indexes ) {
				$unmatched[ $unmatched_key ] = array_values( array_diff( $indexes, [ $old_index ] ) );
			}
		}
		ksort( $pairs );

		$changes = [];
		foreach ( $after as $index => $item ) {
			if ( ! isset( $pairs[ $index ] ) ) {
				$changes['added'][] = self::block_entry( $item );
				continue;
			}

			$old   = $before[ $pairs[ $index ] ];
			$entry = self::block_entry( $item );
			if ( $old['label'] !== $item['label'] ) {
				$changes['renamed'][] = $entry + [
					'old' => $old['label'],
					'new' => $item['label'],
				];
			}
			if ( $old['url'] !== $item['url'] ) {
				$changes['relinked'][] = $entry + [
					'old' => $old['url'],
					'new' => $item['url'],
				];
			}
			if ( $old['parent'] !== $item['parent'] ) {
				$changes['moved'][] = $entry + [ 'parent' => $item['parent'] ];
			}
			if ( $old['attrs'] !== $item['attrs'] ) {
				$changes['details'][] = $entry + [ 'changed' => array_keys( self::differing( $old['attrs'], $item['attrs'] ) ) ];
			}
		}
		foreach ( $unmatched as $indexes ) {
			foreach ( $indexes as $index ) {
				$changes['removed'][] = self::block_entry( $before[ $index ] );
			}
		}

		// Moves among the items that are in both, in the same parent.
		$sequence = [];
		foreach ( $pairs as $index => $old_index ) {
			$sequence[ $index ] = $old_index;
		}
		foreach ( self::out_of_order( $sequence ) as $index ) {
			if ( $before[ $pairs[ $index ] ]['parent'] === $after[ $index ]['parent'] ) {
				$changes['moved'][] = self::block_entry( $after[ $index ] ) + [ 'parent' => $after[ $index ]['parent'] ];
			}
		}

		return $changes;
	}

	/**
	 * The keys whose values differ between two attribute lists.
	 *
	 * @param array<string, mixed> $before The attributes before.
	 * @param array<string, mixed> $after  The attributes after.
	 *
	 * @return array<string, true>
	 */
	private static function differing( array $before, array $after ): array {
		$keys = [];
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $key ) {
			if ( ( $before[ $key ] ?? null ) !== ( $after[ $key ] ?? null ) ) {
				$keys[ (string) $key ] = true;
			}
		}

		return $keys;
	}

	/**
	 * The keys of a sequence (key => place before, in today's order) that
	 * moved: those outside its longest run that kept its order.
	 *
	 * @param array<int|string, int> $sequence The places before, in today's order.
	 *
	 * @return array<int, int|string>
	 */
	public static function out_of_order( array $sequence ): array {
		$keys   = array_keys( $sequence );
		$values = array_values( $sequence );
		$count  = count( $values );
		if ( $count < 2 ) {
			return [];
		}

		// Longest increasing subsequence, n log n, keeping where each came from.
		$tails = [];
		$from  = array_fill( 0, $count, -1 );
		$ends  = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$low  = 0;
			$high = count( $tails );
			while ( $low < $high ) {
				$mid = intdiv( $low + $high, 2 );
				if ( $values[ $tails[ $mid ] ] < $values[ $i ] ) {
					$low = $mid + 1;
				} else {
					$high = $mid;
				}
			}
			$from[ $i ]    = $low > 0 ? $tails[ $low - 1 ] : -1;
			$tails[ $low ] = $i;
			$ends[ $low ]  = $i;
		}

		$keep = [];
		for ( $i = $ends[ count( $tails ) - 1 ]; $i >= 0; $i = $from[ $i ] ) {
			$keep[ $i ] = true;
		}

		$moved = [];
		foreach ( $keys as $i => $key ) {
			if ( ! isset( $keep[ $i ] ) ) {
				$moved[] = $key;
			}
		}

		return $moved;
	}

	/**
	 * The items of a block menu, in order: navigation links and submenus
	 * with their label, link and the submenu they are in, and other blocks
	 * (page list, search, social icons) by their block title.
	 *
	 * @param string $content The wp_navigation post's content.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function block_items( string $content ): array {
		$items = [];
		if ( '' !== trim( $content ) ) {
			self::walk( parse_blocks( $content ), '', $items );
		}

		return $items;
	}

	/**
	 * Add the items of a list of blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks The blocks.
	 * @param string                           $submenu The label of the submenu they are in, '' for the top.
	 * @param array<int, array<string, mixed>> $items  The items so far.
	 *
	 * @return void
	 */
	private static function walk( array $blocks, string $submenu, array &$items ): void {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];

			if ( in_array( $name, [ 'core/navigation-link', 'core/navigation-submenu' ], true ) ) {
				$type  = (string) ( $attrs['type'] ?? '' );
				$id    = (int) ( $attrs['id'] ?? 0 );
				$url   = (string) ( $attrs['url'] ?? '' );
				$label = self::plain( (string) ( $attrs['label'] ?? '' ) );
				unset( $attrs['label'], $attrs['url'], $attrs['id'], $attrs['type'], $attrs['kind'] );
				$key = $id > 0 ? $type . ':' . $id : ( '' !== $url ? 'url:' . $url : $name . ':' . $label );
			} else {
				$type  = $name;
				$id    = 0;
				$url   = '';
				$label = 'core/home-link' === $name && isset( $attrs['label'] ) ? self::plain( (string) $attrs['label'] ) : self::block_title( $name );
				$key   = $name . ':' . $label;
			}
			ksort( $attrs );

			$items[] = [
				'key'    => $key,
				'id'     => $id,
				'label'  => $label,
				'url'    => $url,
				'type'   => $type,
				'parent' => $submenu,
				'attrs'  => $attrs,
			];

			if ( 'core/navigation-submenu' === $name && is_array( $block['innerBlocks'] ?? null ) ) {
				self::walk( $block['innerBlocks'], $label, $items );
			}
		}
	}

	/**
	 * A block menu item as an event lists it.
	 *
	 * @param array<string, mixed> $item The item.
	 *
	 * @return array{id: int, label: string, type: string}
	 */
	private static function block_entry( array $item ): array {
		return [
			'id'    => (int) $item['id'],
			'label' => (string) $item['label'],
			'type'  => self::block_type_label( (string) $item['type'] ),
		];
	}

	/**
	 * The type of a block menu item in words: the post type or taxonomy of
	 * a link, "Custom Link", or the block's title.
	 *
	 * @param string $type The link type (`page`, `post`, `category`, `tag`, `custom`) or block name.
	 *
	 * @return string
	 */
	private static function block_type_label( string $type ): string {
		if ( false !== strpos( $type, '/' ) ) {
			return self::block_title( $type );
		}
		if ( '' === $type || 'custom' === $type ) {
			return __( 'Custom Link', 'scanfully' );
		}

		$object = post_type_exists( $type ) ? get_post_type_object( $type ) : get_taxonomy( 'tag' === $type ? 'post_tag' : $type );

		return is_object( $object ) && isset( $object->labels->singular_name ) ? (string) $object->labels->singular_name : $type;
	}

	/**
	 * A block's title, or its name when it isn't registered.
	 *
	 * @param string $name The block name.
	 *
	 * @return string
	 */
	private static function block_title( string $name ): string {
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );

		return $type instanceof \WP_Block_Type && '' !== (string) $type->title ? (string) $type->title : $name;
	}

	/**
	 * The current published items of a classic menu, by ID, in menu order.
	 *
	 * @param int $menu_id The menu's term ID.
	 *
	 * @return array<int, object>
	 */
	private static function current_items( int $menu_id ): array {
		if ( $menu_id < 1 ) {
			return [];
		}

		// Not wp_get_nav_menu_items(): plugins that hide items from some
		// visitors filter it, and the job runs without a signed-in user.
		$posts = get_posts(
			[
				'post_type'        => 'nav_menu_item',
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => 'menu_order ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'tax_query'        => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The background job; this is how WordPress reads a menu.
					[
						'taxonomy' => 'nav_menu',
						'field'    => 'term_taxonomy_id',
						'terms'    => [ self::menu_tt( $menu_id ) ],
					],
				],
			]
		);
		$out   = [];
		foreach ( $posts as $post ) {
			$item = wp_setup_nav_menu_item( $post );
			if ( is_object( $item ) && isset( $item->db_id ) ) {
				$out[ (int) $item->db_id ] = $item;
			}
		}

		return $out;
	}

	/**
	 * A classic menu's term taxonomy ID, 0 when it is gone.
	 *
	 * @param int $menu_id The menu's term ID.
	 *
	 * @return int
	 */
	private static function menu_tt( int $menu_id ): int {
		$term = get_term( $menu_id, 'nav_menu' );

		return $term instanceof \WP_Term ? (int) $term->term_taxonomy_id : 0;
	}

	/**
	 * A classic item as an event lists it.
	 *
	 * @param object $item The item (wp_setup_nav_menu_item()).
	 *
	 * @return array{id: int, label: string, type: string}
	 */
	private static function describe( object $item ): array {
		return [
			'id'    => (int) $item->db_id,
			'label' => self::label( $item ),
			'type'  => (string) ( $item->type_label ?? '' ),
		];
	}

	/**
	 * A classic item's label: its own, or the title of what it links to.
	 *
	 * @param object $item The item.
	 *
	 * @return string
	 */
	private static function label( object $item ): string {
		return self::plain( (string) ( $item->title ?? '' ) );
	}

	/**
	 * A deleted classic item as an event lists it, from what the request
	 * read before the delete.
	 *
	 * @param int                  $item_id The item ID.
	 * @param array<string, mixed> $removed What the item was.
	 *
	 * @return array{id: int, label: string, type: string}
	 */
	private static function describe_removed( int $item_id, array $removed ): array {
		$type   = (string) ( $removed['type'] ?? '' );
		$object = (string) ( $removed['object'] ?? '' );
		$label  = self::plain( (string) ( $removed['title'] ?? '' ) );
		if ( '' === $label ) {
			$label = self::linked_title( $type, $object, (int) ( $removed['object_id'] ?? 0 ) );
		}

		return [
			'id'    => $item_id,
			'label' => $label,
			'type'  => self::type_label( $type, $object ),
		];
	}

	/**
	 * The title of what a classic item links to now.
	 *
	 * @param object $item The item.
	 *
	 * @return string
	 */
	private static function object_title( object $item ): string {
		if ( 'custom' === (string) $item->type ) {
			return self::label( $item );
		}

		return self::linked_title( (string) $item->type, (string) $item->object, (int) $item->object_id );
	}

	/**
	 * The title of a post or term an item links to; '' when it is gone.
	 *
	 * @param string $type        The item type (`post_type`, `taxonomy`, `post_type_archive`, `custom`).
	 * @param string $object_name The post type or taxonomy.
	 * @param int    $object_id   The post or term ID.
	 *
	 * @return string
	 */
	private static function linked_title( string $type, string $object_name, int $object_id ): string {
		if ( 'post_type' === $type && $object_id > 0 ) {
			$post = get_post( $object_id );
			return $post instanceof \WP_Post ? self::plain( (string) $post->post_title ) : '';
		}
		if ( 'taxonomy' === $type && $object_id > 0 ) {
			$term = get_term( $object_id, $object_name );
			return $term instanceof \WP_Term ? self::plain( (string) $term->name ) : '';
		}
		if ( 'post_type_archive' === $type ) {
			$post_type = get_post_type_object( $object_name );
			return is_object( $post_type ) ? self::plain( (string) $post_type->labels->archives ) : '';
		}

		return '';
	}

	/**
	 * The type of a classic item in words, as the Menus screen shows it.
	 *
	 * @param string $type        The item type.
	 * @param string $object_name The post type or taxonomy.
	 *
	 * @return string
	 */
	private static function type_label( string $type, string $object_name ): string {
		if ( 'post_type' === $type ) {
			$post_type = get_post_type_object( $object_name );
			return is_object( $post_type ) ? (string) $post_type->labels->singular_name : $object_name;
		}
		if ( 'taxonomy' === $type ) {
			$taxonomy = get_taxonomy( $object_name );
			return is_object( $taxonomy ) ? (string) $taxonomy->labels->singular_name : $object_name;
		}
		if ( 'post_type_archive' === $type ) {
			return __( 'Post Type Archive', 'scanfully' );
		}

		return __( 'Custom Link', 'scanfully' );
	}

	/**
	 * Theme locations by slug and name.
	 *
	 * @param array<int, string> $slugs The location slugs.
	 *
	 * @return array<int, array{slug: string, name: string}>
	 */
	private static function locations( array $slugs ): array {
		$registered = get_registered_nav_menus();
		$out        = [];
		foreach ( $slugs as $slug ) {
			$slug  = (string) $slug;
			$out[] = [
				'slug' => $slug,
				'name' => isset( $registered[ $slug ] ) ? self::plain( (string) $registered[ $slug ] ) : $slug,
			];
		}

		return $out;
	}

	/**
	 * The changes with every list cut to MAX_LISTED entries.
	 *
	 * @param array<string, mixed> $changes The changes.
	 *
	 * @return array<string, mixed>
	 */
	private static function cap_lists( array $changes ): array {
		foreach ( [ 'added', 'removed', 'renamed', 'relinked', 'moved', 'details' ] as $list ) {
			if ( isset( $changes[ $list ] ) && count( $changes[ $list ] ) > self::MAX_LISTED ) {
				$changes[ $list ] = array_slice( $changes[ $list ], 0, self::MAX_LISTED );
			}
		}

		return $changes;
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
