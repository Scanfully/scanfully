<?php
/**
 * The menu change collector class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\MenuChanges;

use Scanfully\Events\Content\Limiter;
use Scanfully\Events\ContentEvent;

/**
 * Class Collector
 *
 * Notes what a request changes in navigation menus and reports it at the
 * end of the request: one MenuCreated, MenuChanged or MenuDeleted row per
 * menu. Classic menus (Appearance, Menus, the Customizer, REST, WP-CLI) are
 * a `nav_menu` term with `nav_menu_item` posts; block theme menus are a
 * `wp_navigation` post whose content is blocks.
 *
 * The request only compares what WordPress passes to its hooks and keeps
 * the old values it has in memory; item labels, moves and the items of a
 * block menu are worked out by the background job (Builder).
 *
 * The Menus screen saves every item of a menu, and moving one item
 * renumbers the ones below it; the job tells real moves from renumbering.
 *
 * @package Scanfully\Events\MenuChanges
 */
final class Collector {

	/**
	 * Item fields kept in post meta, by meta key.
	 */
	public const ITEM_META = [
		'_menu_item_menu_item_parent' => 'parent',
		'_menu_item_url'              => 'url',
		'_menu_item_object_id'        => 'object_id',
		'_menu_item_object'           => 'object',
		'_menu_item_type'             => 'type',
		'_menu_item_target'           => 'target',
		'_menu_item_classes'          => 'classes',
		'_menu_item_xfn'              => 'xfn',
	];

	/**
	 * At most this many items of one request are noted.
	 */
	private const MAX_ITEMS = 5000;

	/**
	 * Menus noted, by `classic:{term ID}` or `block:{post ID}`.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $menus = [];

	/**
	 * Classic menu items noted, by item ID: `menu` (term ID, 0 while
	 * unknown), `added`, `old` and `new` fields, `removed` (what the item
	 * was, for one deleted), `tt` (its menu's term taxonomy ID).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $items = [];

	/**
	 * Theme locations whose menu changed: the first old and the last new
	 * menu ID per location.
	 *
	 * @var array<string, array{old: int, new: int}>
	 */
	private static array $locations = [];

	/**
	 * The menus that add new top-level pages by themselves, before and
	 * after, or null when that didn't change.
	 *
	 * @var array{old: array<int, int>, new: array<int, int>}|null
	 */
	private static ?array $auto_add = null;

	/**
	 * The term taxonomy ID of each classic menu saved in this request, by
	 * term ID, from the term cache: it names the menu of an item deleted
	 * in the same save.
	 *
	 * @var array<int, int>
	 */
	private static array $menu_tt = [];

