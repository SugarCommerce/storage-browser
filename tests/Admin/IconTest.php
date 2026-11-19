<?php
declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tests\Admin;

use SugarCommerce\StorageBrowser\Admin\Icon;
use SugarCommerce\StorageBrowser\Models\S3Object;
use SugarCommerce\StorageBrowser\Tables\Buckets;
use SugarCommerce\StorageBrowser\Tables\Objects;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The browser's icons.
 *
 * Heroicons, as inline SVG from the package's own assets, each carrying the
 * `arraypress-icon` class the stylesheet sizes them by.
 */
final class IconTest extends TestCase {

	/**
	 * The tables extend core's list table; the bootstrap stubs it.
	 */
	public static function setUpBeforeClass(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
	}

	private const HEROICONS = [
		'bucket'   => 'circle-stack',
		'folder'   => 'folder',
		'image'    => 'photo',
		'video'    => 'film',
		'audio'    => 'musical-note',
		'document' => 'document-text',
		'archive'  => 'archive-box',
		'file'     => 'document',
		'close'    => 'x-mark',
		'upload'   => 'arrow-up-tray',
		'cancel'   => 'x-mark',
		'done'     => 'check',
		'failed'   => 'exclamation-circle',
		'search'   => 'magnifying-glass',
		'chevron'  => 'chevron-right',
	];

	/**
	 * Each icon is the Heroicon it names -- so a misspelt name, or a file
	 * missing from assets/icons, fails here rather than drawing nothing.
	 */
	public function test_every_icon_is_its_heroicon(): void {
		foreach ( self::HEROICONS as $name => $heroicon ) {
			$file = dirname( __DIR__, 2 ) . '/assets/icons/heroicons/' . $heroicon . '.svg';

			$this->assertFileExists( $file, $heroicon );

			$path = [];
			preg_match( '/ d="([^"]+)"/', (string) file_get_contents( $file ), $path );

			$drawn = Icon::render( $name, 'x' );

			$this->assertStringContainsString( $path[1], $drawn, $name );
			$this->assertMatchesRegularExpression( '/^<svg class="arraypress-icon x" width="20" height="20" aria-hidden="true" focusable="false"[^>]*viewBox="0 0 24 24"/', $drawn, $name );
		}
	}

	/**
	 * The class is escaped: it reaches an attribute.
	 */
	public function test_the_class_is_escaped(): void {
		$this->assertStringContainsString( 'class="arraypress-icon a&quot;b"', Icon::render( 'file', 'a"b' ) );
	}

	public function test_an_unknown_name_is_the_plain_file(): void {
		$this->assertSame( Icon::render( 'file' ), Icon::render( 'nothing-of-the-sort' ) );
	}

	/**
	 * A file's icon is its category's, with the classes the colours hang on.
	 */
	public function test_a_file_icon_follows_its_category(): void {
		foreach ( [ 'image', 'video', 'audio', 'document', 'archive' ] as $category ) {
			$this->assertSame( Icon::render( $category, "s3-file-icon s3-file-icon--{$category}" ), Icon::file( $category ), $category );
		}

		$this->assertSame( Icon::render( 'file', 's3-file-icon s3-file-icon--file' ), Icon::file( 'other' ) );

		// A category that happens to be another icon's name is still a file.
		$this->assertSame( Icon::file( 'other' ), Icon::file( 'bucket' ) );
	}

	/**
	 * The scripts get their icons already drawn.
	 */
	public function test_the_scripts_get_drawn_icons(): void {
		$icons = Icon::for_scripts();

		$this->assertSame( [ 'bucket', 'folder', 'close', 'cancel', 'done', 'failed' ], array_keys( $icons ) );
		$this->assertSame( Icon::render( 'bucket', 's3-folder-icon' ), $icons['bucket'] );
		$this->assertSame( Icon::render( 'folder', 's3-folder-icon' ), $icons['folder'] );
		$this->assertSame( Icon::render( 'close' ), $icons['close'] );
		$this->assertSame( Icon::render( 'cancel' ), $icons['cancel'] );
		$this->assertSame( Icon::render( 'done' ), $icons['done'] );
		$this->assertSame( Icon::render( 'failed' ), $icons['failed'] );
	}

	/**
	 * The picker gets its controls and one icon per kind of file.
	 */
	public function test_the_picker_gets_its_icons(): void {
		$icons = Icon::for_picker();

		foreach ( [ 'bucket', 'folder', 'close', 'search', 'chevron', 'image', 'video', 'audio', 'document', 'archive', 'file' ] as $name ) {
			$this->assertStringStartsWith( '<svg class="arraypress-icon', $icons[ $name ] ?? '', $name );
		}
	}

	/**
	 * No script builds a Dashicons span of its own: each draws an icon PHP
	 * drew, through the one helper that reads them.
	 */
	public function test_no_script_draws_a_dashicon(): void {
		$files = array_merge(
			glob( dirname( __DIR__, 2 ) . '/src/js/*.js' ) ?: [],
			glob( dirname( __DIR__, 2 ) . '/src/js/*/*.js' ) ?: []
		);

		$this->assertNotEmpty( $files );

		foreach ( $files as $file ) {
			$this->assertStringNotContainsString( 'dashicons-', (string) file_get_contents( $file ), basename( $file ) );
		}

		$this->assertStringContainsString( 'browserConfig().icons', (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/js/lib/dom.js' ) );
	}

	public function test_a_bucket_row_draws_the_bucket_icon(): void {
		$table = ( new ReflectionClass( Buckets::class ) )->newInstanceWithoutConstructor();
		$this->set( $table, 'provider_id', 'r2' );

		$html = $table->column_name( [ 'name' => 'shop' ] );

		$this->assertStringStartsWith( Icon::render( 'bucket' ) . ' <a href="#" class="bucket-name"', $html );
	}

	public function test_object_rows_draw_folder_and_file_icons(): void {
		$table = ( new ReflectionClass( Objects::class ) )->newInstanceWithoutConstructor();
		$this->set( $table, 'bucket', 'shop' );
		$this->set( $table, 'prefix', '' );
		$this->set( $table, 'provider_id', 'r2' );

		// A stub for the object: only its category matters here, and the
		// real one's data attributes need more of WordPress than the suite has.
		$object = $this->createStub( S3Object::class );
		$object->method( 'get_category' )->willReturn( 'image' );
		$object->method( 'get_data_attributes' )->willReturn( '' );

		$folder = $table->column_name( [ 'type' => 'folder', 'prefix' => 'stems/', 'name' => 'stems' ] );
		$file   = $table->column_name( [
			'type'   => 'file',
			'name'   => 'cover.jpg',
			'key'    => 'cover.jpg',
			'object' => $object,
		] );

		$this->assertStringStartsWith( Icon::render( 'folder', 's3-folder-icon' ) . ' <a href="#" class="s3-folder-link"', $folder );
		$this->assertStringStartsWith( Icon::file( 'image' ) . ' <span class="s3-filename"', $file );
	}

	private function set( object $object, string $property, mixed $value ): void {
		( new \ReflectionProperty( $object, $property ) )->setValue( $object, $value );
	}
}
