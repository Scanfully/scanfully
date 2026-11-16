<?php
/**
 * Manage own update unit tests: the Scanfully plugin updating itself loads
 * every class first, and never updates a folder that is a symbolic link.
 *
 * @package Scanfully\Tests\Unit\Manage
 */

namespace Scanfully\Tests\Unit\Manage;

use Scanfully\Manage\JobFailed;
use Scanfully\Manage\OwnUpdate;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Manage\OwnUpdate
 * @covers \Scanfully\Manage\JobFailed
 */
final class OwnUpdateTest extends TestCase {

	/**
	 * A folder for the fixture classes and plugin folders.
	 *
	 * @var string
	 */
	private string $tmp;

	/**
	 * The autoloader of the fixture classes.
	 *
	 * @var callable|null
	 */
	private $loader = null;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp = sys_get_temp_dir() . '/scanfully-own-update-' . uniqid( '', true );
		mkdir( $this->tmp, 0777, true );
	}

	protected function tearDown(): void {
		OwnUpdate::$classes = null;
		if ( null !== $this->loader ) {
			spl_autoload_unregister( $this->loader );
		}
		$this->remove( $this->tmp );
		parent::tearDown();
	}

	/**
	 * Remove a folder and what is in it, never following a link.
	 *
	 * @param string $dir The folder.
	 */
	private function remove( string $dir ): void {
		if ( is_link( $dir ) ) {
			unlink( $dir );
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$path = $dir . '/' . $name;
				is_dir( $path ) && ! is_link( $path ) ? $this->remove( $path ) : unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Fixture classes under a namespace of their own, with an autoloader
	 * that maps it to the folder as Composer does.
	 *
	 * @param array<string, string> $files The files, by path under the folder, with their code after the namespace.
	 *
	 * @return string The namespace prefix.
	 */
	private function fixture( array $files ): string {
		$namespace = 'ScanfullyOwnUpdateFixture\\F' . bin2hex( random_bytes( 6 ) );
		$root      = $this->tmp . '/classes';
		foreach ( $files as $path => $code ) {
			if ( ! is_dir( dirname( $root . '/' . $path ) ) ) {
				mkdir( dirname( $root . '/' . $path ), 0777, true );
			}
			$sub = str_replace( '/', '\\', dirname( $path ) );
			file_put_contents( $root . '/' . $path, "<?php\nnamespace $namespace" . ( '.' === $sub ? '' : '\\' . $sub ) . ";\n$code\n" );
		}
		$prefix       = $namespace . '\\';
		$this->loader = static function ( string $name ) use ( $prefix, $root ): void {
			if ( 0 === strpos( $name, $prefix ) ) {
				$file = $root . '/' . str_replace( '\\', '/', substr( $name, strlen( $prefix ) ) ) . '.php';
				if ( is_file( $file ) ) {
					require $file;
				}
			}
		};
		spl_autoload_register( $this->loader );
		OwnUpdate::$classes = [ $root, $prefix ];

		return $prefix;
	}

	public function test_every_class_is_loaded_but_files_without_one_and_classes_whose_outside_parent_is_missing(): void {
		$prefix = $this->fixture(
			[
				'Plain.php'      => 'class Plain {}',
				'Contract.php'   => 'interface Contract {}',
				'Shared.php'     => 'trait Shared {}',
				'Deep/Inner.php' => 'final class Inner extends \\ArrayObject {}',
				'Gateway.php'    => 'class Gateway extends \\Scanfully_Missing_Outside_Gateway {}',
				'Imported.php'   => "use Scanfully_Missing\\Outside\\Base;\nuse Scanfully_Missing\\Outside\\Other as Renamed;\nclass Imported extends Base {}",
				'Aliased.php'    => "use Scanfully_Missing\\Outside\\Other as Renamed;\nclass Aliased extends Renamed {}",
				'Sibling.php'    => "use Scanfully_Missing\\Outside\\Base;\nclass Sibling extends Plain {}",
				'helpers.php'    => 'define( "SCANFULLY_OWN_UPDATE_HELPERS_RAN", true );',
				'Elsewhere.php'  => 'class SomethingElse {}',
			]
		);

		$problems = OwnUpdate::preload();

		$this->assertTrue( class_exists( $prefix . 'Plain', false ) );
		$this->assertTrue( interface_exists( $prefix . 'Contract', false ) );
		$this->assertTrue( trait_exists( $prefix . 'Shared', false ) );
		$this->assertTrue( class_exists( $prefix . 'Deep\\Inner', false ), 'a parent from outside the plugin that is there is fine' );
		$this->assertFalse( class_exists( $prefix . 'Gateway', false ), 'a class whose outside parent is missing is left alone' );
		$this->assertFalse( class_exists( $prefix . 'Imported', false ), 'also when the parent is imported with use' );
		$this->assertFalse( class_exists( $prefix . 'Aliased', false ), 'also when the import has an alias' );
		$this->assertTrue( class_exists( $prefix . 'Sibling', false ), 'a parent of its own namespace loads' );
		$this->assertFalse( defined( 'SCANFULLY_OWN_UPDATE_HELPERS_RAN' ), 'a file without a class is never included' );
		$this->assertCount( 1, $problems, 'a file that does not declare the class of its name is a problem' );
		$this->assertStringContainsString( $prefix . 'Elsewhere', $problems[0] );
	}

	public function test_classes_loaded_already_are_skipped(): void {
		$prefix = $this->fixture( [ 'Plain.php' => 'class Plain {}' ] );
		$this->assertTrue( class_exists( $prefix . 'Plain' ) );

		$this->assertSame( [], OwnUpdate::preload() );
	}

	public function test_every_class_of_the_plugin_loads(): void {
		$this->assertSame( [], OwnUpdate::preload() );
		$this->assertTrue( class_exists( \Scanfully\Manage\Updates::class, false ) );
		$this->assertTrue( class_exists( \Scanfully\Sync\Controller::class, false ) );
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			$this->assertFalse( class_exists( \Scanfully\WooCheckout\ProbeGateway::class, false ), 'needs WooCommerce' );
		}
	}

	public function test_a_folder_that_is_a_symbolic_link_is_never_updated(): void {
		mkdir( $this->tmp . '/checkout' );
		$plugins = WP_PLUGIN_DIR;
		if ( ! is_dir( $plugins ) ) {
			mkdir( $plugins, 0777, true );
		}
		$link = $plugins . '/own-update-linked-' . bin2hex( random_bytes( 4 ) );
		symlink( $this->tmp . '/checkout', $link );
		try {
			OwnUpdate::check( basename( $link ) . '/scanfully.php' );
			$this->fail( 'A linked folder was not refused.' );
		} catch ( JobFailed $e ) {
			$this->assertSame( 'not_supported', $e->error_class() );
			$this->assertStringContainsString( 'symbolic link', $e->getMessage() );
		} finally {
			unlink( $link );
		}
		$this->assertDirectoryExists( $this->tmp . '/checkout', 'the linked folder is left alone' );
	}

	public function test_a_real_folder_is_updated(): void {
		$plugins = WP_PLUGIN_DIR;
		if ( ! is_dir( $plugins ) ) {
			mkdir( $plugins, 0777, true );
		}
		$folder = $plugins . '/own-update-real-' . bin2hex( random_bytes( 4 ) );
		mkdir( $folder );
		try {
			OwnUpdate::check( basename( $folder ) . '/scanfully.php' );
			$this->addToAssertionCount( 1 );
		} finally {
			rmdir( $folder );
		}
	}
}
