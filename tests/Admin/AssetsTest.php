<?php
/**
 * What the browser loads, and where.
 *
 * @package SugarCommerce\StorageBrowser
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tests\Admin;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCommerce\Plugin\Assets as AssetFiles;
use SugarCommerce\S3\Account;
use SugarCommerce\StorageBrowser\Admin\Assets;
use SugarCommerce\StorageBrowser\Browser;
use SugarCommerce\StorageBrowser\Rest\Controller;

final class AssetsTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset();
		wp_test_reset_scripts();
		( new ReflectionProperty( Assets::class, 'configured' ) )->setValue( null, [] );
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		( new ReflectionProperty( Controller::class, 'claimed_routes' ) )->setValue( null, [] );
		AssetFiles::clear_cache();
	}

	private function assets( string $context ): object {
		$browser = new Browser( Account::r2( 'account123' ), 'key', 'secret', [], 'files', 'upload_files', $context );

		return ( new ReflectionProperty( Browser::class, 'assets' ) )->getValue( $browser );
	}

	private static function loaded( string $handle ): bool {
		return wp_script_is( $handle );
	}

	/**
	 * What was printed in front of a script.
	 *
	 * @param string $handle The script.
	 *
	 * @return string[]
	 */
	private static function inline( string $handle ): array {
		return array_column(
			array_filter( $GLOBALS['wp_test_scripts']['script'][ $handle ]['inline'], static fn( array $row ): bool => 'before' === $row[0] ),
			1
		);
	}

	public function test_the_media_frame_loads_a_browser_only_in_its_own_tab(): void {
		$shop  = $this->assets( 'shop' );
		$other = $this->assets( 'other' );

		$_GET['tab'] = 'sugarcommerce_storage_browser_tab_r2_shop';
		$shop->enqueue_browser_assets( 'media-upload-popup' );
		$other->enqueue_browser_assets( 'media-upload-popup' );

		$this->assertTrue( self::loaded( 'sugarcommerce-storage-browser-browser' ) );

		$inline = self::inline( 'sugarcommerce-storage-browser-browser' );

		$this->assertCount( 2, $inline, 'The page global and the frame\'s own, once each.' );
		$this->assertStringContainsString( '["global"]={"providerId":"r2_shop"', $inline[0] );
		$this->assertStringContainsString( '["browser"]={', $inline[1] );

		wp_test_reset_scripts();
		$_GET['tab'] = 'library';
		$shop->enqueue_browser_assets( 'media-upload-popup' );

		$this->assertFalse( self::loaded( 'sugarcommerce-storage-browser-browser' ) );
	}

	public function test_the_built_browser_needs_no_jquery(): void {
		$_GET['tab'] = 'sugarcommerce_storage_browser_tab_r2_shop';
		$this->assets( 'shop' )->enqueue_browser_assets( 'media-upload-popup' );

		$script = $GLOBALS['wp_test_scripts']['script']['sugarcommerce-storage-browser-browser'];

		$this->assertStringEndsWith( '/assets/build/browser.js', $script['src'] );
		$this->assertNotContains( 'jquery', $script['deps'] );
		$this->assertContains( 'wp-api-fetch', $script['deps'] );
		$this->assertSame( 'sugarcommerce', $script['translations'] );
	}
}
