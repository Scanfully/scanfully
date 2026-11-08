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
	private string $body = 'PK fake zip';

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

		$e = $this->failure( fn() => Installs::plugin( [ 'slug' => 'akismet' ], static function (): void {}, $this->run ) );

		$this->assertSame( 'package', $e->error_class() );
		$this->assertSame( '5.0', $e->result()['new_version'] );
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
