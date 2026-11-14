<?php
/**
 * PHP error attribution unit tests.
 *
 * @package Scanfully\Tests\Unit\Errors
 */

namespace Scanfully\Tests\Unit\Errors;

use Scanfully\Errors\Attribution;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Errors\Attribution
 */
final class AttributionTest extends TestCase {

	/**
	 * Headers asked for, as `kind:slug`.
	 *
	 * @var string[]
	 */
	private array $asked = [];

	private function attribution(): Attribution {
		return new Attribution(
			[
				'plugins'    => '/srv/site/wp-content/plugins',
				'mu_plugins' => '/srv/site/wp-content/mu-plugins/',
				'themes'     => [ '/srv/site/wp-content/themes', '/srv/shared-themes' ],
				'content'    => '/srv/site/wp-content',
				'abspath'    => '/srv/site/',
			],
			'6.8.1',
			function ( string $kind, string $slug ): array {
				$this->asked[] = $kind . ':' . $slug;
				$headers       = [
					'plugin:woocommerce' => [
						'name' => 'WooCommerce',
						'version' => '9.2.0',
					],
					'plugin:hello'       => [
						'name' => 'Hello Dolly',
						'version' => '1.7.2',
					],
					'theme:storefront'   => [
						'name' => 'Storefront &amp; Co',
						'version' => '4.6.0',
					],
					'mu_plugin:host.php' => [
						'name' => 'Host tools',
						'version' => '2',
					],
					'dropin:db.php'      => [
						'name' => 'Query Monitor Database Class (Drop-in)',
						'version' => '',
					],
				];

				return $headers[ $kind . ':' . $slug ] ?? [
					'name' => '',
					'version' => '',
				];
			}
		);
	}

	public function test_a_plugin_file_names_the_plugin_by_its_folder(): void {
		$source = $this->attribution()->source( '/srv/site/wp-content/plugins/woocommerce/includes/class-wc-order.php' );

		$this->assertSame(
			[
				'kind'    => 'plugin',
				'slug'    => 'woocommerce',
				'name'    => 'WooCommerce',
				'version' => '9.2.0',
			],
			$source
		);
	}

	public function test_a_single_file_plugin_is_named_by_its_file(): void {
		$this->assertSame( 'hello', $this->attribution()->source( '/srv/site/wp-content/plugins/hello.php' )['slug'] );
	}

	public function test_a_theme_in_a_second_theme_root_and_a_name_with_entities(): void {
		$source = $this->attribution()->source( '/srv/shared-themes/storefront/functions.php' );

		$this->assertSame( 'theme', $source['kind'] );
		$this->assertSame( 'storefront', $source['slug'] );
		$this->assertSame( 'Storefront & Co', $source['name'] );
	}

	public function test_must_use_plugins_drop_ins_and_core(): void {
		$attribution = $this->attribution();

		$this->assertSame( 'mu_plugin', $attribution->source( '/srv/site/wp-content/mu-plugins/host.php' )['kind'] );
		$this->assertSame( 'host.php', $attribution->source( '/srv/site/wp-content/mu-plugins/host.php' )['slug'] );
		$this->assertSame( 'Host tools', $attribution->source( '/srv/site/wp-content/mu-plugins/host.php' )['name'] );
		$this->assertSame( 'loader', $attribution->source( '/srv/site/wp-content/mu-plugins/loader/includes/a.php' )['slug'] );
		$this->assertSame( 'dropin', $attribution->source( '/srv/site/wp-content/db.php' )['kind'] );
		$this->assertSame( 'db.php', $attribution->source( '/srv/site/wp-content/db.php' )['slug'] );
		$this->assertSame(
			[
				'kind'    => 'core',
				'slug'    => 'wordpress',
				'name'    => 'WordPress',
				'version' => '6.8.1',
			],
			$attribution->source( '/srv/site/wp-includes/class-wp-hook.php' )
		);
		$this->assertSame( 'core', $attribution->source( '/srv/site/wp-admin/admin-ajax.php' )['kind'] );
		$this->assertSame( 'core', $attribution->source( '/srv/site/wp-settings.php' )['kind'] );
	}

	public function test_an_error_inside_core_is_attributed_to_the_plugin_that_called_it(): void {
		$source = $this->attribution()->source(
			'/srv/site/wp-includes/class-wp-hook.php',
			[
				[
					'file' => '/srv/site/wp-includes/plugin.php',
					'line' => 517,
					'function' => 'apply_filters',
				],
				[
					'file' => '/srv/site/wp-content/plugins/woocommerce/woocommerce.php',
					'line' => 40,
					'function' => 'do_action',
				],
				[
					'file' => '/srv/site/wp-content/themes/storefront/functions.php',
					'line' => 9,
					'function' => 'wc_run',
				],
			]
		);

		$this->assertSame( 'plugin', $source['kind'] );
		$this->assertSame( 'woocommerce', $source['slug'] );
	}

