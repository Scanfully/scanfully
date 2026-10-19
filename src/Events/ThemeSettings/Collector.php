<?php
/**
 * The theme settings change collector class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\ThemeSettings;

use Scanfully\Events\Content\Limiter;
use Scanfully\Events\ContentEvent;
use Scanfully\Events\MenuChanges\Collector as MenuCollector;
use Scanfully\Events\PostChanges\Fields;
use Scanfully\Events\SettingsChanges\Settings;

/**
 * Class Collector
 *
 * Notes what a request changes in the theme's settings and reports it at
 * the end of the request as one ThemeSettingsChanged row:
 *
 * - a Customizer publish: every setting in the changeset that no other
 *   collector reports, with the value before (read at `customize_save`,
 *   before anything is saved) and after (at `customize_save_after`), and
 *   the label and section the Customizer gives it. Settings are not
 *   previewed while a changeset is published, so both reads are the stored
 *   values, from options WordPress has loaded;
 * - theme mods changed another way (WP-CLI, the old Header and Background
 *   screens, importers), from the `theme_mods_{stylesheet}` option, but
 *   not on page views, theme switches, installs and imports;
 * - the logo, from the `site_logo` option core keeps in step with the
 *   `custom_logo` theme mod, so the Site Logo block counts too;
 * - Additional CSS, from the `custom_css` post WordPress saves for the
 *   theme, old and new CSS as wp_after_insert_post passes them.
 *
 * File names, the theme's name and the CSS diff are added by the
 * background job (Builder).
 *
 * @package Scanfully\Events\ThemeSettings
 */
final class Collector {

	/**
	 * Customizer settings other collectors report: site identity and
	 * homepage settings (SettingsChanges), menus and widgets.
	 */
	private const OTHER_PREFIXES = [ 'nav_menu', 'widget_', 'sidebars_widgets', 'custom_css[' ];

	/**
	 * Customizer settings that are no change of their own: the header
	 * image's data travels with `header_image`, the background thumbnail
	 * with `background_image`, the posts the Customizer makes for menus
	 * with the menu.
	 */
	private const SKIPPED_SETTINGS = [ 'header_image_data', 'background_image_thumb', 'nav_menus_created_posts' ];

	/**
	 * Theme mods that are no theme setting someone changed, or that other
	 * rows report: menu locations, the widget areas a theme switch keeps,
	 * the Additional CSS post's ID, the logo (see `site_logo`), the header
	 * image's data and the background thumbnail.
	 */
	private const SKIPPED_MODS = [ 'nav_menu_locations', 'sidebars_widgets', 'custom_css_post_id', 'custom_logo', 'header_image_data', 'background_image_thumb' ];

	/**
	 * Theme mods that hold a colour.
	 */
	private const COLOR_MODS = [ 'background_color', 'header_textcolor' ];

	/**
	 * Theme mods that hold an image or video (an attachment ID or a URL).
	 */
	private const MEDIA_MODS = [ 'header_image', 'background_image', 'header_video', 'external_header_video' ];

	/**
	 * Customizer settings whose change is read from a theme mod: the header
	 * image's two settings (`header_image` stores nothing, and
	 * `header_image_data` saves both theme mods) are one change of the
	 * `header_image` theme mod.
	 */
	private const MOD_SETTINGS = [
		'header_image'      => 'header_image',
		'header_image_data' => 'header_image',
	];

	/**
	 * The option that holds the logo's attachment ID.
	 */
	public const LOGO_OPTION = 'site_logo';

	/**
	 * The Customizer setting of the logo.
	 */
	private const LOGO_SETTING = 'custom_logo';

	/**
	 * Additional CSS larger than this (per side) is not compared.
	 */
	public const MAX_CSS = 256 * 1024;

	/**
	 * At most this many settings are listed in one row.
	 */
	private const MAX_SETTINGS = 50;

	/**
	 * The Customizer publish of this request: per setting ID the value
	 * before, the user who changed it and, for settings read from a theme
	 * mod, that mod; null without a publish.
	 *
	 * @var array<string, array{old: mixed, user: int, mod?: string}>|null
	 */
	private static ?array $customizer = null;

