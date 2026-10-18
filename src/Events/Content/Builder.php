<?php
/**
 * The content event builder class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Events\Content;

use Scanfully\Events\Controller;
use Scanfully\Events\PostChanges\Builder as PostBuilder;
use Scanfully\Events\PostChanges\Stash;
use Scanfully\Events\SettingsChanges\Settings;

/**
 * Class Builder
 *
 * The background job of a content event (ContentEvent): reads the data the
 * request stored when it was too long for the job's arguments, adds the
 * names that cost a lookup (a term's parent, the terms added to posts in a
 * bulk edit) and sends the event.
 *
 * @package Scanfully\Events\Content
 */
final class Builder {

	/**
	 * Action Scheduler callback: build a content event and send it.
	 *
	 * @param string $type    The event type.
	 * @param array  $user    The user who made the change.
	 * @param array  $data    The event data, or a marker that it is stored.
	 * @param string $stash   The key of the stored data, or ''.
	 * @param int    $attempt Retry number; 0 for the first attempt.
	 *
	 * @return void
	 * @throws \RuntimeException When the API did not accept the event.
	 */
	public static function build( string $type, array $user, array $data, string $stash = '', int $attempt = 0 ): void {
		$event = $data;
		if ( ! empty( $data['stashed'] ) ) {
			$stored = '' !== $stash ? Stash::get( $stash ) : null;
			if ( ! is_array( $stored['data'] ?? null ) ) {
				// Stored more than a day ago and gone: nothing left to send.
				return;
			}
			$event = $stored['data'];
		}

		$retrying = false;
		try {
			Controller::deliver(
				$type,
				$user,
				self::resolve( $type, $event ),
				$attempt,
				static function () use ( &$retrying, $type, $user, $data, $stash, $attempt ): void {
					$retrying = true;
					as_schedule_single_action( time() + Controller::SEND_RETRY_DELAY, Controller::ACTION_BUILD_CONTENT_EVENT, [ $type, $user, $data, $stash, $attempt + 1 ], 'scanfully' );
				}
			);
		} finally {
			if ( ! $retrying && '' !== $stash ) {
				Stash::delete( $stash );
			}
		}
	}

	/**
	 * The event data with names added.
	 *
	 * @param string               $type The event type.
	 * @param array<string, mixed> $data The event data.
	 *
	 * @return array<string, mixed>
	 */
	public static function resolve( string $type, array $data ): array {
		switch ( $type ) {
			case 'PostBulkAction':
				if ( isset( $data['terms'] ) && is_array( $data['terms'] ) ) {
					$terms = PostBuilder::terms( $data['terms'] );
					if ( [] !== $terms ) {
						$data['terms'] = $terms;
					} else {
						unset( $data['terms'] );
					}
				}
				break;
			case 'TermCreated':
				if ( isset( $data['parent']['id'] ) ) {
					$data['parent'] = self::term( (int) $data['parent']['id'], (string) ( $data['taxonomy'] ?? '' ) );
				}
				break;
			case 'SiteOptionChanged':
				$data = Settings::resolve( $data );
				break;
			case 'TermChanged':
				foreach ( [ 'old', 'new' ] as $side ) {
					if ( isset( $data['changes']['parent'][ $side ]['id'] ) ) {
						$data['changes']['parent'][ $side ] = self::term( (int) $data['changes']['parent'][ $side ]['id'], (string) ( $data['taxonomy'] ?? '' ) );
					}
				}
				break;
		}

		return $data;
	}

	/**
	 * A term by ID and name; the name is '' for none (0) or a term that no
	 * longer exists.
	 *
	 * @param int    $term_id  The term ID.
	 * @param string $taxonomy The taxonomy.
	 *
	 * @return array{id: int, name: string}
	 */
	private static function term( int $term_id, string $taxonomy ): array {
		$term = $term_id > 0 ? get_term( $term_id, $taxonomy ) : null;

		return [
			'id'   => $term_id,
			'name' => $term instanceof \WP_Term ? html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ) : '',
		];
	}
}
