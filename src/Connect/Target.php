<?php
/**
 * The target class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Connect;

use Scanfully\Options\Controller as OptionsController;

/**
 * Where a request about this site goes: a Scanfully site id, the token it
 * is sent with and, for a subsite of a network, the blog it is about.
 *
 * A site has at most two targets (see Targets):
 * - its own connection (`own`): the connection that applies here, in the
 *   options scope of this request: a site's own, or on the main site of a
 *   network connected as a whole, the network's;
 * - on a subsite of a connected network whose owner monitors it in
 *   Scanfully (`network`): the subsite's own Scanfully site (a child of the
 *   network's), reached with the network connection's token and the
 *   subsite's blog id in the X-Scanfully-Blog-Id header. Only the routes
 *   the API allows for a child take it (activity, the subsite report, PHP
 *   errors and the WooCommerce checkout config).
 *
 * A target never holds a token: it reads it when a request is made, so no
 * token ever ends up in a job's arguments. A job names its target by key().
 */
final class Target {

	/**
	 * The kinds of target.
	 */
	public const OWN     = 'own';
	public const NETWORK = 'network';

	/**
	 * The header naming the blog a network child request is about.
	 */
	public const BLOG_HEADER = 'X-Scanfully-Blog-Id';

	/**
	 * The kind.
	 *
	 * @var string
	 */
	private string $kind;

	/**
	 * The Scanfully site id.
	 *
	 * @var string
	 */
	private string $site_id;

	/**
	 * The blog the target is about; 0 for the own connection.
	 *
	 * @var int
	 */
	private int $blog_id;

	/**
	 * Constructor.
	 *
	 * @param string $kind    The kind.
	 * @param string $site_id The Scanfully site id.
	 * @param int    $blog_id The blog id, or 0.
	 */
	private function __construct( string $kind, string $site_id, int $blog_id ) {
		$this->kind    = $kind;
		$this->site_id = $site_id;
		$this->blog_id = $blog_id;
	}

	/**
	 * The connection that applies here.
	 *
	 * @param string $site_id Its Scanfully site id.
	 *
	 * @return self
	 */
	public static function own( string $site_id ): self {
		return new self( self::OWN, $site_id, 0 );
	}

	/**
	 * A monitored subsite's child site, through the network connection.
	 *
	 * @param string $site_id The child's Scanfully site id.
	 * @param int    $blog_id The subsite's blog id.
	 *
	 * @return self
	 */
	public static function child( string $site_id, int $blog_id ): self {
		return new self( self::NETWORK, $site_id, $blog_id );
	}

	/**
	 * The kind: OWN or NETWORK.
	 *
	 * @return string
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Whether this is a network child.
	 *
	 * @return bool
	 */
	public function is_child(): bool {
		return self::NETWORK === $this->kind;
	}

	/**
	 * The Scanfully site id.
	 *
	 * @return string
	 */
	public function site_id(): string {
		return $this->site_id;
	}

	/**
	 * The blog id of a network child; 0 for the own connection.
	 *
	 * @return int
	 */
	public function blog_id(): int {
		return $this->blog_id;
	}

	/**
	 * How a job names the target: `own`, or `network:<site id>`, so a job
	 * queued while the subsite was monitored as one child site never goes
	 * to another (monitoring it again makes a new one).
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->is_child() ? self::NETWORK . ':' . $this->site_id : self::OWN;
	}

	/**
	 * The access token, read now: the own connection's in this request's
	 * scope, or the network connection's.
	 *
	 * @param bool $fresh Whether to read it from the database, past the options cache.
	 *
	 * @return string
	 */
	public function access_token( bool $fresh = false ): string {
		if ( $this->is_child() ) {
			return Network::access_token( $fresh );
		}

		return $fresh ? OptionsController::get_fresh_options()->access_token : OptionsController::get_option( 'access_token' );
	}

	/**
	 * The headers the target adds to a request: the blog id of a child.
	 *
	 * @return array<string, string>
	 */
	public function headers(): array {
		return $this->is_child() ? [ self::BLOG_HEADER => (string) $this->blog_id ] : [];
	}

	/**
	 * The name of an option that keeps state per target, in this site's own
	 * options: the name itself for the own connection, with `_network` for
	 * the network child.
	 *
	 * @param string $name The option's name for the own connection.
	 *
	 * @return string
	 */
	public function option( string $name ): string {
		return $this->is_child() ? $name . '_network' : $name;
	}
}
