<?php
/**
 * The reported settings class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\SettingsChanges;

/**
 * Class Settings
 *
 * The settings the activity log reports: every field of the core settings
 * screens (General, Writing, Reading, Discussion, Media, Permalinks,
 * Privacy), how each one's value is sent, and the names the background job
 * adds. The permalink structure has an event of its own
 * (PermalinksChanged); the timezone and the admin email request are put
 * together by the Collector.
 *
 * @package Scanfully\Events\SettingsChanges
 */
final class Settings {

	/**
	 * Text, sent as plain text (the site title and tagline are stored
	 * HTML-encoded).
	 */
	public const KIND_TEXT = 'text';

	/**
	 * A yes or no setting, sent as '1' or '0'.
	 */
	public const KIND_BOOL = 'bool';

	/**
	 * `open` or `closed`, sent as one of the two: an unticked box is saved
	 * as '', REST and plugins save 'closed'.
	 */
	public const KIND_OPEN = 'open';

	/**
	 * A number, sent as a whole number.
	 */
	public const KIND_INT = 'int';

	/**
	 * Search engine visibility: 1 and 0 in core, other numbers from privacy
	 * plugins, which must not collapse to '1'.
	 */
	public const KIND_LEVEL = 'level';

	/**
	 * A page ID; the job adds the page titles.
	 */
	public const KIND_PAGE = 'page';

	/**
	 * A category ID; the job adds the names.
	 */
	public const KIND_CATEGORY = 'category';

	/**
	 * A link category ID (Link Manager); the job adds the names.
	 */
	public const KIND_LINK_CATEGORY = 'link_category';

	/**
	 * A media library item ID; the job adds the file names.
	 */
	public const KIND_FILE = 'file';

	/**
	 * A date or time format; the job adds an example of each.
	 */
	public const KIND_FORMAT = 'format';

	/**
	 * A list of words, one a line: only how many lines were added and
	 * removed is sent, never the words (often spammers' email and IP
	 * addresses).
	 */
	public const KIND_LINES = 'lines';

	/**
	 * A list of URLs, one a line: the URLs added and removed are sent.
	 */
	public const KIND_URLS = 'urls';

	/**
	 * A secret: only that it changed is sent.
	 */
	public const KIND_SECRET = 'secret';