	public function test_an_error_inside_core_called_only_by_core_stays_core(): void {
		$source = $this->attribution()->source(
			'/srv/site/wp-includes/formatting.php',
			[
				[
					'file' => '/srv/site/wp-admin/edit.php',
					'line' => 10,
					'function' => 'esc_html',
				],
			]
		);

		$this->assertSame( 'core', $source['kind'] );
	}

	public function test_files_elsewhere_are_unknown(): void {
		$attribution = $this->attribution();

		$this->assertSame( 'unknown', $attribution->source( '/usr/share/php/PEAR.php' )['kind'] );
		$this->assertSame( 'unknown', $attribution->source( '/srv/site/vendor/autoload.php' )['kind'] );
		$this->assertSame( 'unknown', $attribution->source( '/srv/site/wp-content/uploads/x.php' )['kind'] );
		$this->assertSame( 'unknown', $attribution->source( '' )['kind'] );
		$this->assertSame( 'unknown', $attribution->source( 'Unknown' )['kind'] );
	}

	public function test_headers_are_read_once_per_source(): void {
		$attribution = $this->attribution();
		$attribution->source( '/srv/site/wp-content/plugins/woocommerce/a.php' );
		$attribution->source( '/srv/site/wp-content/plugins/woocommerce/b.php' );

		$this->assertSame( [ 'plugin:woocommerce' ], $this->asked );
	}

	public function test_a_header_reader_that_throws_leaves_the_name_empty(): void {
		$attribution = new Attribution(
			[
				'plugins' => '/srv/site/wp-content/plugins',
				'abspath' => '/srv/site',
			],
			'6.8',
			static function (): array {
				throw new \RuntimeException( 'no headers' );
			}
		);

		$this->assertSame( '', $attribution->source( '/srv/site/wp-content/plugins/x/x.php' )['name'] );
		$this->assertSame( 'x', $attribution->source( '/srv/site/wp-content/plugins/x/x.php' )['slug'] );
	}

	public function test_paths_become_relative_to_the_wordpress_root(): void {
		$attribution = $this->attribution();

		$this->assertSame( 'wp-content/plugins/woocommerce/woocommerce.php', $attribution->relative( '/srv/site/wp-content/plugins/woocommerce/woocommerce.php' ) );
		$this->assertSame( 'wp-includes/class-wp-hook.php', $attribution->relative( '/srv/site/wp-includes/class-wp-hook.php' ) );
		$this->assertSame( 'wp-content/themes/storefront/functions.php', $attribution->relative( '/srv/shared-themes/storefront/functions.php' ) );
		$this->assertSame( '…/share/php/PEAR.php', $attribution->relative( '/usr/share/php/PEAR.php' ) );
		$this->assertSame( 'wp-content/plugins/x/x.php', $attribution->relative( '/srv/site/wp-content/plugins/x/x.php(12) : eval()\'d code' ) );
		$this->assertSame( '', $attribution->relative( '' ) );
		$this->assertSame( 'Unknown', $attribution->relative( 'Unknown' ) );
	}

	public function test_windows_paths_and_a_content_folder_outside_the_root(): void {
		$attribution = new Attribution(
			[
				'plugins' => 'C:\\inetpub\\site\\app\\plugins',
				'content' => 'C:\\inetpub\\site\\app',
				'abspath' => 'C:\\inetpub\\site\\wp\\',
			]
		);

		$this->assertSame( 'plugin', $attribution->source( 'C:\\inetpub\\site\\app\\plugins\\shop\\shop.php' )['kind'] );
		$this->assertSame( 'wp-content/plugins/shop/shop.php', $attribution->relative( 'C:\\inetpub\\site\\app\\plugins\\shop\\shop.php' ) );
		$this->assertSame( 'wp-includes/load.php', $attribution->relative( 'C:\\inetpub\\site\\wp\\wp-includes\\load.php' ) );
	}

	public function test_server_paths_in_a_message_are_made_relative(): void {
		$attribution = $this->attribution();

		$this->assertSame(
			"require(): Failed opening 'wp-content/plugins/shop/missing.php' (include_path='.:…/php')",
			$attribution->relative_in_text( "require(): Failed opening '/srv/site/wp-content/plugins/shop/missing.php' (include_path='.:/usr/share/php')" )
		);
		$this->assertSame(
			'Uncaught Error in wp-content/themes/storefront/functions.php:9',
			$attribution->relative_in_text( 'Uncaught Error in /srv/shared-themes/storefront/functions.php:9' )
		);
	}

	public function test_a_root_of_slash_is_ignored(): void {
		$attribution = new Attribution( [ 'abspath' => '/' ] );

		$this->assertSame( 'unknown', $attribution->source( '/etc/passwd' )['kind'] );
		$this->assertSame( '…/etc/passwd', $attribution->relative( '/etc/passwd' ) );
	}
}
