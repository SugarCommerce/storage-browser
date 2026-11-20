<?php
declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tests\Admin;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use SugarCommerce\StorageBrowser\Admin\Config;
use SugarCommerce\StorageBrowser\Admin\MediaLibrary;

/**
 * The media modal's tab, which core reaches through media-upload.php.
 *
 * Core lets anybody with upload_files open any tab by name, so the tab has to
 * ask the browser's own capability and allow-list itself -- the REST routes'
 * checks never run for a listing drawn on the server.
 */
final class MediaTabTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset();
		$_GET = [];
	}

	protected function tearDown(): void {
		$_GET                     = [];
		$GLOBALS['test_user_can'] = true;
	}

	private function library( array $allowed, string $default = '' ): MediaLibrary {
		$library = ( new ReflectionClass( MediaLibrary::class ) )->newInstanceWithoutConstructor();
		( new ReflectionProperty( $library, 'config' ) )->setValue( $library, new Config( 'r2', 'R2', 'manage_options', $default, [], 'shop' ) );
		( new ReflectionProperty( $library, 'allowed_buckets' ) )->setValue( $library, static fn(): array => $allowed );

		return $library;
	}

	private function requested( MediaLibrary $library ): array {
		return ( new ReflectionMethod( $library, 'requested' ) )->invoke( $library );
	}

	public function test_a_bucket_outside_the_allow_list_is_not_opened(): void {
		$_GET = [ 'bucket' => 'payroll-exports', 'prefix' => 'secret/' ];

		$this->assertSame( [ 'buckets', '', '' ], $this->requested( $this->library( [ 'shop-files' ] ) ) );
	}

	public function test_an_allowed_bucket_is_opened(): void {
		$_GET = [ 'bucket' => 'shop-files', 'prefix' => 'packs/' ];

		$this->assertSame( [ 'objects', 'shop-files', 'packs/' ], $this->requested( $this->library( [ 'shop-files' ] ) ) );
	}

	public function test_no_allow_list_opens_any_well_formed_bucket(): void {
		$_GET = [ 'bucket' => 'any-bucket' ];
		$this->assertSame( [ 'objects', 'any-bucket', '' ], $this->requested( $this->library( [] ) ) );

		$_GET = [ 'bucket' => 'x' ];
		$this->assertSame( [ 'buckets', '', '' ], $this->requested( $this->library( [] ) ) );
	}

	public function test_the_default_bucket_opens_with_no_query(): void {
		$this->assertSame( [ 'objects', 'shop-files', '' ], $this->requested( $this->library( [], 'shop-files' ) ) );
	}

	/**
	 * media-upload.php checks upload_files; the browser may need more.
	 */
	public function test_the_tab_refuses_a_user_without_the_browsers_capability(): void {
		$GLOBALS['test_user_can'] = [ 'upload_files' ];

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );

		$this->library( [] )->handle_media_tab();
	}
}
