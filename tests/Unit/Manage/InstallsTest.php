<?php
/**
 * Manage install action unit tests: from wordpress.org and from an upload,
 * with WordPress's upgraders and the download faked.
 *
 * @package Scanfully\Tests\Unit\Manage
 */

namespace Scanfully\Tests\Unit\Manage;

use Brain\Monkey\Functions;
use Scanfully\Manage\Installs;
use Scanfully\Manage\Package;
use Scanfully\Manage\JobRun;
use Scanfully\Manage\Runner;

/**
 * @covers \Scanfully\Manage\Installs
 * @covers \Scanfully\Manage\Package
 * @covers \Scanfully\Manage\Action
 */
final class InstallsTest extends SiteTestCase {

	private const UPLOAD = 'c3c3c3c3-3333-4333-8333-333333333333';

	/**
	 * The bytes the download serves.
	 *
	 * @var string
	 */
	private string $body = '';

	/**
	 * The HTTP status the download answers.
	 *
	 * @var int
	 */
	private int $status = 200;

	/**
	 * The downloads: URL and arguments.
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $downloads = [];

	/**
	 * The temporary files made.
	 *
	 * @var string[]
	 */
	private array $temp_files = [];

	protected function setUp(): void {
		parent::setUp();
		$this->body = self::zip( [ 'uploaded/uploaded.php' => "<?php\n/**\n * Plugin Name: Uploaded\n * Version: 0.9\n */\n" ] );
		Functions\when( 'wp_tempnam' )->alias(
			function (): string {
				$file = $this->tmp . '/upload-' . count( $this->temp_files ) . '.tmp';
				touch( $file );
				$this->temp_files[] = $file;
				return $file;
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			function ( string $url, array $args ) {
				$this->downloads[] = [ $url, $args ];
				file_put_contents( $args['filename'], $this->body );
				return [ 'response' => [ 'code' => $this->status ] ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $response ) => $response['response']['code'] ?? '' );
		$this->wporg = static function ( string $kind, array $args ) {
			if ( 'akismet' !== $args['slug'] && 'blocksy' !== $args['slug'] ) {
				return new \WP_Error( $kind . 's_api_failed', ucfirst( $kind ) . ' not found.' );
			}
			return (object) [
				'slug'          => $args['slug'],
				'version'       => '5.3.1',
				'download_link' => "https://downloads.wordpress.org/$kind/{$args['slug']}.5.3.1.zip",
				'versions'      => [
					'5.2.0' => "https://downloads.wordpress.org/$kind/{$args['slug']}.5.2.0.zip",
					'5.3.1' => "https://downloads.wordpress.org/$kind/{$args['slug']}.5.3.1.zip",
					'trunk' => "https://downloads.wordpress.org/$kind/{$args['slug']}.zip",
				],
			];
		};
		$this->install = function ( string $kind, string $package, FakeInstaller $upgrader ) {
			if ( 1 === preg_match( '#/(akismet|blocksy)\.([0-9.]+)\.zip$#', $package, $m ) ) {
				$slug    = $m[1];
				$version = rtrim( $m[2], '.' );
			} else {
				$slug    = 'uploaded';
				$version = '0.9';
			}
			$upgrader->skin->feedback( 'Installing the package…' );
			if ( 'plugin' === $kind ) {
				$this->write_plugin( $slug, $version );
				$upgrader->installed = "$slug/$slug.php";
				$this->run->tag_event( [ 'slug' => "$slug/$slug.php" ], 'PluginInstalled' );
			} else {
				$this->write_theme( $slug, $version );
				$upgrader->installed = $slug;
				$this->run->tag_event( [ 'slug' => $slug ], 'ThemeInstalled' );
			}
			return true;
		};
	}

	private function with_package( string $sha256, string $url = 'https://api.scanfully.com/v1/manage/packages/' . self::JOB . '?token=SECRET' ): JobRun {
		$this->run->set_package(
			[
				'url'    => $url,
				'sha256' => $sha256,
			]
		);
		return $this->run;
	}

	/**
	 * The parameters of an upload job, as Scanfully signs them.
	 *
	 * @param string|null $sha256 The SHA-256 of the ZIP; the served body's when null.
	 *
	 * @return array<string, string>
	 */
	private function upload_params( ?string $sha256 = null ): array {
		return [
			'sha256'    => $sha256 ?? hash( 'sha256', $this->body ),
			'upload_id' => self::UPLOAD,
		];
	}

	public function test_a_plugin_is_installed_from_wordpress_org_at_the_newest_version(): void {
		$result = Installs::plugin( [ 'slug' => 'akismet' ], static function (): void {}, $this->run );

		$this->assertSame( [ [ 'plugin', 'https://downloads.wordpress.org/plugin/akismet.5.3.1.zip' ] ], $this->installs, 'the package wordpress.org names, never a URL from the job' );
		$this->assertSame( '', $result['previous_version'] );
		$this->assertSame( '5.3.1', $result['new_version'] );
		$this->assertSame( 'akismet', $result['slug'] );
		$this->assertSame( 'downloads.wordpress.org', $result['source'] );
		$this->assertCount( 1, $result['activity_event_ids'], 'the install event carries the job id' );
		$this->assertFalse( $result['inventory']['plugins'][0]['active'], 'an install switches nothing on' );
	}

	public function test_a_plugin_is_installed_at_the_version_asked_for(): void {
		$result = Installs::plugin(
			[
				'slug'    => 'akismet',
				'version' => '5.2.0',
			],
			static function (): void {},
			$this->run
		);

		$this->assertSame( 'https://downloads.wordpress.org/plugin/akismet.5.2.0.zip', $this->installs[0][1] );
		$this->assertSame( '5.2.0', $result['new_version'] );
	}

	public function test_a_version_wordpress_org_does_not_offer_is_not_available(): void {
		$e = $this->failure(
			fn() => Installs::plugin(
				[
					'slug'    => 'akismet',
					'version' => '9.9',
				],
				static function (): void {},
				$this->run
			)
		);

		$this->assertSame( 'not_available', $e->error_class() );
		$this->assertSame( 'wordpress.org offers no version 9.9 of the plugin "akismet".', $e->getMessage() );
		$this->assertSame( [], $this->installs );
	}

	public function test_trunk_is_never_installed(): void {
		$e = $this->failure(
			fn() => Installs::plugin(
				[
					'slug'    => 'akismet',
					'version' => 'trunk',
				],
				static function (): void {},
				$this->run
			)
		);

		$this->assertSame( 'not_available', $e->error_class() );
	}

	public function test_a_slug_wordpress_org_does_not_know_is_not_available(): void {
		$e = $this->failure( fn() => Installs::plugin( [ 'slug' => 'nope' ], static function (): void {}, $this->run ) );

		$this->assertSame( 'not_available', $e->error_class() );
		$this->assertStringContainsString( 'Plugin not found.', $e->getMessage() );
	}

	public function test_wordpress_org_out_of_reach_is_a_package_failure_or_a_timeout(): void {
		$this->wporg = static fn() => new \WP_Error( 'plugins_api_failed', 'An unexpected error occurred.' );
		$this->assertSame( 'package', $this->failure( fn() => Installs::plugin( [ 'slug' => 'akismet' ], static function (): void {}, $this->run ) )->error_class() );

		$this->wporg = static fn() => new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$this->assertSame( 'timeout', $this->failure( fn() => Installs::plugin( [ 'slug' => 'akismet' ], static function (): void {}, $this->run ) )->error_class() );
	}

	public function test_an_installed_plugin_is_refused_as_already_installed(): void {
		$this->write_plugin( 'akismet', '5.3.1' );

		$same = $this->failure( fn() => Installs::plugin( [ 'slug' => 'akismet' ], static function (): void {}, $this->run ) );
		$this->assertSame( 'already_installed', $same->error_class() );
		$this->assertSame( '5.3.1', $same->result()['previous_version'] );
		$this->assertSame( '5.3.1', $same->result()['new_version'] );
		$this->assertSame( [], $this->installs );

		$e = $this->failure(
			fn() => Installs::plugin(
				[
					'slug'    => 'akismet',
					'version' => '5.2.0',
				],
				static function (): void {},
				$this->run
			)
		);
		$this->assertSame( 'already_installed', $e->error_class() );
		$this->assertStringContainsString( 'Update the plugin instead.', $e->getMessage() );
	}

	public function test_a_package_that_needs_a_newer_php_is_not_supported(): void {
		$this->install = static fn() => new \WP_Error( 'incompatible_php_required_version', 'The PHP version on your server is 7.4, however the uploaded plugin requires 8.1.' );

		$e = $this->failure( fn() => Installs::plugin( [ 'slug' => 'akismet' ], static function (): void {}, $this->run ) );

		$this->assertSame( 'not_supported', $e->error_class() );
		$this->assertSame( 'downloads.wordpress.org', $e->result()['source'] );
	}

	public function test_an_install_that_puts_another_version_in_place_fails_as_package(): void {
		$this->install = function ( string $kind, string $package, FakeInstaller $upgrader ): bool {
			$this->write_plugin( 'akismet', '5.0' );
			$upgrader->installed = 'akismet/akismet.php';
			return true;
		};

		$e = $this->failure(
			fn() => Installs::plugin(
				[
					'slug'    => 'akismet',
					'version' => '5.3.1',
				],
				static function (): void {},
				$this->run
			)
		);

		$this->assertSame( 'package', $e->error_class() );
		$this->assertSame( '5.0', $e->result()['new_version'] );
	}

	public function test_the_version_check_reads_versions_as_versions(): void {
		$this->install = function ( string $kind, string $package, FakeInstaller $upgrader ): bool {
			$this->write_plugin( 'akismet', '5.2' );
			$upgrader->installed = 'akismet/akismet.php';
			return true;
		};

		$result = Installs::plugin(
			[
				'slug'    => 'akismet',
				'version' => '5.2.0',
			],
			static function (): void {},
			$this->run
		);

		$this->assertSame( '5.2', $result['new_version'], '5.2 is 5.2.0' );
	}

	public function test_a_wordpress_org_install_downloaded_from_elsewhere_is_refused(): void {
		$this->wporg = static fn() => (object) [
			'version'       => '5.3.1',
			'download_link' => 'https://evil.test/akismet.zip',
		];

		$e = $this->failure( fn() => Installs::plugin( [ 'slug' => 'akismet' ], static function (): void {}, $this->run ) );

		$this->assertSame( 'package', $e->error_class() );
		$this->assertStringContainsString( 'evil.test', $e->getMessage() );
		$this->assertSame( [], $this->installs );
	}

	public function test_no_file_changes_allowed_installs_nothing(): void {
		$this->file_mods = false;

		$e = $this->failure( fn() => Installs::plugin( [ 'slug' => 'akismet' ], static function (): void {}, $this->run ) );

		$this->assertSame( 'not_supported', $e->error_class() );
	}

	public function test_a_theme_is_installed_from_wordpress_org(): void {
		$result = Installs::theme(
			[
				'slug'    => 'blocksy',
				'version' => '5.3.1',
			],
			static function (): void {},
			$this->run
		);

		$this->assertSame( [ [ 'theme', 'https://downloads.wordpress.org/theme/blocksy.5.3.1.zip' ] ], $this->installs );
		$this->assertSame( '5.3.1', $result['new_version'] );
		$this->assertSame( 'blocksy', $result['stylesheet'] );
		$this->assertSame( 'downloads.wordpress.org', $result['source'] );
		$this->assertCount( 1, $result['activity_event_ids'] );
		$this->assertSame( 'twentyfive', $this->options['stylesheet'], 'an install switches nothing' );
	}

	public function test_an_installed_theme_is_refused_as_already_installed(): void {
		$e = $this->failure( fn() => Installs::theme( [ 'slug' => 'twentyfive' ], static function (): void {}, $this->run ) );

		$this->assertSame( 'already_installed', $e->error_class() );
		$this->assertSame( '1.3', $e->result()['new_version'] );
		$this->assertSame( [], $this->installs );
	}

	public function test_a_download_whose_checksum_is_not_the_signed_one_is_never_fetched(): void {
		$e = $this->failure( fn() => Installs::plugin_upload( $this->upload_params(), static function (): void {}, $this->with_package( str_repeat( 'b', 64 ) ) ) );

		$this->assertSame( 'package', $e->error_class() );
		$this->assertSame( [], $this->downloads );
	}

	public function test_a_download_on_another_origin_than_the_api_is_never_fetched(): void {
		foreach ( [ 'https://evil.test/v1/manage/packages/x', 'http://api.scanfully.com/v1/manage/packages/x', 'https://api.scanfully.com:8443/x', 'https://user:pw@api.scanfully.com/x' ] as $url ) {
			$e = $this->failure( fn() => Installs::plugin_upload( $this->upload_params(), static function (): void {}, $this->with_package( hash( 'sha256', $this->body ), $url ) ) );
			$this->assertSame( 'package', $e->error_class(), $url );
		}
		$this->assertSame( [], $this->downloads );
		$this->assertSame( [], $this->temp_files );
	}

	public function test_an_upload_is_downloaded_checked_installed_and_its_file_removed(): void {
		$result = Installs::plugin_upload( $this->upload_params(), static function (): void {}, $this->with_package( hash( 'sha256', $this->body ) ) );

		$this->assertSame( 'https://api.scanfully.com/v1/manage/packages/' . self::JOB . '?token=SECRET', $this->downloads[0][0] );
		$this->assertSame( 0, $this->downloads[0][1]['redirection'], 'the API serves the file itself' );
		$this->assertSame( $this->temp_files[0], $this->downloads[0][1]['filename'], 'streamed to the temporary file' );
		$this->assertSame( [ [ 'plugin', $this->temp_files[0] ] ], $this->installs, 'installed from the local file' );
		$this->assertFileDoesNotExist( $this->temp_files[0] );
		$this->assertSame( 'uploaded', $result['slug'] );
		$this->assertSame( '0.9', $result['new_version'] );
		$this->assertSame( 'api.scanfully.com', $result['source'] );
		$this->assertStringNotContainsString( 'SECRET', (string) json_encode( $result ) );
	}

	public function test_an_upload_whose_hash_does_not_match_is_refused_before_anything_is_unpacked(): void {
		$e = $this->failure( fn() => Installs::plugin_upload( $this->upload_params( str_repeat( 'a', 64 ) ), static function (): void {}, $this->with_package( str_repeat( 'a', 64 ) ) ) );

		$this->assertSame( 'package', $e->error_class() );
		$this->assertSame( 'The downloaded upload does not match the SHA-256 checksum Scanfully gave for it, so nothing was unpacked or installed.', $e->getMessage() );
		$this->assertSame( [], $this->installs );
		$this->assertFileDoesNotExist( $this->temp_files[0] );
		$this->assertArrayHasKey( 'inventory', $e->result(), 'a refused download still answers the inventory' );
		$this->assertSame( '', $e->result()['new_version'] );
		$this->assertIsInt( $e->result()['duration_ms'] );
	}

	public function test_the_download_reads_no_more_than_the_size_the_pull_named(): void {
		$this->run->set_package(
			[
				'url'    => 'https://api.scanfully.com/v1/manage/packages/' . self::JOB . '?token=t',
				'sha256' => hash( 'sha256', $this->body ),
				'size'   => strlen( $this->body ),
			]
		);

		Installs::plugin_upload( $this->upload_params(), static function (): void {}, $this->run );

		$this->assertSame( strlen( $this->body ) + 1, $this->downloads[0][1]['limit_response_size'] );
	}

	public function test_a_download_of_another_size_than_named_is_refused(): void {
		$this->run->set_package(
			[
				'url'    => 'https://api.scanfully.com/v1/manage/packages/' . self::JOB . '?token=t',
				'sha256' => hash( 'sha256', $this->body ),
				'size'   => strlen( $this->body ) - 1,
			]
		);

		$e = $this->failure( fn() => Installs::plugin_upload( $this->upload_params(), static function (): void {}, $this->run ) );

		$this->assertSame( 'package', $e->error_class() );
		$this->assertSame( 'The downloaded upload does not match the SHA-256 checksum Scanfully gave for it, so nothing was unpacked or installed.', $e->getMessage() );
		$this->assertSame( [], $this->installs );
		$this->assertFileDoesNotExist( $this->temp_files[0] );
	}

	/**
	 * A ZIP's bytes.
	 *
	 * @param array<string, string> $entries The entries, by name; a name ending in / is a folder.
	 * @param bool                  $store   Whether to store the files uncompressed.
	 *
	 * @return string
	 */
	private static function zip( array $entries, bool $store = false ): string {
		$file = tempnam( sys_get_temp_dir(), 'scanfully-zip' );
		$zip  = new \ZipArchive();
		$zip->open( $file, \ZipArchive::OVERWRITE );
		foreach ( $entries as $name => $content ) {
			if ( '/' === substr( $name, -1 ) ) {
				$zip->addEmptyDir( rtrim( $name, '/' ) );
				continue;
			}
			$zip->addFromString( $name, $content );
			if ( $store ) {
				$zip->setCompressionName( $name, \ZipArchive::CM_STORE );
			}
		}
		$zip->close();
		$bytes = (string) file_get_contents( $file );
		unlink( $file );
		return $bytes;
	}

	/**
	 * Run an upload install of a ZIP and give the JobFailed it threw.
	 *
	 * @param string $bytes The ZIP.
	 */
	private function refused_zip( string $bytes ): \Scanfully\Manage\JobFailed {
		$this->body = $bytes;
		return $this->failure( fn() => Installs::plugin_upload( $this->upload_params(), static function (): void {}, $this->with_package( hash( 'sha256', $this->body ) ) ) );
	}

	public function test_a_zip_with_more_than_one_top_level_folder_is_refused(): void {
		$e = $this->refused_zip(
			self::zip(
				[
					'one/one.php' => '<?php',
					'two/two.php' => '<?php',
				]
			)
		);

		$this->assertSame( 'package', $e->error_class() );
		$this->assertStringContainsString( 'more than one top-level folder', $e->getMessage() );
		$this->assertSame( [], $this->installs );
		$this->assertFileDoesNotExist( $this->temp_files[0] );
	}

	public function test_a_zip_with_a_file_at_its_root_or_outside_its_folder_is_refused(): void {
		$root = $this->refused_zip(
			self::zip(
				[
					'one/one.php' => '<?php',
					'readme.txt'  => 'hi',
				]
			)
		);
		$this->assertStringContainsString( 'outside a folder', $root->getMessage() );

		$climb = $this->refused_zip(
			self::zip(
				[
					'one/one.php'       => '<?php',
					'one/../../evil.php' => '<?php',
				]
			)
		);
		$this->assertSame( 'package', $climb->error_class() );
		$this->assertStringContainsString( 'outside its folder', $climb->getMessage() );
		$this->assertSame( [], $this->installs );
	}

	public function test_what_is_not_a_zip_is_refused(): void {
		$e = $this->refused_zip( 'PK not really a zip' );

		$this->assertSame( 'package', $e->error_class() );
		$this->assertStringContainsString( 'not a ZIP', $e->getMessage() );
	}

	public function test_a_zip_with_too_many_entries_is_refused(): void {
		$entries = [];
		for ( $i = 0; $i <= Package::MAX_ENTRIES; $i++ ) {
			$entries[ "many/f$i.txt" ] = '';
		}

		$e = $this->refused_zip( self::zip( $entries ) );

		$this->assertSame( 'package', $e->error_class() );
		$this->assertStringContainsString( '20001 entries', $e->getMessage() );
	}

	public function test_a_zip_that_declares_or_unpacks_to_too_much_is_refused(): void {
		$file = $this->tmp . '/bomb.zip';
		file_put_contents( $file, self::zip( [ 'bomb/zeros.txt' => str_repeat( "\0", 300000 ) ] ) );

		// Declared: the central directory says 300,000 bytes.
		$declared = $this->failure( fn() => Package::inspect( $file, $this->run, 200000 ) );
		$this->assertStringContainsString( 'unpacks to more than', $declared->getMessage() );

		// A ZIP that lies: its central directory says 1 byte, it inflates to
		// 300,000.
		$bytes = (string) file_get_contents( $file );
		$at    = strpos( $bytes, "PK\x01\x02" );
		$bytes = substr_replace( $bytes, pack( 'V', 1 ), $at + 24, 4 );
		file_put_contents( $file, $bytes );
		$zip = new \ZipArchive();
		$zip->open( $file );
		$this->assertSame( 1, $zip->statIndex( 0 )['size'], 'the crafted archive declares 1 byte' );
		$zip->close();

		$lie = $this->failure( fn() => Package::inspect( $file, $this->run, 200000 ) );
		$this->assertSame( 'package', $lie->error_class() );
	}

	public function test_a_zip_with_two_entries_of_one_name_is_refused(): void {
		// A small entry and a large one, then the large one renamed to the
		// small one's name in its local header and the central directory:
		// read by name, the large one would never be counted.
		$file  = $this->tmp . '/twins.zip';
		$bytes = self::zip(
			[
				'twin/aaaa.txt' => 'a',
				'twin/bbbb.txt' => str_repeat( "\0", 300000 ),
			]
		);
		$bytes = str_replace( 'twin/bbbb.txt', 'twin/aaaa.txt', $bytes );
		// It also lies about its size, so only reading every entry finds it.
		$at    = strpos( $bytes, "PK\x01\x02", (int) strpos( $bytes, "PK\x01\x02" ) + 4 );
		$bytes = substr_replace( $bytes, pack( 'V', 1 ), $at + 24, 4 );
		file_put_contents( $file, $bytes );
		$zip = new \ZipArchive();
		$zip->open( $file );
		$this->assertSame( [ 'twin/aaaa.txt', 'twin/aaaa.txt' ], [ $zip->statIndex( 0 )['name'], $zip->statIndex( 1 )['name'] ], 'the crafted archive holds one name twice' );
		$this->assertSame( 1, $zip->statIndex( 1 )['size'], 'and declares 1 byte for the large one' );
		$zip->close();

		$e = $this->failure( fn() => Package::inspect( $file, $this->run, 200000 ) );

		$this->assertSame( 'package', $e->error_class() );
		$this->assertStringContainsString( 'two entries with the same name', $e->getMessage() );
	}

	public function test_a_zip_within_the_limits_passes_the_inspection(): void {
		$file = $this->tmp . '/fine.zip';
		file_put_contents(
			$file,
			self::zip(
				[
					'fine/'          => '',
					'fine/fine.php'  => '<?php',
					'fine/a/b/c.txt' => str_repeat( 'x', 1000 ),
				]
			)
		);

		Package::inspect( $file, $this->run );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_download_at_the_cap_is_taken_and_one_byte_more_is_not(): void {
		$this->body = self::zip(
			[
				'uploaded/uploaded.php' => '<?php',
				'uploaded/pad.bin' => '',
			],
			true
		);
		$this->body = self::zip(
			[
				'uploaded/uploaded.php' => '<?php',
				'uploaded/pad.bin'      => str_repeat( 'p', Package::MAX_BYTES - strlen( $this->body ) ),
			],
			true
		);
		$this->assertSame( Package::MAX_BYTES, strlen( $this->body ), 'a stored ZIP of exactly 50 MB' );
		$result     = Installs::plugin_upload( $this->upload_params(), static function (): void {}, $this->with_package( hash( 'sha256', $this->body ) ) );
		$this->assertSame( Package::MAX_BYTES + 1, $this->downloads[0][1]['limit_response_size'], 'without a size, 50 MB and one byte' );
		$this->assertSame( 'uploaded', $result['slug'] );

		$this->body .= 'z';
		$e           = $this->failure( fn() => Installs::plugin_upload( $this->upload_params(), static function (): void {}, $this->with_package( hash( 'sha256', $this->body ) ) ) );
		$this->assertSame( 'package', $e->error_class() );
		$this->assertStringContainsString( 'larger than the 50 MB', $e->getMessage() );
		$this->body = '';
	}

	public function test_an_empty_download_is_refused(): void {
		$this->body = '';

		$e = $this->failure( fn() => Installs::plugin_upload( $this->upload_params( hash( 'sha256', '' ) ), static function (): void {}, $this->with_package( hash( 'sha256', '' ) ) ) );

		$this->assertSame( 'package', $e->error_class() );
		$this->assertStringContainsString( 'empty', $e->getMessage() );
		$this->assertFileDoesNotExist( $this->temp_files[0] );
	}

	public function test_a_download_that_times_out_or_breaks_fails_and_leaves_no_file(): void {
		$error = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 300000 milliseconds' );
		Functions\when( 'wp_remote_get' )->alias(
			function ( string $url, array $args ) use ( &$error ) {
				file_put_contents( $args['filename'], 'partial' );
				return $error;
			}
		);

		$timeout = $this->failure( fn() => Installs::plugin_upload( $this->upload_params(), static function (): void {}, $this->with_package( hash( 'sha256', $this->body ) ) ) );
		$this->assertSame( 'timeout', $timeout->error_class() );
		$this->assertFileDoesNotExist( $this->temp_files[0] );

		$error  = new \WP_Error( 'http_request_failed', 'cURL error 56: Recv failure: Connection reset by peer' );
		$broken = $this->failure( fn() => Installs::plugin_upload( $this->upload_params(), static function (): void {}, $this->with_package( hash( 'sha256', $this->body ) ) ) );
		$this->assertSame( 'package', $broken->error_class() );
		$this->assertFileDoesNotExist( $this->temp_files[1] );
	}

	public function test_the_run_s_clean_up_removes_the_temporary_file_when_the_action_never_did(): void {
		$download = Package::fetch( $this->with_package( hash( 'sha256', $this->body ) ), hash( 'sha256', $this->body ) );
		$this->assertFileExists( $download['file'] );

		// PHP stops before the action removes it: the run's clean-up does.
		$this->run->clean();

		$this->assertFileDoesNotExist( $download['file'] );
	}

	public function test_an_upload_the_download_refuses_fails_as_package_and_leaves_no_file(): void {
		$this->status = 410;

		$e = $this->failure( fn() => Installs::theme_upload( $this->upload_params(), static function (): void {}, $this->with_package( hash( 'sha256', $this->body ) ) ) );

		$this->assertSame( 'package', $e->error_class() );
		$this->assertStringContainsString( 'HTTP 410', $e->getMessage() );
		$this->assertFileDoesNotExist( $this->temp_files[0] );
	}

	public function test_an_upload_without_its_download_installs_nothing(): void {
		$e = $this->failure( fn() => Installs::plugin_upload( $this->upload_params(), static function (): void {}, $this->run ) );

		$this->assertSame( 'package', $e->error_class() );
		$this->assertSame( [], $this->downloads );
	}

	public function test_an_upload_that_fails_to_install_still_removes_its_file(): void {
		$this->install = static fn() => new \WP_Error( 'incompatible_archive_no_plugins', 'No valid plugins were found.' );

		$e = $this->failure( fn() => Installs::plugin_upload( $this->upload_params(), static function (): void {}, $this->with_package( hash( 'sha256', $this->body ) ) ) );

		$this->assertSame( 'package', $e->error_class() );
		$this->assertFileDoesNotExist( $this->temp_files[0] );
	}

	public function test_an_upload_of_a_plugin_that_is_there_already_says_so(): void {
		$this->install = static fn() => new \WP_Error( 'folder_exists', 'Destination folder already exists.' );

		$e = $this->failure( fn() => Installs::plugin_upload( $this->upload_params(), static function (): void {}, $this->with_package( hash( 'sha256', $this->body ) ) ) );

		$this->assertSame( 'already_installed', $e->error_class() );
		$this->assertStringContainsString( 'installed already', $e->getMessage() );
	}

	public function test_a_fatal_mid_install_still_removes_the_temporary_file(): void {
		$this->install = static function (): void {
			throw new \RuntimeException( 'Stopped.' );
		};

		try {
			Installs::plugin_upload( $this->upload_params(), static function (): void {}, $this->with_package( hash( 'sha256', $this->body ) ) );
			$this->fail( 'The install did not stop.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Stopped.', $e->getMessage() );
		}
		$this->assertFileDoesNotExist( $this->temp_files[0] );
	}

	public function test_the_runner_hands_the_pulled_download_to_the_upload_install(): void {
		$result = Runner::run(
			[
				'id'      => self::JOB,
				'action'  => 'install_plugin_upload',
				'params'  => $this->upload_params(),
				'package' => [
					'url'    => 'https://api.scanfully.com/v1/manage/packages/' . self::JOB . '?token=abc',
					'sha256' => strtoupper( hash( 'sha256', $this->body ) ),
				],
			],
			static fn(): bool => true,
			null,
			null,
			$this->run
		);

		$this->assertSame( 'succeeded', $result['state'], (string) json_encode( $result ) );
		$this->assertSame( 'uploaded', $result['result']->slug );
	}

	public function test_a_malformed_upload_id_installs_nothing(): void {
		$e = $this->failure(
			fn() => Installs::plugin_upload(
				[
					'upload_id' => '../x',
					'sha256' => hash( 'sha256', $this->body ),
				],
				static function (): void {},
				$this->with_package( hash( 'sha256', $this->body ) )
			)
		);

		$this->assertSame( 'other', $e->error_class() );
		$this->assertSame( [], $this->downloads );
	}
}
