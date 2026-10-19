<?php
/**
 * The file edit collector class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\FileEdits;

use Scanfully\Events\ContentEvent;

/**
 * Class Collector
 *
 * Notes an edit in WordPress's plugin and theme file editors and reports it
 * at the end of the request when the file really changed (`PluginFileEdited`,
 * `ThemeFileEdited`). WordPress has no hook for these edits: both editors
 * save through `wp_edit_theme_plugin_file()`, from the `edit-theme-plugin-file`
 * AJAX action or, without JavaScript, from a POST to plugin-editor.php or
 * theme-editor.php. So the file is read just before WordPress saves it and
 * again at the end of the request. WordPress puts the old file back when the
 * edited PHP breaks the site; then the file is unchanged and nothing is
 * reported.
 *
 * Only the editor's save requests do this work. The content diff is worked
 * out in the background job (Content\Builder).
 *
 * @package Scanfully\Events\FileEdits
 */
final class Collector {

	/**
	 * Files larger than this are only compared by their hash, not diffed.
	 */
	public const MAX_CONTENT = 256 * 1024;

	/**
	 * Edits noted in this request.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $pending = [];

	/**
	 * Whether the hooks are added.
	 *
	 * @var bool
	 */
	private static bool $set_up = false;

	/**
	 * Add the hooks.
	 *
	 * @return void
	 */
	public static function setup(): void {
		if ( self::$set_up ) {
			return;
		}
		self::$set_up = true;

		// WordPress's own handler runs at priority 1.
		add_action( 'wp_ajax_edit-theme-plugin-file', [ self::class, 'note_ajax_save' ], 0, 0 );
		add_action( 'load-plugin-editor.php', [ self::class, 'note_form_save' ], 10, 0 );
		add_action( 'load-theme-editor.php', [ self::class, 'note_form_save' ], 10, 0 );
		add_action( 'shutdown', [ self::class, 'flush' ], 1, 0 );
	}

