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
use SugarCommerce\StorageBrowser\Browser;
use SugarCommerce\StorageBrowser\Rest\Controller;

final class AssetsTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset();
		wp_test_reset_scripts();
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

	public function test_the_media_frame_loads_a_browser_only_in_its_own_tab(): void {
		$shop  = $this->assets( 'shop' );
		$other = $this->assets( 'other' );

		$_GET['tab'] = 's3_r2_shop';
		$shop->enqueue_browser_assets( 'media-upload-popup' );
		$other->enqueue_browser_assets( 'media-upload-popup' );

		$this->assertTrue( self::loaded( 'sugarcommerce-storage-browser-browser' ) );
		$this->assertArrayHasKey( 's3BrowserConfig', $GLOBALS['wp_test_scripts']['script']['sugarcommerce-storage-browser-browser']['localized'] );
		$this->assertSame(
			'r2_shop',
			$GLOBALS['wp_test_scripts']['script']['sugarcommerce-storage-browser-browser-config']['localized']['S3BrowserGlobalConfig']['providerId'],
			'Another browser configured the page.'
		);

		wp_test_reset_scripts();
		$_GET['tab'] = 'library';
		$shop->enqueue_browser_assets( 'media-upload-popup' );

		$this->assertFalse( self::loaded( 'sugarcommerce-storage-browser-browser' ) );
	}

	public function test_the_built_browser_needs_no_jquery(): void {
		$_GET['tab'] = 's3_r2_shop';
		$this->assets( 'shop' )->enqueue_browser_assets( 'media-upload-popup' );

		$script = $GLOBALS['wp_test_scripts']['script']['sugarcommerce-storage-browser-browser'];

		$this->assertStringEndsWith( '/assets/build/browser.js', $script['src'] );
		$this->assertNotContains( 'jquery', $script['deps'] );
		$this->assertContains( 'wp-api-fetch', $script['deps'] );
		$this->assertContains( 'sugarcommerce-storage-browser-browser-config', $script['deps'] );
		$this->assertSame( 'sugarcommerce', $script['translations'] );
	}
}