	/**
	 * The reported settings and their kind, in the order of the settings
	 * screens.
	 */
	public const KINDS = [
		// General.
		'blogname'                      => self::KIND_TEXT,
		'blogdescription'               => self::KIND_TEXT,
		'site_icon'                     => self::KIND_FILE,
		'siteurl'                       => self::KIND_TEXT,
		'home'                          => self::KIND_TEXT,
		'admin_email'                   => self::KIND_TEXT,
		'users_can_register'            => self::KIND_BOOL,
		'default_role'                  => self::KIND_TEXT,
		'WPLANG'                        => self::KIND_TEXT,
		'date_format'                   => self::KIND_FORMAT,
		'time_format'                   => self::KIND_FORMAT,
		'start_of_week'                 => self::KIND_INT,
		// Writing.
		'default_category'              => self::KIND_CATEGORY,
		'default_post_format'           => self::KIND_TEXT,
		'mailserver_url'                => self::KIND_TEXT,
		'mailserver_port'               => self::KIND_INT,
		'mailserver_login'              => self::KIND_SECRET,
		'mailserver_pass'               => self::KIND_SECRET,
		'default_email_category'        => self::KIND_CATEGORY,
		'ping_sites'                    => self::KIND_URLS,
		'default_link_category'         => self::KIND_LINK_CATEGORY,
		'use_smilies'                   => self::KIND_BOOL,
		'use_balanceTags'               => self::KIND_BOOL,
		// Reading.
		'show_on_front'                 => self::KIND_TEXT,
		'page_on_front'                 => self::KIND_PAGE,
		'page_for_posts'                => self::KIND_PAGE,
		'posts_per_page'                => self::KIND_INT,
		'posts_per_rss'                 => self::KIND_INT,
		'rss_use_excerpt'               => self::KIND_BOOL,
		'blog_public'                   => self::KIND_LEVEL,
		'blog_charset'                  => self::KIND_TEXT,
		// Discussion.
		'default_pingback_flag'         => self::KIND_BOOL,
		'default_ping_status'           => self::KIND_OPEN,
		'default_comment_status'        => self::KIND_OPEN,
		'comment_registration'          => self::KIND_BOOL,
		'require_name_email'            => self::KIND_BOOL,
		'close_comments_for_old_posts'  => self::KIND_BOOL,
		'close_comments_days_old'       => self::KIND_INT,
		'show_comments_cookies_opt_in'  => self::KIND_BOOL,
		'thread_comments'               => self::KIND_BOOL,
		'thread_comments_depth'         => self::KIND_INT,
		'page_comments'                 => self::KIND_BOOL,
		'comments_per_page'             => self::KIND_INT,
		'default_comments_page'         => self::KIND_TEXT,
		'comment_order'                 => self::KIND_TEXT,
		'comments_notify'               => self::KIND_BOOL,
		'moderation_notify'             => self::KIND_BOOL,
		'wp_notes_notify'               => self::KIND_BOOL,
		'comment_moderation'            => self::KIND_BOOL,
		'comment_previously_approved'   => self::KIND_BOOL,
		'comment_max_links'             => self::KIND_INT,
		'moderation_keys'               => self::KIND_LINES,
		'disallowed_keys'               => self::KIND_LINES,
		'show_avatars'                  => self::KIND_BOOL,
		'avatar_rating'                 => self::KIND_TEXT,
		'avatar_default'                => self::KIND_TEXT,
		// Media.
		'thumbnail_size_w'              => self::KIND_INT,
		'thumbnail_size_h'              => self::KIND_INT,
		'thumbnail_crop'                => self::KIND_BOOL,
		'medium_size_w'                 => self::KIND_INT,
		'medium_size_h'                 => self::KIND_INT,
		'large_size_w'                  => self::KIND_INT,
		'large_size_h'                  => self::KIND_INT,
		'image_default_size'            => self::KIND_TEXT,
		'image_default_align'           => self::KIND_TEXT,
		'image_default_link_type'       => self::KIND_TEXT,
		'uploads_use_yearmonth_folders' => self::KIND_BOOL,
		'upload_path'                   => self::KIND_TEXT,
		'upload_url_path'               => self::KIND_TEXT,
		// Permalinks (the structure is PermalinksChanged).
		'category_base'                 => self::KIND_TEXT,
		'tag_base'                      => self::KIND_TEXT,
		// Privacy.
		'wp_page_for_privacy_policy'    => self::KIND_PAGE,
	];

	/**
	 * The two options the timezone is stored in; one save can change both,
	 * so they are reported together as `timezone`.
	 */
	public const TIMEZONE_OPTIONS = [ 'timezone_string', 'gmt_offset' ];

	/**
	 * The option a requested admin email change is stored in (with the
	 * confirmation hash, which is never sent), reported as
	 * `new_admin_email`.
	 */
	public const ADMIN_EMAIL_REQUEST = 'adminhash';

	/**
	 * Values longer than this are cut.
	 */
	public const MAX_VALUE = 1000;

	/**
	 * At most this many URLs added or removed are listed.
	 */
	private const MAX_URLS = 50;

	/**
	 * A fixed moment for the date and time format examples: 31 January 2026,
	 * 15:04:05 UTC. The day is above 12 and the hour after noon, so the
	 * order of day and month and a 12 or 24 hour clock both show.
	 */
	private const EXAMPLE_TIME = 1769871845;

	/**
	 * Whether a setting is reported on its own row.
	 *
	 * @param string $option The option name.
	 *
	 * @return bool
	 */
	public static function is_reported( string $option ): bool {
		return isset( self::KINDS[ $option ] );
	}

