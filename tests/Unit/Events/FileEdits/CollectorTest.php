<?php
/**
 * File edit collector unit tests.
 *
 * @package Scanfully\Tests\Unit\Events\FileEdits
 */

namespace Scanfully\Tests\Unit\Events\FileEdits;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Scanfully\Events\Content\Builder;
use Scanfully\Events\FileEdits\Collector;
use Scanfully\Events\PostChanges\ContentDiff;
use Scanfully\Tests\Unit\Events\PostChanges\ContentDiffTest;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \Scanfully\Events\FileEdits\Collector
 * @covers \Scanfully\Events\PostChanges\ContentDiff::compare_code
 * @covers \Scanfully\Events\PostChanges\ContentDiff::code_lines
 */
final class CollectorTest extends TestCase {

	/**
	 * Files written by a test.
	 *
	 * @var array<int, string>
	 */
	private array $files = [];

	protected function setUp(): void {
		parent::setUp();
		Collector::reset();
	}

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- A temporary test file.
			}
		}
		Collector::reset();
		parent::tearDown();
	}

	/**
	 * A temporary file with the given content.
	 *
	 * @param string $content The content.
	 *
	 * @return string
	 */
	private function file( string $content ): string {
		$path          = (string) tempnam( sys_get_temp_dir(), 'scanfully-file-edit-' );
		$this->files[] = $path;
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary test file.

		return $path;
	}

	public function test_a_small_file_is_read_with_its_content(): void {
		$state = Collector::read( $this->file( "<?php\necho 1;\n" ) );

		$this->assertSame( "<?php\necho 1;\n", $state['content'] );
		$this->assertSame( md5( "<?php\necho 1;\n" ), $state['hash'] );
	}

	public function test_a_large_file_is_only_hashed(): void {
		$content = str_repeat( 'a', Collector::MAX_CONTENT + 1 );
		$state   = Collector::read( $this->file( $content ) );

		$this->assertArrayNotHasKey( 'content', $state );
		$this->assertSame( md5( $content ), $state['hash'] );
	}

	public function test_a_missing_file_has_no_hash(): void {
		$this->assertSame( [ 'hash' => '' ], Collector::read( sys_get_temp_dir() . '/scanfully-no-such-file-' . uniqid() ) );
	}

	public function test_an_unchanged_or_restored_file_is_no_change(): void {
		$state = [
			'content' => 'a',
			'hash'    => md5( 'a' ),
		];

		$this->assertNull( Collector::change( $state, $state ) );
		$this->assertNull( Collector::change( [ 'hash' => '' ], $state ), 'The file could not be read before.' );
		$this->assertNull( Collector::change( $state, [ 'hash' => '' ] ), 'The file is gone.' );
	}

	public function test_a_changed_file_is_kept_for_the_job(): void {
		$change = Collector::change(
			[
				'content' => 'a',
				'hash'    => md5( 'a' ),
			],
			[
				'content' => 'b',
				'hash'    => md5( 'b' ),
			]
		);

		$this->assertSame(
			[
				'stash' => [
					'old' => 'a',
					'new' => 'b',
				],
			],
			$change
		);
	}

	public function test_a_changed_large_file_is_reported_without_content(): void {
		$this->assertSame( [ 'too_large' => true ], Collector::change( [ 'hash' => 'x' ], [ 'hash' => 'y' ] ) );
	}

	public function test_nothing_is_noted_without_a_file_or_a_plugin_or_theme(): void {
		Functions\when( 'validate_file' )->justReturn( 0 );
		Functions\expect( 'current_user_can' )->never();

		Collector::note( [ 'file' => 'akismet/akismet.php' ] );
		Collector::note( [ 'plugin' => 'akismet/akismet.php' ] );
		Collector::note( 'nonsense' );

		Actions\expectDone( 'scanfully_content_event_PluginFileEdited' )->never();
		Collector::flush();
	}

	public function test_nothing_is_noted_for_a_user_who_may_not_edit_plugins(): void {
		Functions\when( 'validate_file' )->justReturn( 0 );
		Functions\expect( 'current_user_can' )->once()->with( 'edit_plugins' )->andReturn( false );

		Collector::note(
			[
				'file'   => 'akismet/akismet.php',
				'plugin' => 'akismet/akismet.php',
			]
		);

		Actions\expectDone( 'scanfully_content_event_PluginFileEdited' )->never();
		Collector::flush();
	}

	public function test_a_path_that_climbs_out_is_not_noted(): void {
		Functions\when( 'validate_file' )->alias( static fn( $file ) => false !== strpos( (string) $file, '..' ) ? 1 : 0 );
		Functions\expect( 'current_user_can' )->never();

		Collector::note(
			[
				'file'   => '../wp-config.php',
				'plugin' => 'akismet/akismet.php',
			]
		);
	}

	public function test_code_lines_keep_indentation_and_drop_line_endings(): void {
		$this->assertSame( [ '<?php', "\tif ( true ) {", '    echo 1;', '}', '' ], ContentDiff::code_lines( "<?php\r\n\tif ( true ) {  \n    echo 1;\n}\n" ) );
		$this->assertSame( [], ContentDiff::code_lines( '' ) );
	}

	public function test_only_whitespace_at_line_ends_is_no_diff(): void {
		$this->assertSame(
			[
				'changed' => true,
				'reason'  => 'whitespace',
			],
			ContentDiff::compare_code( "a\nb\n", "a  \r\nb\r\n", [ ContentDiffTest::class, 'naive_diff' ] )
		);
	}

	public function test_a_code_change_is_a_diff_of_the_lines_as_written(): void {
		$diff = ContentDiff::compare_code( "<?php\n\tfoo();\n", "<?php\n\tbar();\n", [ ContentDiffTest::class, 'naive_diff' ] );

		$this->assertSame( 1, $diff['added'] );
		$this->assertSame( 1, $diff['removed'] );
		$this->assertContains(
			[
				'op'   => '+',
				'text' => "\tbar();",
			],
			$diff['lines']
		);
	}

	public function test_a_large_file_edit_is_reported_as_too_large_by_the_job(): void {
		$data = Builder::resolve(
			'ThemeFileEdited',
			[
				'name'    => 'Astra',
				'file'    => 'functions.php',
				'changes' => [ 'content' => [ 'too_large' => true ] ],
			]
		);

		$this->assertSame(
			[
				'content' => [
					'changed' => true,
					'reason'  => 'too_large',
				],
			],
			$data['changes']
		);
	}
}
