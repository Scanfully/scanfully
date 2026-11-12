<?php
/**
 * The targets class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Connect;

use Scanfully\Options\Controller as OptionsController;

/**
 * The targets of this site (see Target): its own connection when it has
 * one, and, on a subsite of a connected network whose owner monitors it,
 * the network's child site for it. One blog can have both, even in two
 * Scanfully accounts. The main site of a network connected as a whole has
 * only the network's connection (its own was taken over), so what happens
 * there and in Network Admin goes to the network's site only.
 *
 * Two ways to ask:
 * - here(), on the hot path (an activity event, a WooCommerce change): the
 *   own connection's stored state and the subsite's marker, read from the
 *   autoloaded options;
 * - now(), in a job that sends: the child is checked from end to end (see
 *   Children::current()).
 *
 * Both follow the site WordPress is on, so an event raised while a request
 * works on another site of the network (switch_to_blog()) goes to that
 * site's targets.
 */
final class Targets {

	/**
	 * The targets as the hot path sees them.
	 *
	 * @return Target[]
	 */
	public static function here(): array {
		return self::of( Children::marker_here() );
	}

	/**
	 * The targets as a job that sends sees them.
	 *
	 * @return Target[]
	 */
	public static function now(): array {
		$child = Children::current();

		return self::of(
			null === $child ? null : [
				'site_id' => $child->site_id(),
				'blog_id' => $child->blog_id(),
			]
		);
	}

	/**
	 * Whether the site has a target at all, on the hot path.
	 *
	 * @return bool
	 */
	public static function reachable(): bool {
		return [] !== self::here();
	}

	/**
	 * A target by its key, checked as now() does; '' is the own connection
	 * (a job queued before targets existed). Null when it is no target of
	 * this site now.
	 *
	 * @param string $key The key (Target::key()).
	 *
	 * @return Target|null
	 */
	public static function find( string $key ): ?Target {
		$key = '' === $key ? Target::OWN : $key;
		foreach ( self::now() as $target ) {
			if ( $target->key() === $key ) {
				return $target;
			}
		}

		return null;
	}

	/**
	 * Some targets of now() by key; all of them for null.
	 *
	 * @param string[]|null $keys The keys, or null.
	 *
	 * @return Target[]
	 */
	public static function only( ?array $keys ): array {
		$targets = self::now();
		if ( null === $keys ) {
			return $targets;
		}
		$keys = array_map(
			static function ( $key ): string {
				return '' === $key ? Target::OWN : (string) $key;
			},
			$keys
		);

		return array_values(
			array_filter(
				$targets,
				static function ( Target $target ) use ( $keys ): bool {
					return in_array( $target->key(), $keys, true );
				}
			)
		);
	}

	/**
	 * The targets: the own connection when connected, and the child of a
	 * marker. A child with the own connection's site id (a site attached to
	 * the network keeps its id while its own grant ended) is the child only.
	 *
	 * @param array{site_id: string, blog_id: int}|null $child The child, or null.
	 *
	 * @return Target[]
	 */
	private static function of( ?array $child ): array {
		$targets = [];
		$options = OptionsController::get_options();
		if ( $options->is_connected && ( null === $child || $options->site_id !== $child['site_id'] ) ) {
			$targets[] = Target::own( $options->site_id );
		}
		if ( null !== $child ) {
			$targets[] = Target::child( $child['site_id'], $child['blog_id'] );
		}

		return $targets;
	}
}
