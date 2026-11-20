<?php
/**
 * Two plugins, two copies of this library, one page.
 *
 * Every name a copy puts on the page must be its build's own: a fixed global
 * or a fixed "already started" flag lets the first copy to load answer for
 * every copy -- one plugin's older script driving another's browser with
 * its own REST route and nonce, or never starting the second browser at all.
 *
 * @package SugarCommerce\StorageBrowser
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use SugarCommerce\Plugin\Assets as AssetFiles;
use SugarCommerce\S3\Account;
use SugarCommerce\StorageBrowser\Admin\Assets;
use SugarCommerce\StorageBrowser\Browser;
use SugarCommerce\StorageBrowser\Picker\Picker;
use SugarCommerce\StorageBrowser\Rest\Controller;
use SugarCommerce\StorageBrowser\Utils\Admin;
use SugarCommerce\StorageBrowser\Utils\Runtime;

final class CoexistenceTest extends TestCase {

	/**
	 * The fixed page globals the scripts used to share, and must not again.
	 */
	private const array RETIRED = [
		'S3BrowserInitialized',
		'S3BrowserGlobalConfig',
		's3BrowserConfig',
		'S3BrowserIcons',
		'SugarCommerceStoragePicker',
	];

	protected function setUp(): void {
		wp_test_reset();
		wp_test_reset_scripts();
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		( new ReflectionProperty( Controller::class, 'claimed_routes' ) )->setValue( null, [] );
		( new ReflectionProperty( Assets::class, 'configured' ) )->setValue( null, [] );
		AssetFiles::clear_cache();
		( new ReflectionProperty( Picker::class, 'added' ) )->setValue( null, [] );
	}

	private function browser( string $context = 'shop' ): Browser {
		return new Browser( Account::r2( 'account123' ), 'key', 'secret', [], 'files', 'upload_files', $context );
	}

	private function assets( Browser $browser ): Assets {
		return ( new ReflectionProperty( Browser::class, 'assets' ) )->getValue( $browser );
	}

	public function test_no_script_reads_or_sets_a_fixed_page_global(): void {
		$root  = dirname( __DIR__ );
		$found = [];

		foreach ( [ 'assets/js', 'assets/build', 'src' ] as $dir ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
				if ( ! in_array( $file->getExtension(), [ 'js', 'php' ], true ) ) {
					continue;
				}

				$code = (string) file_get_contents( $file->getPathname() );

				foreach ( self::RETIRED as $name ) {
					if ( str_contains( $code, $name ) ) {
						$found[] = substr( $file->getPathname(), strlen( $root ) + 1 ) . ': ' . $name;
					}
				}
			}
		}

		$this->assertSame( [], $found );
	}

	public function test_nothing_is_localized_under_a_fixed_name(): void {
		$browser     = $this->browser();
		$_GET['tab'] = $browser->get_tab_id();
		$this->assets( $browser )->enqueue_browser_assets( 'media-upload-popup' );
		$this->assets( $browser )->enqueue_settings_assets( 'nowhere' );
		$browser->picker()->enqueue();

		foreach ( $GLOBALS['wp_test_scripts']['script'] as $handle => $script ) {
			$this->assertSame( [], $script['localized'], "{$handle} was given a page global of its own." );
		}
	}

	public function test_each_script_is_configured_under_its_own_handle(): void {
		$browser     = $this->browser();
		$_GET['tab'] = $browser->get_tab_id();
		$this->assets( $browser )->enqueue_browser_assets( 'media-upload-popup' );

		$handle = Runtime::handle( 'browser' );
		$before = array_column( $GLOBALS['wp_test_scripts']['script'][ $handle ]['inline'], 1 );
		$key    = 'window.SugarCommerceStorageBrowser[' . wp_json_encode( $handle ) . ']';

		$this->assertSame( $handle, Assets::browser_handle() );
		$this->assertStringContainsString( $key . '["global"]=', $before[0] );
		$this->assertStringContainsString( $key . '["browser"]=', $before[1] );
	}

	public function test_the_browser_it_draws_carries_its_build(): void {
		$browser     = $this->browser();
		$_GET['tab'] = $browser->get_tab_id();
		$media       = ( new ReflectionProperty( Browser::class, 'media' ) )->getValue( $browser );

		ob_start();
		$media->render_tab_content();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-storage-browser="' . Runtime::handle( 'browser' ) . '"', $html );
	}

	public function test_the_connection_button_carries_its_build(): void {
		ob_start();
		Admin::render_connection_test( 'my-test-connection', 'my-test-result' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-storage-browser="' . Runtime::handle( 'connection' ) . '"', $html );
	}

	/**
	 * The token an insert request carries is checked by the page's own
	 * copy of the script. Under a fixed nonce action every copy would hold
	 * the same token, and each would act on the other's request.
	 */
	public function test_the_insert_token_is_the_builds(): void {
		$config = $this->assets( $this->browser() )->global_config();

		$this->assertSame( wp_create_nonce( Runtime::key( 'insert' ) ), $config['insertToken'] );
		$this->assertNotSame( wp_create_nonce( 's3_browser_insert' ), $config['insertToken'] );
		$this->assertSame( $this->browser()->get_tab_id(), $config['tabId'] );
	}

	public function test_the_picker_is_the_builds_in_every_name(): void {
		$picker = $this->browser()->picker();

		$this->assertSame( Runtime::handle( 'picker' ), Picker::handle() );
		$this->assertSame( Runtime::key( 'r2_shop' ), $picker->id() );
		$this->assertStringContainsString( 'data-storage-picker="' . Picker::handle() . '"', $picker->button( 'file-url' ) );
	}
}