	/**
	 * The kind of a reported setting, or '' for any other option.
	 *
	 * @param string $option The option name.
	 *
	 * @return string
	 */
	public static function kind( string $option ): string {
		return self::KINDS[ $option ] ?? '';
	}

	/**
	 * A setting's value as a string, so a value that only changed type
	 * (WordPress saves '0' as 0) is no change.
	 *
	 * @param string $option The option name.
	 * @param mixed  $value  The value.
	 *
	 * @return string
	 */
	public static function to_string( string $option, $value ): string {
		switch ( self::kind( $option ) ) {
			case self::KIND_BOOL:
				return ! empty( $value ) ? '1' : '0';
			case self::KIND_OPEN:
				return 'open' === $value ? 'open' : 'closed';
			case self::KIND_INT:
			case self::KIND_LEVEL:
			case self::KIND_PAGE:
			case self::KIND_CATEGORY:
			case self::KIND_LINK_CATEGORY:
			case self::KIND_FILE:
				return is_scalar( $value ) ? (string) (int) $value : '0';
		}

		if ( is_scalar( $value ) ) {
			// The site title and tagline are stored HTML-encoded
			// (`Barry&#039;s Shop`).
			return html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
		}
		if ( null === $value ) {
			return '';
		}

		return (string) wp_json_encode( $value );
	}

	/**
	 * The event data for a setting that changed, or [] when it didn't.
	 *
	 * @param string $option    The option name.
	 * @param mixed  $old_value The value before.
	 * @param mixed  $new_value The value after.
	 *
	 * @return array<string, mixed>
	 */
	public static function change( string $option, $old_value, $new_value ): array {
		$old = self::to_string( $option, $old_value );
		$new = self::to_string( $option, $new_value );
		if ( $old === $new ) {
			return [];
		}

		$kind = self::kind( $option );
		if ( self::KIND_SECRET === $kind ) {
			return [
				'option' => $option,
				'secret' => true,
			];
		}

		$added   = [];
		$removed = [];
		if ( self::KIND_LINES === $kind || self::KIND_URLS === $kind ) {
			[ $added, $removed ] = self::line_changes( $old, $new );
			// Only blank lines, spaces or line endings changed.
			if ( [] === $added && [] === $removed ) {
				return [];
			}
		}

		switch ( $kind ) {
			case self::KIND_LINES:
				return [
					'option'  => $option,
					'added'   => count( $added ),
					'removed' => count( $removed ),
				];
			case self::KIND_URLS:
				return [
					'option'  => $option,
					'added'   => array_map( [ self::class, 'cut' ], array_slice( $added, 0, self::MAX_URLS ) ),
					'removed' => array_map( [ self::class, 'cut' ], array_slice( $removed, 0, self::MAX_URLS ) ),
				];
		}

		return [
			'option' => $option,
			'old'    => self::cut( $old ),
			'new'    => self::cut( $new ),
		];
	}

	/**
	 * The timezone as WordPress shows it: the city zone, or the offset from
	 * UTC (`UTC+2`, `UTC-3:30`) when none is set.
	 *
	 * @param mixed $timezone_string The `timezone_string` option.
	 * @param mixed $gmt_offset      The `gmt_offset` option.
	 *
	 * @return string
	 */
	public static function timezone( $timezone_string, $gmt_offset ): string {
		if ( is_string( $timezone_string ) && '' !== $timezone_string ) {
			return $timezone_string;
		}

		$offset  = is_numeric( $gmt_offset ) ? (float) $gmt_offset : 0.0;
		$hours   = (int) floor( abs( $offset ) );
		$minutes = (int) round( ( abs( $offset ) - $hours ) * 60 );

		return 'UTC' . ( $offset < 0 ? '-' : '+' ) . $hours . ( $minutes > 0 ? sprintf( ':%02d', $minutes ) : '' );
	}