	/**
	 * The page, post or term being deleted, whose menu items WordPress then
	 * deletes: its title or name and type, for an item that showed it.
	 *
	 * @var array{label: string, type: string, object: string}|null
	 */
	private static ?array $deleting = null;

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_action( 'wp_create_nav_menu', [ self::class, 'note_created' ], 10, 2 );
		add_filter( 'wp_update_term_data', [ self::class, 'note_renaming' ], PHP_INT_MAX, 3 );
		add_action( 'delete_term', [ self::class, 'note_deleted' ], 10, 5 );
		add_action( 'wp_add_nav_menu_item', [ self::class, 'note_item_added' ], 10, 3 );
		add_action( 'wp_update_nav_menu_item', [ self::class, 'note_item_saved' ], 10, 2 );
		add_action( 'post_updated', [ self::class, 'note_item_updated' ], 10, 3 );
		add_action( 'update_post_meta', [ self::class, 'note_item_meta' ], 10, 4 );
		add_action( 'before_delete_post', [ self::class, 'note_deleting' ], 10, 2 );
		// Before WordPress deletes the menu items of a deleted page or term (priority 10).
		add_action( 'delete_post', [ self::class, 'note_object_deleting' ], 9, 2 );
		add_action( 'delete_term', [ self::class, 'note_term_deleting' ], 9, 4 );
		add_action( 'delete_term_relationships', [ self::class, 'note_item_menu' ], 10, 3 );
		add_filter( 'pre_set_theme_mod_nav_menu_locations', [ self::class, 'note_locations' ], PHP_INT_MAX, 2 );
		add_action( 'updated_option', [ self::class, 'note_option' ], 10, 3 );
		add_action( 'wp_after_insert_post', [ self::class, 'note_block_saved' ], 10, 4 );
		add_action( 'shutdown', [ self::class, 'flush' ], 1, 0 );
	}

	/**
	 * Forget what was noted (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$menus     = [];
		self::$items     = [];
		self::$locations = [];
		self::$auto_add  = null;
		self::$menu_tt   = [];
		self::$deleting  = null;
	}

	/**
	 * Whether this request's menu changes are left out: imports, installs,
	 * the remapping WordPress does when the theme is switched (the theme
	 * switch is its own row), and changes to another site of a multisite
	 * network (they would be reported on the wrong site).
	 *
	 * @return bool
	 */
	public static function is_quiet(): bool {
		return ( defined( 'WP_IMPORTING' ) && WP_IMPORTING )
			|| wp_installing()
			|| ( function_exists( 'ms_is_switched' ) && ms_is_switched() )
			|| did_action( 'switch_theme' ) > 0
			|| doing_action( 'after_switch_theme' );
	}

	/**
	 * Whether the request only reads: the block editor makes a fallback
	 * navigation menu by itself when it loads, and that is nobody's change.
	 * Cron runs (a scheduled Customizer change) and WP-CLI (which calls
	 * every command a GET) count as changes.
	 *
	 * @return bool
	 */
	public static function is_read_request(): bool {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		return in_array( $method, [ 'GET', 'HEAD' ], true );
	}

	/**
	 * The note of a menu, made when missing.
	 *
	 * @param string $kind `classic` or `block`.
	 * @param int    $id   The term or post ID.
	 *
	 * @return string The key.
	 */
	private static function menu( string $kind, int $id ): string {
		$key = $kind . ':' . $id;
		if ( ! isset( self::$menus[ $key ] ) ) {
			self::$menus[ $key ] = [
				'kind' => $kind,
				'id'   => $id,
			];
		}

		return $key;
	}

	/**
	 * The note of a classic item, made when missing; null past MAX_ITEMS.
	 *
	 * @param int $item_id The item ID.
	 *
	 * @return bool Whether the item is noted.
	 */
	private static function item( int $item_id ): bool {
		if ( isset( self::$items[ $item_id ] ) ) {
			return true;
		}
		if ( count( self::$items ) >= self::MAX_ITEMS ) {
			return false;
		}

		self::$items[ $item_id ] = [
			'menu' => 0,
			'old'  => [],
			'new'  => [],
		];

		return true;
	}

	/**
	 * `wp_create_nav_menu`: a classic menu was made.
	 *
	 * @param mixed $term_id   The menu's term ID.
	 * @param mixed $menu_data The menu data: `menu-name`.
	 *
	 * @return void
	 */
	public static function note_created( $term_id, $menu_data = [] ): void {
		if ( self::is_quiet() ) {
			return;
		}

		$key                            = self::menu( 'classic', (int) $term_id );
		self::$menus[ $key ]['created'] = true;
		// Every core caller passes the name slashed.
		self::$menus[ $key ]['name'] = is_array( $menu_data ) ? wp_unslash( (string) ( $menu_data['menu-name'] ?? '' ) ) : '';
	}

	/**
	 * `wp_update_term_data`: a classic menu gets a new name. The term is in
	 * the cache: wp_update_term() just read it.
	 *
	 * @param mixed $data     The term data to be saved.
	 * @param mixed $term_id  The term ID.
	 * @param mixed $taxonomy The taxonomy.
	 *
	 * @return mixed The data, unchanged.
	 */
	public static function note_renaming( $data, $term_id, $taxonomy ) {
		if ( 'nav_menu' !== $taxonomy || ! is_array( $data ) || ! isset( $data['name'] ) || self::is_quiet() ) {
			return $data;
		}

		$term = get_term( (int) $term_id, 'nav_menu' );
		if ( ! $term instanceof \WP_Term || (string) $term->name === (string) $data['name'] ) {
			return $data;
		}

		$key = self::menu( 'classic', (int) $term_id );
		if ( ! isset( self::$menus[ $key ]['old_name'] ) ) {
			self::$menus[ $key ]['old_name'] = (string) $term->name;
		}
		self::$menus[ $key ]['name'] = (string) $data['name'];

		return $data;
	}

	/**
	 * `delete_term`: a classic menu was deleted. Its items were deleted just
	 * before (wp_delete_nav_menu()).
	 *
	 * @param mixed $term_id      The term ID.
	 * @param mixed $tt_id        The term taxonomy ID.
	 * @param mixed $taxonomy     The taxonomy.
	 * @param mixed $deleted_term The term as it was.
	 * @param mixed $object_ids   The objects it had (its items were deleted before).
	 *
	 * @return void
	 */
	public static function note_deleted( $term_id, $tt_id, $taxonomy, $deleted_term = null, $object_ids = [] ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The hook passes it.
		if ( 'nav_menu' !== $taxonomy || self::is_quiet() ) {
			return;
		}

		$key                            = self::menu( 'classic', (int) $term_id );
		self::$menus[ $key ]['deleted'] = true;
		self::$menus[ $key ]['tt']      = (int) $tt_id;
		if ( is_object( $deleted_term ) && ! isset( self::$menus[ $key ]['old_name'] ) ) {
			self::$menus[ $key ]['name'] = (string) ( $deleted_term->name ?? '' );
		}
	}

	/**
	 * `wp_add_nav_menu_item`: an item was made. The Menus screen makes a
	 * draft as soon as one is put on the menu and publishes it on Save
	 * Menu; the Customizer, REST and WP-CLI make published items.
	 *
	 * @param mixed $menu_id The menu's term ID.
	 * @param mixed $item_id The item ID.
	 * @param mixed $args    The item data.
	 *
	 * @return void
	 */
	public static function note_item_added( $menu_id, $item_id, $args = [] ): void {
		if ( self::is_quiet() || ! is_array( $args ) || 'publish' !== ( $args['menu-item-status'] ?? '' ) || ! self::item( (int) $item_id ) ) {
			return;
		}

		self::$items[ (int) $item_id ]['added'] = true;
		self::$items[ (int) $item_id ]['menu']  = (int) $menu_id;
		// For the job, should the item be gone or filtered out by then.
		self::$items[ (int) $item_id ]['noted'] = [
			'title'     => (string) ( $args['menu-item-title'] ?? '' ),
			'type'      => (string) ( $args['menu-item-type'] ?? '' ),
			'object'    => (string) ( $args['menu-item-object'] ?? '' ),
			'object_id' => (string) ( $args['menu-item-object-id'] ?? '' ),
			'url'       => (string) ( $args['menu-item-url'] ?? '' ),
		];
	}

	/**
	 * `wp_update_nav_menu_item`: an item was saved; this names its menu.
	 *
	 * @param mixed $menu_id The menu's term ID.
	 * @param mixed $item_id The item ID.
	 *
	 * @return void
	 */
	public static function note_item_saved( $menu_id, $item_id ): void {
		$menu_id = (int) $menu_id;
		if ( $menu_id < 1 ) {
			return;
		}
		if ( isset( self::$items[ (int) $item_id ] ) ) {
			self::$items[ (int) $item_id ]['menu'] = $menu_id;
		}
		// The menu's term was just read (wp_get_nav_menu_object()); only the
		// cache is asked, never the database.
		if ( ! isset( self::$menu_tt[ $menu_id ] ) ) {
			$term = wp_cache_get( $menu_id, 'terms' );
			if ( is_object( $term ) && isset( $term->term_taxonomy_id ) ) {
				self::$menu_tt[ $menu_id ] = (int) $term->term_taxonomy_id;
			}
		}
	}

	/**
	 * `post_updated`: an item's label, place, description or status
	 * changed; a draft becoming published was put on the menu.
	 *
	 * @param mixed $post_id The item ID.
	 * @param mixed $after   The item after.
	 * @param mixed $before  The item before.
	 *
	 * @return void
	 */
	public static function note_item_updated( $post_id, $after, $before ): void {
		if ( ! is_object( $after ) || ! is_object( $before ) || 'nav_menu_item' !== ( $after->post_type ?? '' ) || self::is_quiet() ) {
			return;
		}

		$item_id = (int) $post_id;
		if ( 'publish' !== $after->post_status ) {
			// Set back to draft (REST): off the menu.
			if ( 'publish' === $before->post_status && empty( self::$items[ $item_id ]['added'] ) && self::item( $item_id ) ) {
				self::$items[ $item_id ]['removed'] = self::item_facts( $item_id, $before );
			}
			return;
		}
		if ( 'publish' !== $before->post_status ) {
			if ( self::item( $item_id ) ) {
				self::$items[ $item_id ]['added'] = true;
				self::$items[ $item_id ]['noted'] = self::item_facts( $item_id, $after );
				unset( self::$items[ $item_id ]['noted']['reason'] );
			}
			return;
		}

		$fields = [
			'title'       => 'post_title',
			'order'       => 'menu_order',
			'attr_title'  => 'post_excerpt',
			'description' => 'post_content',
		];
		foreach ( $fields as $field => $column ) {
			// WordPress stores a lone space as the description of an item
			// without one, and clears it again when the label changes.
			$old = 'description' === $field ? trim( (string) $before->$column ) : (string) $before->$column;
			$new = 'description' === $field ? trim( (string) $after->$column ) : (string) $after->$column;
			if ( $old === $new ) {
				continue;
			}
			if ( ! self::item( $item_id ) ) {
				return;
			}
			self::change( $item_id, $field, $old, $new );
		}
	}

	/**
	 * `update_post_meta` (before the update): an item's link, parent,
	 * target, classes or XFN changed. WordPress only calls this for a real
	 * change; the old value is taken from the meta cache, which loading the
	 * menu filled.
	 *
	 * @param mixed $meta_id   The meta ID.
	 * @param mixed $object_id The item ID.
	 * @param mixed $meta_key  The meta key.
	 * @param mixed $value     The new value.
	 *
	 * @return void
	 */
	public static function note_item_meta( $meta_id, $object_id, $meta_key, $value ): void {
		if ( ! is_string( $meta_key ) || ! isset( self::ITEM_META[ $meta_key ] ) || self::is_quiet() ) {
			return;
		}

		$item_id = (int) $object_id;
		// A new item's fields are its first values, not changes.
		if ( ! empty( self::$items[ $item_id ]['added'] ) ) {
			return;
		}

		$cache = wp_cache_get( $item_id, 'post_meta' );
		$old   = is_array( $cache ) && isset( $cache[ $meta_key ][0] ) ? maybe_unserialize( $cache[ $meta_key ][0] ) : null;
		if ( ! self::item( $item_id ) ) {
			return;
		}

		self::change( $item_id, self::ITEM_META[ $meta_key ], self::meta_string( $old ), self::meta_string( $value ) );
	}

	/**
	 * Keep the first old and the last new value of an item's field.
	 *
	 * @param int    $item_id The item ID.
	 * @param string $field   The field.
	 * @param string $before  The value before.
	 * @param string $after   The value after.
	 *
	 * @return void
	 */
	private static function change( int $item_id, string $field, string $before, string $after ): void {
		if ( ! array_key_exists( $field, self::$items[ $item_id ]['old'] ) ) {
			self::$items[ $item_id ]['old'][ $field ] = $before;
		}
		self::$items[ $item_id ]['new'][ $field ] = $after;
	}

	/**
	 * A meta value as a string (the classes are a list).
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function meta_string( $value ): string {
		if ( is_array( $value ) ) {
			return implode( ' ', array_filter( array_map( 'strval', $value ), static fn( string $part ): bool => '' !== $part ) );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * `before_delete_post`: a published item is taken off its menu, or a
	 * block menu is deleted. What the job needs to name it is read now.
	 *
	 * @param mixed $post_id The post ID.
	 * @param mixed $post    The post.
	 *
	 * @return void
	 */
	public static function note_deleting( $post_id, $post = null ): void {
		if ( ! is_object( $post ) || self::is_quiet() ) {
			return;
		}

		if ( 'wp_navigation' === $post->post_type ) {
			if ( 'auto-draft' === $post->post_status ) {
				return;
			}
			$key = self::menu( 'block', (int) $post_id );
			if ( ! isset( self::$menus[ $key ]['before'] ) ) {
				self::$menus[ $key ]['before'] = self::block_state( $post );
			}
			self::$menus[ $key ]['deleted'] = true;
			return;
		}

		if ( 'nav_menu_item' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}

		$item_id = (int) $post_id;
		if ( ! empty( self::$items[ $item_id ]['added'] ) ) {
			// Put on the menu and taken off again in the same request.
			unset( self::$items[ $item_id ] );
			return;
		}
		if ( ! self::item( $item_id ) ) {
			return;
		}

		self::$items[ $item_id ]['removed'] = self::item_facts( $item_id, $post );
	}

	/**
	 * What the job needs of an item put on or taken off its menu, should it
	 * be gone by then: its label and link from the post and the meta cache
	 * (never the database; deleting a whole menu deletes items whose meta
	 * isn't cached, and only counts them), and whether its page or term was
	 * deleted.
	 *
	 * @param int    $item_id The item ID.
	 * @param object $post    The item.
	 *
	 * @return array<string, string>
	 */
	private static function item_facts( int $item_id, object $post ): array {
		$cache   = wp_cache_get( $item_id, 'post_meta' );
		$removed = [
			'title'  => (string) $post->post_title,
			// The page, post or term it linked to was deleted (WordPress
			// takes its items off every menu).
			'reason' => ( doing_action( 'delete_post' ) || doing_action( 'delete_term' ) ) && null !== self::$deleting ? 'object_deleted' : '',
		];
		foreach ( self::ITEM_META as $meta_key => $field ) {
			if ( in_array( $field, [ 'url', 'object_id', 'object', 'type' ], true ) ) {
				$removed[ $field ] = is_array( $cache ) && isset( $cache[ $meta_key ][0] ) ? (string) $cache[ $meta_key ][0] : '';
			}
		}
		// An item that shows the title of its page has no label of its own.
		if ( 'object_deleted' === $removed['reason'] ) {
			if ( '' === $removed['title'] ) {
				$removed['title'] = self::$deleting['label'];
			}
			$removed['type']   = self::$deleting['type'];
			$removed['object'] = self::$deleting['object'];
		}

		return $removed;
	}

	/**
	 * `delete_post` (priority 9): a post is being deleted; WordPress deletes
	 * the menu items that show it next.
	 *
	 * @param mixed $post_id The post ID.
	 * @param mixed $post    The post.
	 *
	 * @return void
	 */
	public static function note_object_deleting( $post_id, $post = null ): void {
		if ( is_object( $post ) && 'nav_menu_item' !== $post->post_type ) {
			self::$deleting = [
				'label'  => (string) $post->post_title,
				'type'   => 'post_type',
				'object' => (string) $post->post_type,
			];
		}
	}

	/**
	 * `delete_term` (priority 9): a term is being deleted; WordPress deletes
	 * the menu items that show it next.
	 *
	 * @param mixed $term_id      The term ID.
	 * @param mixed $tt_id        The term taxonomy ID.
	 * @param mixed $taxonomy     The taxonomy.
	 * @param mixed $deleted_term The term as it was.
	 *
	 * @return void
	 */
	public static function note_term_deleting( $term_id, $tt_id, $taxonomy, $deleted_term = null ): void {
		if ( is_object( $deleted_term ) && 'nav_menu' !== $taxonomy ) {
			self::$deleting = [
				'label'  => (string) ( $deleted_term->name ?? '' ),
				'type'   => 'taxonomy',
				'object' => (string) $taxonomy,
			];
		}
	}

	/**
	 * `delete_term_relationships`: the menu a deleted item was on (WordPress
	 * read it to remove the relationship).
	 *
	 * @param mixed $object_id The item ID.
	 * @param mixed $tt_ids    The term taxonomy IDs.
	 * @param mixed $taxonomy  The taxonomy.
	 *
	 * @return void
	 */
	public static function note_item_menu( $object_id, $tt_ids, $taxonomy ): void {
		if ( 'nav_menu' === $taxonomy && isset( self::$items[ (int) $object_id ]['removed'] ) && is_array( $tt_ids ) && [] !== $tt_ids ) {
			self::$items[ (int) $object_id ]['tt'] = (int) reset( $tt_ids );
		}
	}

	/**
	 * `pre_set_theme_mod_nav_menu_locations`: which menu each theme location
	 * shows.
	 *
	 * @param mixed $value     The new locations.
	 * @param mixed $old_value The locations before.
	 *
	 * @return mixed The new locations, unchanged.
	 */
	public static function note_locations( $value, $old_value ) {
		if ( self::is_quiet() ) {
			return $value;
		}

		$new = is_array( $value ) ? $value : [];
		$old = is_array( $old_value ) ? $old_value : [];
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $location ) {
			$before = (int) ( $old[ $location ] ?? 0 );
			$after  = (int) ( $new[ $location ] ?? 0 );
			if ( $before === $after ) {
				continue;
			}
			$location = (string) $location;
			if ( ! isset( self::$locations[ $location ] ) ) {
				self::$locations[ $location ] = [
					'old' => $before,
					'new' => $after,
				];
			}
			self::$locations[ $location ]['new'] = $after;
		}

		return $value;
	}

	/**
	 * `updated_option`: the menus that add new top-level pages by
	 * themselves (`nav_menu_options`).
	 *
	 * @param mixed $option    The option name.
	 * @param mixed $old_value The value before.
	 * @param mixed $value     The value after.
	 *
	 * @return void
	 */
	public static function note_option( $option, $old_value, $value ): void {
		if ( 'nav_menu_options' !== $option || self::is_quiet() ) {
			return;
		}

		$ids = static function ( $options ): array {
			$list = is_array( $options ) && is_array( $options['auto_add'] ?? null ) ? $options['auto_add'] : [];

			return array_values( array_unique( array_map( 'intval', $list ) ) );
		};

		if ( null === self::$auto_add ) {
			self::$auto_add = [
				'old' => $ids( $old_value ),
				'new' => [],
			];
		}
		self::$auto_add['new'] = $ids( $value );
	}

	/**
	 * `wp_after_insert_post`: a block menu was made or saved.
	 *
	 * @param mixed $post_id     The post ID.
	 * @param mixed $post        The post.
	 * @param mixed $update      Whether an existing post was updated.
	 * @param mixed $post_before The post before, or null.
	 *
	 * @return void
	 */
	public static function note_block_saved( $post_id, $post, $update, $post_before ): void {
		if ( ! is_object( $post ) || 'wp_navigation' !== $post->post_type || 'auto-draft' === $post->post_status || self::is_quiet() ) {
			return;
		}

		$key = 'block:' . (int) $post_id;
		$new = ! $update || ! is_object( $post_before ) || 'auto-draft' === $post_before->post_status;
		if ( $new && ! isset( self::$menus[ $key ] ) && self::is_read_request() ) {
			return;
		}

		$key = self::menu( 'block', (int) $post_id );
		if ( $new && ! isset( self::$menus[ $key ]['before'] ) ) {
			self::$menus[ $key ]['created'] = true;
		} elseif ( ! isset( self::$menus[ $key ]['before'] ) && is_object( $post_before ) ) {
			self::$menus[ $key ]['before'] = self::block_state( $post_before );
		}
		self::$menus[ $key ]['after'] = self::block_state( $post );
	}

	/**
	 * What a block menu's row needs of its post.
	 *
	 * @param object $post The wp_navigation post.
	 *
	 * @return array{name: string, status: string, content: string}
	 */
	private static function block_state( object $post ): array {
		return [
			'name'    => (string) $post->post_title,
			'status'  => (string) $post->post_status,
			'content' => (string) $post->post_content,
		];
	}

	/**
	 * The rows for what this request changed: event type and data, which
	 * the background job completes (Builder).
	 *
	 * @return array<int, array{type: string, data: array<string, mixed>}>
	 */
	public static function rows(): array {
		$rows = [];

		// Classic items by menu: the menu ID the save named, else the menu
		// a deleted item was on.
		$by_menu = [];
		$by_tt   = [];
		$unknown = [];
		foreach ( self::$items as $item_id => $item ) {
			if ( $item['menu'] > 0 ) {
				$by_menu[ $item['menu'] ][ $item_id ] = $item;
			} elseif ( isset( $item['tt'] ) ) {
				$by_tt[ $item['tt'] ][ $item_id ] = $item;
			} else {
				// Saved with wp_update_post() rather than as a menu item.
				$unknown[ $item_id ] = $item;
			}
		}
		foreach ( self::$menus as $menu ) {
			if ( 'classic' === $menu['kind'] && isset( $menu['tt'], $by_tt[ $menu['tt'] ] ) ) {
				$by_menu[ $menu['id'] ] = ( $by_menu[ $menu['id'] ] ?? [] ) + $by_tt[ $menu['tt'] ];
				unset( $by_tt[ $menu['tt'] ] );
			}
		}
		foreach ( self::$menu_tt as $menu_id => $tt_id ) {
			if ( isset( $by_tt[ $tt_id ] ) ) {
				$by_menu[ $menu_id ] = ( $by_menu[ $menu_id ] ?? [] ) + $by_tt[ $tt_id ];
				unset( $by_tt[ $tt_id ] );
			}
		}
		// The rest (an item deleted over REST, or with its page) is named by
		// the job, which may query.

		// Locations by menu.
		$shown  = [];
		$hidden = [];
		foreach ( self::$locations as $location => $change ) {
			if ( $change['old'] === $change['new'] ) {
				continue;
			}
			if ( $change['new'] > 0 ) {
				$shown[ $change['new'] ][] = $location;
			}
			if ( $change['old'] > 0 ) {
				$hidden[ $change['old'] ][] = $location;
			}
		}

		// Auto add pages by menu.
		$auto_add = [];
		if ( null !== self::$auto_add ) {
			foreach ( array_diff( self::$auto_add['new'], self::$auto_add['old'] ) as $menu_id ) {
				$auto_add[ $menu_id ] = true;
			}
			foreach ( array_diff( self::$auto_add['old'], self::$auto_add['new'] ) as $menu_id ) {
				$auto_add[ $menu_id ] = false;
			}
		}

		$classic_ids = array_unique( array_merge( array_keys( $by_menu ), array_keys( $shown ), array_keys( $hidden ), array_keys( $auto_add ) ) );
		foreach ( self::$menus as $menu ) {
			if ( 'classic' === $menu['kind'] ) {
				$classic_ids[] = $menu['id'];
			}
		}
		sort( $classic_ids );

		foreach ( array_unique( $classic_ids ) as $menu_id ) {
			$row = self::classic_row( (int) $menu_id, self::$menus[ 'classic:' . $menu_id ] ?? [], $by_menu[ $menu_id ] ?? [], $shown[ $menu_id ] ?? [], $hidden[ $menu_id ] ?? [], $auto_add[ $menu_id ] ?? null );
			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		foreach ( $by_tt as $tt_id => $items ) {
			$row = self::classic_row( 0, [ 'tt' => (int) $tt_id ], $items, [], [], null );
			if ( null !== $row ) {
				$rows[] = $row;
			}
		}
		if ( [] !== $unknown ) {
			$row = self::classic_row( 0, [ 'item' => (int) array_key_first( $unknown ) ], $unknown, [], [], null );
			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		foreach ( self::$menus as $menu ) {
			if ( 'block' === $menu['kind'] ) {
				$row = self::block_row( $menu );
				if ( null !== $row ) {
					$rows[] = $row;
				}
			}
		}

		return $rows;
	}

	/**
	 * The row of a classic menu, or null when nothing about it changed.
	 *
	 * @param int                             $menu_id  The menu's term ID.
	 * @param array<string, mixed>            $menu     What was noted about the menu.
	 * @param array<int, array<string,mixed>> $items    Its items noted, by ID.
	 * @param array<int, string>              $shown    Locations it now shows in.
	 * @param array<int, string>              $hidden   Locations it no longer shows in.
	 * @param bool|null                       $auto_add Whether it now adds new pages by itself, null when unchanged.
	 *
	 * @return array{type: string, data: array<string, mixed>}|null
	 */
	private static function classic_row( int $menu_id, array $menu, array $items, array $shown, array $hidden, ?bool $auto_add ): ?array {
		$created = ! empty( $menu['created'] );
		$deleted = ! empty( $menu['deleted'] );
		if ( $created && $deleted ) {
			return null;
		}

		$data = [
			'id'   => $menu_id,
			'kind' => 'classic',
		];
		if ( 0 === $menu_id ) {
			// The job finds the menu: by its term taxonomy ID, or by an item.
			if ( isset( $menu['tt'] ) ) {
				$data['_tt'] = (int) $menu['tt'];
			} else {
				$data['_item'] = (int) $menu['item'];
			}
		}
		if ( isset( $menu['name'] ) ) {
			$data['name'] = $menu['name'];
		}

		if ( $deleted ) {
			$data['items'] = count(
				array_filter( $items, static fn( array $item ): bool => isset( $item['removed'] ) )
			);
			if ( [] !== $hidden ) {
				$data['_locations'] = $hidden;
			}

			return [
				'type' => 'MenuDeleted',
				'data' => $data,
			];
		}

		if ( $created ) {
			if ( [] !== $shown ) {
				$data['_locations'] = $shown;
			}

			return [
				'type' => 'MenuCreated',
				'data' => $data,
			];
		}

		$changes = [];
		if ( isset( $menu['old_name'] ) && ( $menu['name'] ?? '' ) !== $menu['old_name'] ) {
			$changes['name'] = [
				'old' => $menu['old_name'],
				'new' => $menu['name'] ?? '',
			];
		}
		if ( null !== $auto_add ) {
			$changes['auto_add'] = [
				'old' => ! $auto_add,
				'new' => $auto_add,
			];
		}

		$noted = [];
		foreach ( $items as $item_id => $item ) {
			$entry = [];
			if ( ! empty( $item['added'] ) ) {
				$entry['added'] = $item['noted'] ?? true;
			} elseif ( isset( $item['removed'] ) ) {
				$entry['removed'] = $item['removed'];
			} elseif ( 'publish' === get_post_status( $item_id ) ) {
				// A draft item isn't on the menu yet (the post is cached).
				foreach ( $item['new'] as $field => $value ) {
					if ( ( $item['old'][ $field ] ?? null ) !== $value ) {
						$entry['old'][ $field ] = $item['old'][ $field ];
						$entry['new'][ $field ] = $value;
					}
				}
			}
			if ( [] !== $entry ) {
				$noted[ $item_id ] = $entry;
			}
		}

		if ( [] === $changes && [] === $noted && [] === $shown && [] === $hidden ) {
			return null;
		}

		if ( [] !== $changes ) {
			$data['changes'] = $changes;
		}
		if ( [] !== $noted ) {
			$data['_items'] = $noted;
		}
		if ( [] !== $shown || [] !== $hidden ) {
			$data['_locations'] = [
				'added'   => $shown,
				'removed' => $hidden,
			];
		}

		return [
			'type' => 'MenuChanged',
			'data' => $data,
		];
	}

	/**
	 * The row of a block menu, or null when nothing about it changed.
	 *
	 * @param array<string, mixed> $menu What was noted about the menu.
	 *
	 * @return array{type: string, data: array<string, mixed>}|null
	 */
	private static function block_row( array $menu ): ?array {
		$created = ! empty( $menu['created'] );
		$deleted = ! empty( $menu['deleted'] );
		$before  = $menu['before'] ?? null;
		$after   = $menu['after'] ?? null;
		if ( $created && $deleted ) {
			return null;
		}

		$data = [
			'id'   => (int) $menu['id'],
			'kind' => 'block',
		];

		if ( $deleted && is_array( $before ) ) {
			$data['name']     = $before['name'];
			$data['_content'] = $before['content'];

			return [
				'type' => 'MenuDeleted',
				'data' => $data,
			];
		}

		if ( ! is_array( $after ) ) {
			return null;
		}
		$data['name'] = $after['name'];

		if ( $created ) {
			$data['_content'] = $after['content'];

			return [
				'type' => 'MenuCreated',
				'data' => $data,
			];
		}

		if ( ! is_array( $before ) ) {
			return null;
		}

		$changes = [];
		if ( $before['name'] !== $after['name'] ) {
			$changes['name'] = [
				'old' => $before['name'],
				'new' => $after['name'],
			];
		}
		if ( $before['status'] !== $after['status'] ) {
			$changes['status'] = [
				'old' => $before['status'],
				'new' => $after['status'],
			];
		}
		if ( $before['content'] !== $after['content'] ) {
			$data['_before'] = $before['content'];
			$data['_after']  = $after['content'];
		} elseif ( [] === $changes ) {
			return null;
		}
		if ( [] !== $changes ) {
			$data['changes'] = $changes;
		}

		return [
			'type' => 'MenuChanged',
			'data' => $data,
		];
	}

	/**
	 * Report what this request changed, once, at the end of the request.
	 *
	 * @return void
	 */
	public static function flush(): void {
		$rows = self::rows();
		self::reset();
		if ( [] === $rows ) {
			return;
		}

		$room = Limiter::take( Limiter::KIND_MENU, count( $rows ) );
		foreach ( array_slice( $rows, 0, $room ) as $row ) {
			do_action( ContentEvent::hook( $row['type'] ), $row['data'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The hooks start with scanfully_.
		}
	}
}
