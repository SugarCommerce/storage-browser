<?php
/**
 * The "Choose from your bucket" picker: what it lists, what it is told, and
 * the button and source it offers.
 *
 * @package SugarCommerce\StorageBrowser
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tests\Picker;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCommerce\Plugin\Assets as AssetFiles;
use SugarCommerce\S3\Account;
use SugarCommerce\StorageBrowser\Browser;
use SugarCommerce\StorageBrowser\Client;
use SugarCommerce\StorageBrowser\Picker\Listing;
use SugarCommerce\StorageBrowser\Picker\Picker;
use SugarCommerce\StorageBrowser\Rest\Controller;
use SugarCommerce\StorageBrowser\Tests\Support\FakeHttp;
use SugarCommerce\StorageBrowser\Tests\Support\FakeS3;
use WP_REST_Request;
use WP_REST_Response;

final class PickerTest extends TestCase {

	/**
	 * A file in the package, for the asset helper to find it by.
	 */
	private const PICKER_FILE = __DIR__ . '/../../src/Picker/Picker.php';

	private ?FakeS3 $s3 = null;

	protected function setUp(): void {
		wp_test_reset();
		wp_test_reset_scripts();
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		( new ReflectionProperty( Controller::class, 'claimed_routes' ) )->setValue( null, [] );
		AssetFiles::clear_cache();
		( new ReflectionProperty( Picker::class, 'added' ) )->setValue( null, [] );

		$this->s3 = FakeS3::install( 'files' );

		foreach ( [ 'readme.txt', 'packs/Drums One.zip', 'packs/drums/kick.wav', 'packs/keys.zip', 'packs/.DS_Store', 'covers/drum-cover.jpg' ] as $key ) {
			$this->s3->objects[ $key ] = 'bytes of ' . $key;
		}
	}

	/**
	 * PHPUnit keeps every test object to the end of the run, and a bucket of
	 * twelve thousand keys is not worth keeping.
	 */
	protected function tearDown(): void {
		$this->s3 = null;
		FakeHttp::reset();
	}

	private function client(): Client {
		return new Client( Account::r2( 'account123' ), 'EXAMPLEKEYIDNOTREAL', 'secret', false );
	}

	private function browser( array $allowed = [ 'files' ] ): Browser {
		$browser = new Browser( Account::r2( 'account123' ), 'EXAMPLEKEYIDNOTREAL', 'secret', [], 'files', 'upload_files', 'shop' );
		$browser->set_allowed_buckets( $allowed );

		return $browser;
	}

	private static function request( array $params ): WP_REST_Request {
		$request = new WP_REST_Request();

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/* --- Listing ----------------------------------------------------------- */

	public function test_a_folder_lists_its_folders_and_files_without_hidden_ones(): void {
		$page = Listing::browse( $this->client(), 'files', 'packs' );

		$this->assertSame( 'packs/', $page['prefix'] );
		$this->assertSame( [ [ 'name' => 'drums', 'prefix' => 'packs/drums/' ] ], $page['folders'] );
		$this->assertSame( [ 'Drums One.zip', 'keys.zip' ], array_column( $page['objects'], 'name' ) );
		$this->assertSame( 's3://files/packs/Drums One.zip', $page['objects'][0]['address'] );
		$this->assertSame( 'packs/Drums One.zip', $page['objects'][0]['key'] );
		$this->assertSame( strlen( 'bytes of packs/Drums One.zip' ), $page['objects'][0]['size'] );
		$this->assertSame( 'application/zip', $page['objects'][0]['mime_type'] );
		$this->assertSame( 'archive', $page['objects'][0]['category'] );
		$this->assertFalse( $page['truncated'] );
	}

	public function test_a_search_finds_names_anywhere_beneath_the_folder(): void {
		$page = Listing::browse( $this->client(), 'files', '', 'DRUM' );

		$this->assertSame( [], $page['folders'] );
		$this->assertSame( [ 'covers/drum-cover.jpg', 'packs/Drums One.zip' ], array_column( $page['objects'], 'key' ) );

		// Every word, in any order; the folder's own name does not count.
		$this->assertSame( [ 'packs/Drums One.zip' ], array_column( Listing::browse( $this->client(), 'files', 'packs/', 'one drums' )['objects'], 'key' ) );
		$this->assertSame( [], Listing::browse( $this->client(), 'files', 'packs/', 'packs' )['objects'] );
	}

	public function test_a_long_search_stops_to_answer_and_carries_on_from_its_token(): void {
		$this->s3->objects = [];

		for ( $i = 0; $i < ( Listing::SEARCH_PAGES + 2 ) * Listing::PAGE; $i++ ) {
			$this->s3->objects[ sprintf( 'k/%05d.bin', $i ) ] = 'x';
		}

		$this->s3->objects['k/99999-match.bin'] = 'x';

		$first = Listing::browse( $this->client(), 'files', 'k/', 'match' );

		$this->assertSame( [], $first['objects'] );
		$this->assertTrue( $first['truncated'] );
		$this->assertSame( (string) ( Listing::SEARCH_PAGES * Listing::PAGE ), $first['continuation_token'] );
		$this->assertCount( Listing::SEARCH_PAGES, array_filter( FakeHttp::requests(), static fn( $r ) => str_contains( $r['url'], 'list-type' ) ) );

		$second = Listing::browse( $this->client(), 'files', 'k/', 'match', $first['continuation_token'] );

		$this->assertSame( [ 'k/99999-match.bin' ], array_column( $second['objects'], 'key' ) );
		$this->assertFalse( $second['truncated'] );
	}

	public function test_a_search_stops_once_it_has_enough(): void {
		$this->s3->objects = [];

		for ( $i = 0; $i < 3 * Listing::PAGE; $i++ ) {
			$this->s3->objects[ sprintf( 'm/match-%05d.bin', $i ) ] = 'x';
		}

		$page = Listing::browse( $this->client(), 'files', '', 'match' );

		$this->assertCount( Listing::PAGE, $page['objects'] );
		$this->assertTrue( $page['truncated'] );
	}

	public function test_words_match_whole_or_not_at_all(): void {
		$this->assertTrue( Listing::matches( 'Déjà Vu.wav', 'déjà' ) );
		$this->assertTrue( Listing::matches( 'a b c', '  c   a ' ) );
		$this->assertFalse( Listing::matches( 'a b', 'a z' ) );
		$this->assertTrue( Listing::matches( 'anything', '' ) );
	}

	/* --- REST -------------------------------------------------------------- */

	public function test_the_picker_routes_are_registered(): void {
		$this->browser()->rest()->register_rest_routes();

		$routes = array_column( $GLOBALS['registered_rest_routes'], 'route' );

		$this->assertContains( '/r2_shop/buckets', $routes );
		$this->assertContains( '/r2_shop/buckets/(?P<bucket>[^/]+)/browse', $routes );
	}

	public function test_a_confined_browser_offers_only_its_buckets_without_asking_storage(): void {
		$answer = $this->browser( [ 'files', 'masters' ] )->rest()->rest_list_buckets();

		$this->assertInstanceOf( WP_REST_Response::class, $answer );
		$this->assertSame( [ [ 'name' => 'files' ], [ 'name' => 'masters' ] ], $answer->get_data()['buckets'] );
		$this->assertSame( 'files', $answer->get_data()['default'] );
		$this->assertSame( [], FakeHttp::requests() );
	}

	public function test_an_unconfined_browser_offers_what_the_key_can_list(): void {
		FakeHttp::respond_with( null );
		FakeHttp::queue_fixture( 'list-buckets.xml' );

		$answer = $this->browser( [] )->rest()->rest_list_buckets();

		$this->assertNotEmpty( $answer->get_data()['buckets'] );
		$this->assertStringContainsString( 'cloudflarestorage.com', FakeHttp::requests()[0]['url'] );
	}

	public function test_browsing_a_bucket_outside_the_allow_list_is_refused(): void {
		$rest = $this->browser( [ 'files' ] )->rest();

		$this->assertTrue( $rest->rest_bucket_permission_check( self::request( [ 'bucket' => 'files' ] ) ) );
		$this->assertSame( 'rest_bucket_forbidden', $rest->rest_bucket_permission_check( self::request( [ 'bucket' => 'other' ] ) )->get_error_code() );
	}

	public function test_browse_answers_a_page(): void {
		$answer = $this->browser()->rest()->rest_browse( self::request( [ 'bucket' => 'files', 'prefix' => 'covers/', 'search' => '', 'continuation_token' => '' ] ) );

		$this->assertSame( [ 'drum-cover.jpg' ], array_column( $answer->get_data()['objects'], 'name' ) );
	}

	/* --- The picker -------------------------------------------------------- */

	public function test_the_script_is_told_where_to_ask_and_what_it_may_open(): void {
		$config = $this->browser( [ 'files' ] )->picker()->config();

		$this->assertSame( 'Choose from Cloudflare R2', $config['label'] );
		$this->assertStringEndsWith( '/s3-browser/v1/r2_shop', $config['restUrl'] );
		$this->assertSame( 'files', $config['defaultBucket'] );
		$this->assertSame( [ [ 'name' => 'files' ] ], $config['buckets'] );
		$this->assertArrayHasKey( 'search', $config['icons'] );
		$this->assertNotSame( '', $config['nonce'] );
	}

	public function test_enqueue_loads_the_built_script_with_its_configuration_once(): void {
		$picker = $this->browser()->picker();

		$this->assertTrue( $picker->enqueue() );
		$this->assertTrue( $picker->enqueue() );

		$script = $GLOBALS['wp_test_scripts']['script'][ Picker::handle() ];

		$this->assertTrue( $script['enqueued'] );
		$this->assertStringEndsWith( '/assets/build/picker.js', $script['src'] );
		$this->assertSame( AssetFiles::built_manifest( self::PICKER_FILE, 'picker' )['dependencies'], $script['deps'] );
		$this->assertSame( 'sugarcommerce', $script['translations'] );
		$this->assertCount( 1, $script['inline'] );
		$this->assertSame( 'before', $script['inline'][0][0] );
		$this->assertStringContainsString( 'window.SugarCommerceStorageBrowser["sugarcommerce-storage-browser-picker"].pickers["sugarcommerce_storage_browser_r2_shop"]={', $script['inline'][0][1] );
		$this->assertTrue( wp_style_is( 'sugarcommerce-storage-browser-picker' ) );
	}

	public function test_nothing_loads_for_somebody_who_may_not_use_the_browser(): void {
		$GLOBALS['test_user_can'] = false;
		$picker                   = $this->browser()->picker();

		$this->assertFalse( $picker->enqueue() );
		$this->assertSame( '', $picker->button( 'file-url' ) );
		$this->assertArrayNotHasKey( Picker::handle(), $GLOBALS['wp_test_scripts']['script'] ?? [] );
	}

	public function test_a_button_names_its_input_and_escapes_what_it_is_given(): void {
		$html = $this->browser()->picker()->button( 'file-url', [
			'name'    => 'file-name',
			'accept'  => [ 'zip', 'wav' ],
			'returns' => 'nonsense',
			'label'   => 'Pick <b>one</b>',
		] );

		$this->assertStringContainsString( 'data-storage-picker="sugarcommerce-storage-browser-picker"', $html );
		$this->assertStringContainsString( 'data-storage-picker-target="#file-url"', $html );
		$this->assertStringContainsString( 'data-storage-picker-name="#file-name"', $html );
		$this->assertStringContainsString( 'data-storage-picker-accept="zip,wav"', $html );
		$this->assertStringContainsString( 'data-storage-picker-returns="address"', $html );
		$this->assertStringContainsString( 'data-storage-picker-source="sugarcommerce_storage_browser_r2_shop"', $html );
		$this->assertStringContainsString( 'Pick &lt;b&gt;one&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( 'data-storage-picker-bucket', $html );
	}

	public function test_the_source_is_plain_values_and_a_callable(): void {
		$source = $this->browser()->picker()->source();

		$this->assertSame( [ 'id', 'label', 'icon', 'enqueue', 'protocols' ], array_keys( $source ) );
		// A field that cleans its value as a URL keeps s3:// only when told.
		$this->assertSame( [ 's3' ], $source['protocols'] );
		$this->assertSame( 'sugarcommerce_storage_browser_r2_shop', $source['id'] );
		$this->assertIsCallable( $source['enqueue'] );
		$this->assertTrue( ( $source['enqueue'] )() );
	}

	public function test_an_error_with_an_unbroken_hostname_wraps_on_a_phone(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/picker.css' );

		// The error is drawn in the empty line and said in the status line.
		foreach ( [ '__empty', '__status' ] as $part ) {
			$this->assertMatchesRegularExpression(
				'/\.sc-storage-picker' . $part . '\s*\{[^}]*overflow-wrap:\s*anywhere;/',
				$css,
				$part
			);
		}
	}

	public function test_a_field_gets_one_of_three_shapes(): void {
		$this->assertSame( 'path', Picker::returns( 'path' ) );
		$this->assertSame( 'key', Picker::returns( 'key' ) );
		$this->assertSame( 'address', Picker::returns( 'url' ) );
	}

	public function test_every_built_entry_has_its_manifest(): void {
		foreach ( [ 'browser', 'picker', 'connection', 'host', 'edd', 'wc' ] as $entry ) {
			$manifest = AssetFiles::built_manifest( self::PICKER_FILE, $entry );

			$this->assertNotNull( $manifest, $entry );
			$this->assertNotContains( 'jquery', $manifest['dependencies'], $entry );
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{20}$/', (string) $manifest['version'], $entry );
		}

		$this->assertNull( AssetFiles::built_manifest( self::PICKER_FILE, 'nothing' ) );
		$this->assertNull( AssetFiles::built_manifest( self::PICKER_FILE, '../../composer' ) );
	}
}