	/**
	 * The event data with the names the background job looks up: page
	 * titles, category names, the site icon's file name, and an example of
	 * a date or time format.
	 *
	 * @param array<string, mixed> $data The event data.
	 *
	 * @return array<string, mixed>
	 */
	public static function resolve( array $data ): array {
		$option = (string) ( $data['option'] ?? '' );
		$kind   = self::kind( $option );
		foreach ( [ 'old', 'new' ] as $side ) {
			if ( ! isset( $data[ $side ] ) || ! is_string( $data[ $side ] ) ) {
				continue;
			}
			$value = $data[ $side ];

			$name = '';
			switch ( $kind ) {
				case self::KIND_PAGE:
					$name = self::post_title( (int) $value );
					break;
				case self::KIND_CATEGORY:
					$name = self::term_name( (int) $value, 'category' );
					break;
				case self::KIND_LINK_CATEGORY:
					$name = self::term_name( (int) $value, 'link_category' );
					break;
				case self::KIND_FILE:
					$name = self::file_name( (int) $value );
					break;
				case self::KIND_FORMAT:
					if ( '' !== $value ) {
						$data[ $side . '_example' ] = wp_date( $value, self::EXAMPLE_TIME, new \DateTimeZone( 'UTC' ) );
					}
					break;
			}
			if ( '' !== $name ) {
				$data[ $side . '_name' ] = $name;
			}
		}

		return $data;
	}

	/**
	 * The lines added to and removed from a list, one entry a line (blank
	 * lines and surrounding spaces don't count).
	 *
	 * @param string $before_text The list before.
	 * @param string $after_text  The list after.
	 *
	 * @return array{0: array<int, string>, 1: array<int, string>}
	 */
	public static function line_changes( string $before_text, string $after_text ): array {
		$before = self::lines( $before_text );
		$after  = self::lines( $after_text );

		return [ self::minus( $after, $before ), self::minus( $before, $after ) ];
	}

	/**
	 * The non-empty lines of a list.
	 *
	 * @param string $text The list.
	 *
	 * @return array<int, string>
	 */
	private static function lines( string $text ): array {
		return array_values( array_filter( array_map( 'trim', explode( "\n", str_replace( "\r", '', $text ) ) ), static fn( string $line ): bool => '' !== $line ) );
	}

	/**
	 * The lines of one list that the other doesn't have, as often as they
	 * are extra (a line listed twice and kept once was removed once).
	 *
	 * @param array<int, string> $lines The list.
	 * @param array<int, string> $other The list to take away.
	 *
	 * @return array<int, string>
	 */
	private static function minus( array $lines, array $other ): array {
		$left = array_count_values( $other );
		$out  = [];
		foreach ( $lines as $line ) {
			if ( ( $left[ $line ] ?? 0 ) > 0 ) {
				--$left[ $line ];
				continue;
			}
			$out[] = $line;
		}

		return $out;
	}

	/**
	 * A value cut to MAX_VALUE characters.
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 */
	private static function cut( string $value ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::MAX_VALUE ) : substr( $value, 0, self::MAX_VALUE );
	}

	/**
	 * A page's title as plain text; '' for none or a page that is gone.
	 *
	 * @param int $id The page ID.
	 *
	 * @return string
	 */
	private static function post_title( int $id ): string {
		$post = $id > 0 ? get_post( $id ) : null;

		return $post instanceof \WP_Post ? html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ) : '';
	}

	/**
	 * A term's name as plain text; '' for none or a term that is gone.
	 *
	 * @param int    $id       The term ID.
	 * @param string $taxonomy The taxonomy.
	 *
	 * @return string
	 */
	private static function term_name( int $id, string $taxonomy ): string {
		$term = $id > 0 ? get_term( $id, $taxonomy ) : null;

		return $term instanceof \WP_Term ? html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ) : '';
	}

	/**
	 * A media library item's file name; '' for none or a file that is gone.
	 *
	 * @param int $id The attachment ID.
	 *
	 * @return string
	 */
	private static function file_name( int $id ): string {
		$file = $id > 0 ? get_post_meta( $id, '_wp_attached_file', true ) : '';

		return is_string( $file ) && '' !== $file ? wp_basename( $file ) : '';
	}
}