	/**
	 * The rows of a finished Customizer publish.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $customizer_settings = [];

	/**
	 * The theme a Customizer publish saved, and who made it when cron
	 * published a scheduled change.
	 *
	 * @var array{stylesheet: string, actor: int, scheduled: bool}|null
	 */
	private static ?array $customizer_publish = null;

	/**
	 * Theme mods changed outside the Customizer, per stylesheet: first old
	 * and last new value of the option.
	 *
	 * @var array<string, array{old: mixed, new: mixed}>
	 */
	private static array $mods = [];

	/**
	 * The logo: first old and last new attachment ID; null when unchanged.
	 *
	 * @var array{old: mixed, new: mixed}|null
	 */
	private static ?array $logo = null;

	/**
	 * Additional CSS: first old and last new CSS of the theme's post.
	 *
	 * @var array{old: string, new: string, stylesheet: string}|null
	 */
	private static ?array $css = null;

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public static function setup(): void {
		add_action( 'customize_save', [ self::class, 'note_publish_start' ], 10, 1 );
		add_action( 'customize_save_after', [ self::class, 'note_publish_done' ], 10, 1 );
		add_action( 'updated_option', [ self::class, 'note_updated' ], 10, 3 );
		add_action( 'added_option', [ self::class, 'note_added' ], 10, 2 );
		add_action( 'delete_option', [ self::class, 'note_deleting' ], 10, 1 );
		add_action( 'wp_after_insert_post', [ self::class, 'note_post_saved' ], 10, 4 );
		add_action( 'shutdown', [ self::class, 'flush' ], 1, 0 );
	}