	/**
	 * Forget what was noted (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$pending = [];
	}

	/**
	 * `wp_ajax_edit-theme-plugin-file`: the editor is about to save.
	 *
	 * @return void
	 */
	public static function note_ajax_save(): void {
		self::note( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress checks the nonce when it saves; this only reads the file it names.
	}

	/**
	 * `load-plugin-editor.php`, `load-theme-editor.php`: the editor's form
	 * without JavaScript is about to save.
	 *
	 * @return void
	 */
	public static function note_form_save(): void {
		if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return;
		}

		self::note( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress checks the nonce when it saves; this only reads the file it names.
	}

	/**
	 * Note the file a save names, as it is before the save. Nothing is noted
	 * for a file outside the plugin or theme, or for a user who may not edit
	 * it.
	 *
	 * @param mixed $args The posted fields: file, and plugin or theme.
	 *
	 * @return void
	 */
	public static function note( $args ): void {
		$target = self::target( is_array( $args ) ? $args : [] );
		if ( null === $target ) {
			return;
		}

		foreach ( self::$pending as $pending ) {
			if ( $pending['path'] === $target['path'] ) {
				return;
			}
		}

		$target['before'] = self::read( $target['path'] );
		self::$pending[]  = $target;
	}

	/**
	 * The file a save names: its event type, plugin or theme, the path the
	 * editor shows and the real path; null when it can't be edited.
	 *
	 * @param array<string, mixed> $args The posted fields.
	 *
	 * @return array<string, string>|null
	 */
	private static function target( array $args ): ?array {
		$file = is_string( $args['file'] ?? null ) ? $args['file'] : '';
		if ( '' === $file || 0 !== validate_file( $file ) ) {
			return null;
		}

		$plugin = is_string( $args['plugin'] ?? null ) ? $args['plugin'] : '';
		$theme  = is_string( $args['theme'] ?? null ) ? $args['theme'] : '';
		if ( '' !== $plugin ) {
			if ( 0 !== validate_file( $plugin ) || ! current_user_can( 'edit_plugins' ) ) {
				return null;
			}
			$type = 'PluginFileEdited';
			$slug = $plugin;
			$root = WP_PLUGIN_DIR;
		} elseif ( '' !== $theme ) {
			if ( 0 !== validate_file( $theme ) || ! current_user_can( 'edit_themes' ) ) {
				return null;
			}
			$object = wp_get_theme( $theme );
			if ( ! $object->exists() ) {
				return null;
			}
			$type = 'ThemeFileEdited';
			$slug = $theme;
			$root = $object->get_stylesheet_directory();
		} else {
			return null;
		}

		$real_root = realpath( $root );
		$real_file = realpath( $root . '/' . $file );
		if ( false === $real_root || false === $real_file || 0 !== strpos( $real_file, trailingslashit( $real_root ) ) || ! is_file( $real_file ) || ! is_readable( $real_file ) ) {
			return null;
		}

		return [
			'type' => $type,
			'slug' => $slug,
			'file' => $file,
			'path' => $real_file,
		];
	}

	/**
	 * A file's content, or only its hash when it is too large to compare.
	 *
	 * @param string $path The file.
	 *
	 * @return array{content?: string, hash: string}
	 */
	public static function read( string $path ): array {
		clearstatcache( true, $path );
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The file may be gone.
		if ( false === $size ) {
			return [ 'hash' => '' ];
		}

		if ( $size > self::MAX_CONTENT ) {
			$hash = md5_file( $path );
			return [ 'hash' => false !== $hash ? $hash : '' ];
		}

		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file the editor saves.
		if ( false === $content ) {
			return [ 'hash' => '' ];
		}

		return [
			'content' => $content,
			'hash'    => md5( $content ),
		];
	}

	/**
	 * What changed between a file before and after: the content for the job
	 * to compare, `too_large`, or null when the file didn't change (the save
	 * failed, or WordPress put the old file back).
	 *
	 * @param array<string, string> $before The file before.
	 * @param array<string, string> $after  The file after.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function change( array $before, array $after ): ?array {
		if ( '' === ( $before['hash'] ?? '' ) || '' === ( $after['hash'] ?? '' ) || $before['hash'] === $after['hash'] ) {
			return null;
		}

		if ( ! isset( $before['content'], $after['content'] ) ) {
			return [ 'too_large' => true ];
		}

		return [
			'stash' => [
				'old' => $before['content'],
				'new' => $after['content'],
			],
		];
	}

	/**
	 * Report each noted file that changed, at the end of the request.
	 *
	 * @return void
	 */
	public static function flush(): void {
		$pending       = self::$pending;
		self::$pending = [];

		foreach ( $pending as $edit ) {
			$content = self::change( $edit['before'], self::read( $edit['path'] ) );
			if ( null === $content ) {
				continue;
			}

			$data = [
				'name'    => self::name( $edit['type'], $edit['slug'] ),
				'slug'    => $edit['slug'],
				'file'    => $edit['file'],
				'changes' => [ 'content' => $content ],
			];

			do_action( ContentEvent::hook( $edit['type'] ), $data ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The hooks start with scanfully_.
		}
	}

	/**
	 * The name of the plugin or theme a file belongs to.
	 *
	 * @param string $type The event type.
	 * @param string $slug The plugin's basename or the theme's folder.
	 *
	 * @return string
	 */
	private static function name( string $type, string $slug ): string {
		if ( 'ThemeFileEdited' === $type ) {
			return html_entity_decode( (string) wp_get_theme( $slug )->get( 'Name' ), ENT_QUOTES, 'UTF-8' );
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$path = WP_PLUGIN_DIR . '/' . $slug;
		$data = is_file( $path ) ? get_plugin_data( $path, false, false ) : [];

		return html_entity_decode( (string) ( $data['Name'] ?? '' ), ENT_QUOTES, 'UTF-8' );
	}
}
