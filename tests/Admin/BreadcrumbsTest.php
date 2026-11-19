<?php
declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tests\Admin;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SugarCommerce\StorageBrowser\Admin\Config;
use SugarCommerce\StorageBrowser\Admin\Icon;
use SugarCommerce\StorageBrowser\Admin\MediaLibrary;

/**
 * The trail above a listing: Buckets › bucket › folders.
 *
 * Drawn in the shape the stylesheet was written against, so its classes and
 * structure are what is checked.
 */
final class BreadcrumbsTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset();
	}

	private function trail( string $bucket, string $prefix ): string {
		$library = ( new ReflectionClass( MediaLibrary::class ) )->newInstanceWithoutConstructor();
		( new ReflectionProperty( $library, 'config' ) )->setValue( $library, new Config( 'r2', 'R2', 'upload_files', $bucket, [], 'shop' ) );

		ob_start();
		( new ReflectionMethod( $library, 'display_breadcrumbs' ) )->invoke( $library, $bucket, $prefix );

		return (string) ob_get_clean();
	}

	public function test_the_trail_has_the_shape_the_stylesheet_expects(): void {
		$html = $this->trail( 'shop', 'packs/drums/' );

		$this->assertStringStartsWith( '<nav class="s3-browser-breadcrumbs" aria-label="Breadcrumb"><ol class="breadcrumb" itemscope itemtype="https://schema.org/BreadcrumbList">', $html );
		$this->assertSame( 3, substr_count( $html, '<li class="separator" aria-hidden="true">›</li>' ) );
		$this->assertSame( 4, substr_count( $html, 'itemprop="itemListElement"' ) );
		$this->assertStringContainsString( '<meta itemprop="position" content="4">', $html );
	}

	/**
	 * The last folder is where you are: not a link, marked current.
	 */
	public function test_the_current_folder_is_marked_and_not_linked(): void {
		$html = $this->trail( 'shop', 'packs/drums/' );

		$this->assertStringContainsString( '<li class=" active" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem" aria-current="page"><span itemprop="name">drums</span>', $html );
		$this->assertStringNotContainsString( 'prefix=packs%2Fdrums%2F', $html );
		$this->assertStringContainsString( 'prefix=packs%2F', $html );
	}

	public function test_the_bucket_and_root_are_links_with_icons(): void {
		$html = $this->trail( 'shop', '' );

		$this->assertStringContainsString( Icon::render( 'bucket' ) . ' <span itemprop="name">Buckets</span>', $html );
		// At the bucket's root the bucket is the current item.
		$this->assertStringContainsString( 'aria-current="page">' . Icon::render( 'folder', 's3-folder-icon' ) . ' <span itemprop="name">shop</span>', $html );
	}

	public function test_names_are_escaped(): void {
		$html = $this->trail( 'shop', '<b>x</b>/' );

		$this->assertStringContainsString( '&lt;b&gt;x&lt;', $html );
		$this->assertStringNotContainsString( '<b>x', $html );
	}
}