	/**
	 * Forget what was noted (tests, and after a flush).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$customizer          = null;
		self::$customizer_settings = [];
		self::$customizer_publish  = null;
		self::$mods                = [];
		self::$logo                = null;
		self::$css                 = null;
	}

	/**
	 * Whether installs, upgrades or imports run.
	 *
	 * @return bool
	 */
	private static function is_setup_work(): bool {
		return ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) || wp_installing();
	}

	/**
	 * Whether option changes outside a Customizer publish are left out:
	 * page views (some themes write defaults when a page loads), theme
	 * switches, installs and imports.
	 *
	 * @return bool
	 */
	private static function is_quiet(): bool {
		return MenuCollector::is_quiet() || MenuCollector::is_read_request();
	}

	/**
	 * Whether a Customizer setting is reported by another collector, or is
	 * no change of its own.
	 *
	 * @param string $id      The setting ID.
	 * @param object $setting The setting (a WP_Customize_Setting).
	 *
	 * @return bool
	 */
	public static function is_reported_elsewhere( string $id, object $setting ): bool {
		if ( in_array( $id, self::SKIPPED_SETTINGS, true ) ) {
			return true;
		}
		foreach ( self::OTHER_PREFIXES as $prefix ) {
			if ( 0 === strpos( $id, $prefix ) ) {
				return true;
			}
		}
		// A filter setting stores nothing (the header text checkbox sets
		// `header_textcolor`).
		if ( $setting instanceof \WP_Customize_Filter_Setting ) {
			return true;
		}

		$type = (string) ( $setting->type ?? '' );
		$base = method_exists( $setting, 'id_data' ) ? (string) ( $setting->id_data()['base'] ?? $id ) : $id;

		return 'option' === $type && Settings::is_reported( $base );
	}

	/**
	 * `customize_save`: a changeset is about to be published. Notes the
	 * value of every changed setting before it is saved.
	 *
	 * @param mixed $manager The Customizer (a WP_Customize_Manager).
	 *
	 * @return void
	 */
	public static function note_publish_start( $manager ): void {
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'unsanitized_post_values' ) || self::is_setup_work() ) {
			return;
		}

		$values = $manager->unsanitized_post_values(
			[
				'exclude_post_data' => true,
				'exclude_changeset' => false,
			]
		);
		$users  = self::setting_users( $manager );

		self::$customizer = [];
		foreach ( array_keys( is_array( $values ) ? $values : [] ) as $id ) {
			$id = (string) $id;
			if ( isset( self::MOD_SETTINGS[ $id ] ) ) {
				$mod = self::MOD_SETTINGS[ $id ];
				if ( ! isset( self::$customizer[ $mod ] ) ) {
					self::$customizer[ $mod ] = [
						'old'  => get_theme_mod( $mod, self::mod_default( $mod ) ),
						'user' => $users[ $id ] ?? 0,
						'mod'  => $mod,
					];
				}
				continue;
			}
			$setting = $manager->get_setting( $id );
			if ( ! is_object( $setting ) || self::is_reported_elsewhere( $id, $setting ) ) {
				continue;
			}
			self::$customizer[ $id ] = [
				'old'  => $setting->value(),
				'user' => $users[ $id ] ?? 0,
			];
		}
	}

	/**
	 * The value a theme mod has while it isn't set: for the header image the
	 * theme's default image, which is what the site showed.
	 *
	 * @param string $mod The theme mod.
	 *
	 * @return mixed
	 */
	private static function mod_default( string $mod ) {
		return 'header_image' === $mod ? get_theme_support( 'custom-header', 'default-image' ) : false;
	}

	/**
	 * Who changed each setting of the changeset being published, by
	 * setting ID (theme mods are stored as `{stylesheet}::{id}`).
	 *
	 * @param object $manager The Customizer.
	 *
	 * @return array<string, int>
	 */
	private static function setting_users( object $manager ): array {
		if ( ! method_exists( $manager, 'changeset_data' ) ) {
			return [];
		}

		$users = [];
		foreach ( (array) $manager->changeset_data() as $raw_id => $params ) {
			if ( ! is_array( $params ) || ! isset( $params['user_id'] ) ) {
				continue;
			}
			$id           = preg_match( '/^.+?::(.+)$/', (string) $raw_id, $matches ) ? $matches[1] : (string) $raw_id;
			$users[ $id ] = (int) $params['user_id'];
		}

		return $users;
	}

	/**
	 * `customize_save_after`: the changeset is published. Notes the value
	 * after, and the label and section of each changed setting.
	 *
	 * @param mixed $manager The Customizer (a WP_Customize_Manager).
	 *
	 * @return void
	 */
	public static function note_publish_done( $manager ): void {
		if ( null === self::$customizer || ! is_object( $manager ) || ! method_exists( $manager, 'get_setting' ) ) {
			return;
		}

		$noted            = self::$customizer;
		self::$customizer = null;
		$controls         = self::controls_by_setting( $manager );
		$users            = [];

		foreach ( $noted as $id => $before ) {
			if ( isset( $before['mod'] ) ) {
				$after = get_theme_mod( $before['mod'], self::mod_default( $before['mod'] ) );
			} else {
				$setting = $manager->get_setting( $id );
				if ( ! is_object( $setting ) ) {
					continue;
				}
				$after = $setting->value();
			}
			if ( Fields::fingerprint( $before['old'] ) === Fields::fingerprint( $after ) ) {
				continue;
			}

			if ( self::LOGO_SETTING === $id ) {
				self::$logo = [
					'old' => $before['old'],
					'new' => $after,
				];
				continue;
			}

			$control = $controls[ $id ] ?? null;
			$row     = self::setting_row( $id, $before['old'], $after, $control, $manager );
			if ( null !== $row && count( self::$customizer_settings ) < self::MAX_SETTINGS ) {
				self::$customizer_settings[] = $row;
			}
			if ( $before['user'] > 0 ) {
				$users[] = $before['user'];
			}
		}

		// Cron publishes a scheduled change signed out: the row names the
		// person who made most of the changes.
		$actor = 0;
		if ( get_current_user_id() < 1 && [] !== $users ) {
			$counts = array_count_values( $users );
			arsort( $counts );
			$actor = (int) array_key_first( $counts );
		}

		self::$customizer_publish = [
			'stylesheet' => method_exists( $manager, 'get_stylesheet' ) ? (string) $manager->get_stylesheet() : (string) get_stylesheet(),
			'actor'      => $actor,
			'scheduled'  => wp_doing_cron(),
		];
	}

	/**
	 * The Customizer's controls by the ID of the setting they edit. When
	 * several edit one setting (core's "Display Site Title and Tagline"
	 * checkbox edits the header text colour), the control named after the
	 * setting gives the label.
	 *
	 * @param object $manager The Customizer.
	 *
	 * @return array<string, object>
	 */
	private static function controls_by_setting( object $manager ): array {
		if ( ! method_exists( $manager, 'controls' ) ) {
			return [];
		}

		$controls = [];
		foreach ( (array) $manager->controls() as $control ) {
			if ( ! is_object( $control ) || ! isset( $control->settings ) || ! is_array( $control->settings ) ) {
				continue;
			}
			foreach ( $control->settings as $setting ) {
				$id = is_object( $setting ) && isset( $setting->id ) ? (string) $setting->id : '';
				if ( '' !== $id && ( ! isset( $controls[ $id ] ) || ( $control->id ?? '' ) === $id ) ) {
					$controls[ $id ] = $control;
				}
			}
		}

		return $controls;
	}

	/**
	 * The row of a changed Customizer setting, or null when nothing shows.
	 *
	 * @param string      $id      The setting ID.
	 * @param mixed       $old     The value before.
	 * @param mixed       $new     The value after.
	 * @param object|null $control The control that edits it.
	 * @param object      $manager The Customizer.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function setting_row( string $id, $old, $new, ?object $control, object $manager ): ?array {
		$row = [ 'id' => $id ];

		if ( null !== $control ) {
			$label = self::plain( $control->label ?? '' );
			if ( '' !== $label ) {
				$row['label'] = $label;
			}
			$section_id = (string) ( $control->section ?? '' );
			$section    = '' !== $section_id && method_exists( $manager, 'get_section' ) ? $manager->get_section( $section_id ) : null;
			$title      = is_object( $section ) ? self::plain( $section->title ?? '' ) : '';
			if ( '' !== $title ) {
				$row['section'] = $title;
			}
		}

		$kind = self::control_kind( $id, $control );
		if ( '' !== $kind ) {
			$row['kind'] = $kind;
		}

		if ( null !== $control && 'password' === ( $control->type ?? '' ) ) {
			$row['change'] = 'changed';
			$row['secret'] = true;

			return $row;
		}

		return self::with_values( $row, $old, $new );
	}

	/**
	 * What a setting holds, for the dashboard: `color`, `media` (an
	 * attachment ID or a URL), `page` (a page ID, from a page dropdown) or
	 * ''.
	 *
	 * @param string      $id      The setting or theme mod ID.
	 * @param object|null $control The control that edits it, if any.
	 *
	 * @return string
	 */
	private static function control_kind( string $id, ?object $control ): string {
		if ( in_array( $id, self::COLOR_MODS, true ) || ( null !== $control && $control instanceof \WP_Customize_Color_Control ) ) {
			return 'color';
		}
		if ( in_array( $id, self::MEDIA_MODS, true ) || ( null !== $control && $control instanceof \WP_Customize_Media_Control ) ) {
			return 'media';
		}
		if ( null !== $control && 'dropdown-pages' === ( $control->type ?? '' ) ) {
			return 'page';
		}

		return '';
	}

	/**
	 * A row with its change and values as custom fields show them: short
	 * values as they are, long ones and arrays only as large.
	 *
	 * @param array<string, mixed> $row The row so far.
	 * @param mixed                $old The value before.
	 * @param mixed                $new The value after.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function with_values( array $row, $old, $new ): ?array {
		$had = null !== $old && '' !== $old;
		$has = null !== $new && '' !== $new;
		if ( ! $had && ! $has ) {
			return null;
		}
		$row['change'] = $had && $has ? 'changed' : ( $has ? 'added' : 'removed' );

		foreach ( [
			'old' => $had ? $old : null,
			'new' => $has ? $new : null,
		] as $side => $value ) {
			if ( null === $value ) {
				continue;
			}
			$described = Fields::describe( $value );
			if ( $described['large'] ) {
				$row['large'] = true;
			} elseif ( null !== $described['value'] ) {
				$row[ $side ] = $described['value'];
			}
		}

		return $row;
	}

	/**
	 * The stylesheet of a theme mods option, or '' for any other option.
	 *
	 * @param string $option The option name.
	 *
	 * @return string
	 */
	private static function mods_stylesheet( string $option ): string {
		return 0 === strpos( $option, 'theme_mods_' ) ? substr( $option, strlen( 'theme_mods_' ) ) : '';
	}

	/**
	 * `updated_option`: theme mods or the logo changed.
	 *
	 * @param mixed $option    The option name.
	 * @param mixed $old_value The value before.
	 * @param mixed $value     The value after.
	 *
	 * @return void
	 */
	public static function note_updated( $option, $old_value, $value ): void {
		if ( ! is_string( $option ) ) {
			return;
		}
		self::note_option( $option, $old_value, $value );
	}

	/**
	 * `added_option`: the first theme mod of a theme, or a logo set.
	 *
	 * @param mixed $option The option name.
	 * @param mixed $value  The value.
	 *
	 * @return void
	 */
	public static function note_added( $option, $value ): void {
		if ( ! is_string( $option ) ) {
			return;
		}
		self::note_option( $option, self::LOGO_OPTION === $option ? null : [], $value );
	}

	/**
	 * `delete_option`: the logo is removed, or every theme mod of a theme
	 * (remove_theme_mods()). The value before comes from the options cache.
	 *
	 * @param mixed $option The option name.
	 *
	 * @return void
	 */
	public static function note_deleting( $option ): void {
		if ( ! is_string( $option ) || ( self::LOGO_OPTION !== $option && '' === self::mods_stylesheet( $option ) ) ) {
			return;
		}

		$all   = wp_load_alloptions();
		$value = $all[ $option ] ?? wp_cache_get( $option, 'options' );
		if ( false === $value ) {
			return;
		}
		self::note_option( $option, maybe_unserialize( $value ), self::LOGO_OPTION === $option ? null : [] );
	}

	/**
	 * Note a change of the logo or of the active theme's mods.
	 *
	 * @param string $option The option name.
	 * @param mixed  $old    The value before.
	 * @param mixed  $new    The value after.
	 *
	 * @return void
	 */
	private static function note_option( string $option, $old, $new ): void {
		if ( self::LOGO_OPTION === $option ) {
			if ( self::is_quiet() ) {
				return;
			}
			if ( null === self::$logo ) {
				self::$logo = [
					'old' => $old,
					'new' => $new,
				];
				return;
			}
			self::$logo['new'] = $new;
			return;
		}

		$stylesheet = self::mods_stylesheet( $option );
		if ( '' === $stylesheet || get_stylesheet() !== $stylesheet || self::is_quiet() ) {
			return;
		}
		if ( ! isset( self::$mods[ $stylesheet ] ) ) {
			self::$mods[ $stylesheet ] = [
				'old' => $old,
				'new' => $new,
			];
			return;
		}
		self::$mods[ $stylesheet ]['new'] = $new;
	}

	/**
	 * `wp_after_insert_post`: the Additional CSS of a theme was saved.
	 *
	 * @param mixed $post_id     The post ID.
	 * @param mixed $post        The post.
	 * @param mixed $update      Whether an existing post was updated.
	 * @param mixed $post_before The post before the save, or null.
	 *
	 * @return void
	 */
	public static function note_post_saved( $post_id, $post, $update, $post_before ): void {
		if ( ! is_object( $post ) || 'custom_css' !== ( $post->post_type ?? '' ) || self::is_setup_work() ) {
			return;
		}

		$stylesheet = (string) ( $post->post_title ?? '' );
		$old        = is_object( $post_before ) ? (string) ( $post_before->post_content ?? '' ) : '';
		$new        = (string) ( $post->post_content ?? '' );

		if ( null === self::$css ) {
			self::$css = [
				'old'        => $old,
				'new'        => $new,
				'stylesheet' => $stylesheet,
			];
			return;
		}
		self::$css['new'] = $new;
	}

	/**
	 * The rows of the theme mods changed outside the Customizer.
	 *
	 * @param mixed $old The theme mods before.
	 * @param mixed $new The theme mods after.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function mod_rows( $old, $new ): array {
		$old  = is_array( $old ) ? $old : [];
		$new  = is_array( $new ) ? $new : [];
		$rows = [];
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $key ) {
			$key = (string) $key;
			if ( '' === $key || is_numeric( $key ) || in_array( $key, self::SKIPPED_MODS, true ) ) {
				continue;
			}
			$before = $old[ $key ] ?? null;
			$after  = $new[ $key ] ?? null;
			if ( Fields::fingerprint( $before ) === Fields::fingerprint( $after ) ) {
				continue;
			}
			$row  = [ 'id' => $key ];
			$kind = self::control_kind( $key, null );
			if ( '' !== $kind ) {
				$row['kind'] = $kind;
			}
			$row = self::with_values( $row, $before, $after );
			if ( null !== $row ) {
				$rows[] = $row;
			}
			if ( count( $rows ) >= self::MAX_SETTINGS ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * The row's data for what this request changed (the job completes it),
	 * or [] when nothing changed.
	 *
	 * @return array<string, mixed>
	 */
	public static function row(): array {
		$publish  = self::$customizer_publish;
		$settings = self::$customizer_settings;

		// A Customizer publish writes the theme mods itself; what it doesn't
		// list (caches a theme writes when it saves) is no change of its own.
		if ( null === $publish ) {
			foreach ( self::$mods as $mods ) {
				$settings = array_merge( $settings, self::mod_rows( $mods['old'], $mods['new'] ) );
			}
		}

		$row = [
			'stylesheet' => null !== $publish ? $publish['stylesheet'] : (string) get_stylesheet(),
			'source'     => null !== $publish ? 'customizer' : 'other',
		];
		if ( null !== $publish && $publish['scheduled'] ) {
			$row['scheduled'] = true;
		}
		if ( null !== $publish && $publish['actor'] > 0 ) {
			$row['_actor'] = $publish['actor'];
		}

		if ( [] !== $settings ) {
			$row['settings'] = array_slice( $settings, 0, self::MAX_SETTINGS );
		}

		$logo = self::$logo;
		if ( null !== $logo && (int) $logo['old'] !== (int) $logo['new'] ) {
			$row['logo'] = [
				'old' => (int) $logo['old'] > 0 ? (string) (int) $logo['old'] : '',
				'new' => (int) $logo['new'] > 0 ? (string) (int) $logo['new'] : '',
			];
		}

		$css = self::$css;
		if ( null !== $css && $css['old'] !== $css['new'] ) {
			$row['css'] = strlen( $css['old'] ) > self::MAX_CSS || strlen( $css['new'] ) > self::MAX_CSS
				? [ 'too_large' => true ]
				: [
					'stash' => [
						'old' => $css['old'],
						'new' => $css['new'],
					],
				];
			if ( null === $publish ) {
				$row['stylesheet'] = '' !== $css['stylesheet'] ? $css['stylesheet'] : $row['stylesheet'];
			}
		}

		return isset( $row['settings'] ) || isset( $row['logo'] ) || isset( $row['css'] ) ? $row : [];
	}

	/**
	 * Report what this request changed, once, at the end of the request.
	 *
	 * @return void
	 */
	public static function flush(): void {
		$row = self::row();
		self::reset();
		if ( [] === $row || Limiter::take( Limiter::KIND_THEME, 1 ) < 1 ) {
			return;
		}

		do_action( ContentEvent::hook( 'ThemeSettingsChanged' ), $row ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The hooks start with scanfully_.
	}

	/**
	 * Plain text from a label the Customizer may give with HTML or
	 * entities.
	 *
	 * @param mixed $value The label.
	 *
	 * @return string
	 */
	private static function plain( $value ): string {
		return trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' ) );
	}
}
