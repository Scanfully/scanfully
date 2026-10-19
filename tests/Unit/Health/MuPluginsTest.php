<?php
/**
 * MU plugins and drop-ins unit tests.
 *
 * @package Scanfully\Tests\Unit\Health
 */

namespace Scanfully\Tests\Unit\Health;

use Brain\Monkey\Functions;
use Scanfully\Health\MuPlugins;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Health\MuPlugins
 */
final class MuPluginsTest extends TestCase {

	/**
	 * The temporary `wp-content` folder.
	 *
	 * @var string
	 */
	private string $content = '';

	protected function setUp(): void {
		parent::setUp();
		$this->content = sys_get_temp_dir() . '/scanfully-mu-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->content . '/mu-plugins', 0777, true );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $text ) => strip_tags( (string) $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress is not loaded in unit tests.
	}

	protected function tearDown(): void {
		$this->remove( $this->content );
		parent::tearDown();
	}

	/**
	 * Remove a folder and everything in it.
	 *
	 * @param string $path The folder.
	 *
	 * @return void
	 */
	private function remove( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
		}
		rmdir( $path );
	}

	/**
	 * Write a file, creating its folder, and return its modification time as
	 * the file system has it (the test suite's stream wrapper can shift what
	 * touch() sets).
	 *
	 * @param string $relative Path relative to `wp-content`.
	 * @param string $content  The content.
	 * @param int    $mtime    The modification time to set.
	 *
	 * @return int
	 */
	private function write( string $relative, string $content, int $mtime = 1760000000 ): int {
		$path = $this->content . '/' . $relative;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		touch( $path, $mtime );
		clearstatcache( true, $path );

		return (int) filemtime( $path );
	}

	/**
	 * Scan the temporary folder.
	 *
	 * @param array<string, array<string, mixed>> $mu_plugins Header data of the MU plugin files.
	 * @param array<string, array<string, mixed>> $dropins    Header data of the drop-ins.
	 * @param array<string, string>               $cache      The hash cache; updated.
	 *
	 * @return array<string, array<string, mixed>> Entries by kind and file.
	 */
	private function scan( array $mu_plugins, array $dropins = [], array &$cache = [] ): array {
		$used    = [];
		$entries = MuPlugins::entries( $this->content . '/mu-plugins', $mu_plugins, $this->content, $dropins, $cache, $used );
		$cache   = array_intersect_key( $cache, $used );
		$out     = [];
		foreach ( $entries as $entry ) {
			$out[ $entry['kind'] . ':' . $entry['file'] ] = $entry;
		}

		return $out;
	}

	public function test_a_mu_plugin_file_has_its_header_size_time_and_content_hash(): void {
		$mtime = $this->write( 'mu-plugins/custom-login.php', '<?php /* Plugin Name: Custom Login */' );

		$entries = $this->scan(
			[
				'custom-login.php' => [
					'Name'        => 'Custom <b>Login</b>',
					'Version'     => '1.2.0',
					'Author'      => 'Agency &amp; Co',
					'Description' => 'Brands the login page.',
				],
			]
		);

		$this->assertSame(
			[
				'kind'        => 'mu',
				'file'        => 'custom-login.php',
				'name'        => 'Custom Login',
				'version'     => '1.2.0',
				'author'      => 'Agency & Co',
				'description' => 'Brands the login page.',
				'size'        => 37,
				'modified'    => $mtime,
				'hash'        => 'sha256:' . hash( 'sha256', '<?php /* Plugin Name: Custom Login */' ),
			],
			$entries['mu:custom-login.php']
		);
	}

	public function test_a_file_without_a_name_is_named_by_its_file(): void {
		$this->write( 'mu-plugins/loader.php', '<?php require __DIR__ . "/x/x.php";' );

		$this->assertSame( 'loader.php', $this->scan( [ 'loader.php' => [] ] )['mu:loader.php']['name'] );
	}

	public function test_a_folder_gets_a_fingerprint_over_its_files_and_their_content(): void {
		$this->write( 'mu-plugins/agency/agency.php', 'a' );
		$newest = $this->write( 'mu-plugins/agency/includes/admin.php', 'bb', 1760000100 );

		$folder = $this->scan( [] )['mu_folder:agency'];

		$this->assertSame( 2, $folder['files'] );
		$this->assertSame( 3, $folder['size'] );
		$this->assertSame( $newest, $folder['modified'] );
		$this->assertFalse( $folder['partial'] );
		$this->assertSame( 'sha256:' . hash( 'sha256', "agency.php\0sha256:" . hash( 'sha256', 'a' ) . "\nincludes/admin.php\0sha256:" . hash( 'sha256', 'bb' ) ), $folder['hash'] );
	}

	public function test_a_changed_file_in_a_folder_changes_its_fingerprint(): void {
		$this->write( 'mu-plugins/agency/agency.php', 'a' );
		$before = $this->scan( [] )['mu_folder:agency']['hash'];

		$this->write( 'mu-plugins/agency/agency.php', 'b', 1760000500 );

		$this->assertNotSame( $before, $this->scan( [] )['mu_folder:agency']['hash'] );
	}

	public function test_a_touched_but_unchanged_file_keeps_its_hash(): void {
		$this->write( 'mu-plugins/agency/agency.php', 'same' );
		$cache  = [];
		$before = $this->scan( [], [], $cache )['mu_folder:agency']['hash'];

		$this->write( 'mu-plugins/agency/agency.php', 'same', 1760009999 );

		$this->assertSame( $before, $this->scan( [], [], $cache )['mu_folder:agency']['hash'] );
	}

	public function test_a_cached_hash_is_used_while_size_and_time_are_the_same(): void {
		$mtime = $this->write( 'mu-plugins/custom.php', 'abc' );
		$cache = [ 'mu-plugins/custom.php' => '3:' . $mtime . ':sha256:cached' ];

		$this->assertSame( 'sha256:cached', $this->scan( [ 'custom.php' => [] ], [], $cache )['mu:custom.php']['hash'] );
	}

	public function test_the_cache_keeps_only_files_that_are_still_there(): void {
		$this->write( 'mu-plugins/custom.php', 'abc' );
		$cache = [ 'mu-plugins/gone.php' => '1:1:sha256:x' ];

		$this->scan( [ 'custom.php' => [] ], [], $cache );

		$this->assertSame( [ 'mu-plugins/custom.php' ], array_keys( $cache ) );
	}

	public function test_drop_ins_are_listed_with_their_hash(): void {
		$this->write( 'object-cache.php', '<?php // cache' );

		$dropin = $this->scan(
			[],
			[
				'object-cache.php' => [
					'Name' => 'Redis Object Cache Drop-In',
					'Version' => '2.6.5',
				],
			]
		)['dropin:object-cache.php'];

		$this->assertSame( 'Redis Object Cache Drop-In', $dropin['name'] );
		$this->assertSame( '2.6.5', $dropin['version'] );
		$this->assertSame( 'sha256:' . hash( 'sha256', '<?php // cache' ), $dropin['hash'] );
	}

	public function test_files_that_are_gone_are_left_out(): void {
		$this->assertSame( [], $this->scan( [ 'gone.php' => [ 'Name' => 'Gone' ] ], [ 'db.php' => [] ] ) );
	}

	public function test_a_missing_mu_plugins_folder_gives_no_entries(): void {
		rmdir( $this->content . '/mu-plugins' );

		$this->assertSame( [], $this->scan( [] ) );
	}

	public function test_the_quick_fingerprint_changes_with_a_new_file_or_a_new_time_and_reads_no_file(): void {
		$this->write( 'mu-plugins/custom.php', 'abc' );
		$this->write( 'object-cache.php', 'x' );
		$first = MuPlugins::stat_fingerprint( $this->content . '/mu-plugins', $this->content, [ 'object-cache.php' ] );

		$this->assertSame( $first, MuPlugins::stat_fingerprint( $this->content . '/mu-plugins', $this->content, [ 'object-cache.php' ] ) );

		$this->write( 'mu-plugins/new.php', 'n' );
		$second = MuPlugins::stat_fingerprint( $this->content . '/mu-plugins', $this->content, [ 'object-cache.php' ] );
		$this->assertNotSame( $first, $second );

		$this->write( 'object-cache.php', 'x', 1760000500 );
		$this->assertNotSame( $second, MuPlugins::stat_fingerprint( $this->content . '/mu-plugins', $this->content, [ 'object-cache.php' ] ) );
	}

	public function test_a_hidden_entry_is_left_out(): void {
		$this->write( 'mu-plugins/.git/HEAD', 'ref' );

		$this->assertSame( [], $this->scan( [] ) );
	}
}
