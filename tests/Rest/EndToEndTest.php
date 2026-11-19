<?php
/**
 * EndToEndTest
 *
 * @package SugarCommerce\StorageBrowser
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tests\Rest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCommerce\StorageBrowser\Client;
use SugarCommerce\StorageBrowser\Rest\Controller;
use SugarCommerce\S3\Account;
use SugarCommerce\StorageBrowser\Tests\Support\FakeHttp;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Every REST handler the browser's scripts call, driven through the client and
 * S3 core down to a fake transport, with what went over the wire and what came
 * back both checked.
 *
 * The scripts read these payloads, so a field renamed or a status changed is a
 * broken browser -- in this package and in every plugin that bundles it.
 */
final class EndToEndTest extends TestCase {

	private const HOST = 'https://account123.r2.cloudflarestorage.com';

	/**
	 * Canned answers: method, URL pattern, status, body, headers. First match wins.
	 *
	 * @var array<int, array{0: string, 1: string, 2: int, 3: string, 4?: array}>
	 */
	private array $routes = array();

	protected function setUp(): void {
		wp_test_reset();
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		( new ReflectionProperty( Controller::class, 'claimed_routes' ) )->setValue( null, array() );

		FakeHttp::respond_with(
			function ( string $url, array $args ): array|WP_Error {
				$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );

				foreach ( $this->routes as $route ) {
					if ( ( '*' === $route[0] || $route[0] === $method ) && 1 === preg_match( $route[1], $url ) ) {
						if ( 'ERROR' === $route[3] ) {
							return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
						}

						return FakeHttp::response( $route[2], $route[3], $route[4] ?? array() );
					}
				}

				return FakeHttp::response( 200, '' );
			}
		);
	}

	private function on( string $method, string $pattern, int $status, string $body = '', array $headers = array() ): void {
		$this->routes[] = array( $method, $pattern, $status, $body, $headers );
	}

	private static function fixture( string $name ): string {
		return (string) file_get_contents( __DIR__ . '/../fixtures/' . $name );
	}

	private function controller(): Controller {
		return new Controller(
			new Client( Account::r2( 'account123' ), 'EXAMPLEKEYIDNOTREAL', 'secret', false ),
			'cloudflare-r2',
			'upload_files',
			'cloudflare-r2',
			static fn(): array => array()
		);
	}

	private static function request( array $params ): WP_REST_Request {
		$request = new WP_REST_Request();

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/**
	 * A handler's answer as status and payload, either way it came back.
	 *
	 * @param mixed $result A WP_REST_Response or a WP_Error.
	 *
	 * @return array{status: int, data: mixed, code?: string, message?: string}
	 */
	private static function answer( mixed $result ): array {
		if ( $result instanceof WP_Error ) {
			$data = (array) $result->get_error_data();

			return array(
				'status'  => (int) ( $data['status'] ?? 0 ),
				'code'    => (string) $result->get_error_code(),
				'message' => $result->get_error_message(),
				'data'    => $data,
			);
		}

		self::assertInstanceOf( WP_REST_Response::class, $result );

		return array(
			'status' => (int) $result->get_status(),
			'data'   => $result->get_data(),
		);
	}

	/**
	 * What went over the wire: method and URL without the signature.
	 *
	 * @return string[]
	 */
	private static function wire(): array {
		return array_map(
			static function ( array $request ): string {
				$url = (string) preg_replace( '/[?&]X-Amz-[^&]+/', '', $request['url'] );

				$path = str_replace( self::HOST, '', $url );

				return strtoupper( (string) ( $request['args']['method'] ?? 'GET' ) ) . ' ' . ( '' === $path ? '/' : $path );
			},
			FakeHttp::requests()
		);
	}

	/* --- Connection ------------------------------------------------------- */

	public function test_connection_lists_the_buckets(): void {
		$this->on( 'GET', '#cloudflarestorage\.com/?$#', 200, self::fixture( 'list-buckets.xml' ) );

		$answer = self::answer( $this->controller()->rest_connection_test() );

		$this->assertSame( 200, $answer['status'] );
		$this->assertSame( 'ok', $answer['data']['status'] );
		$this->assertSame( array( 'test-bucket', 'customer-backups' ), $answer['data']['buckets'] );
		$this->assertSame( 2, $answer['data']['count'] );
		$this->assertSame( 'Found 2 accessible buckets', $answer['data']['summary'] );
		$this->assertSame( array( 'GET /' ), self::wire() );
	}

	public function test_a_scoped_token_needs_a_bucket(): void {
		$this->on( 'GET', '#cloudflarestorage\.com/?$#', 403, self::fixture( 'error-access-denied-r2.xml' ) );

		$answer = self::answer( $this->controller()->rest_connection_test() );

		$this->assertSame( 'needs_bucket', $answer['data']['status'] );
		$this->assertTrue( $answer['data']['scoped'] );
	}

	public function test_a_scoped_token_is_verified_against_the_fallback_bucket(): void {
		add_filter( 'sugarcommerce_storage_browser_s3_connection_test_fallback_bucket', static fn(): string => 'test-bucket' );
		$this->on( 'GET', '#cloudflarestorage\.com/?$#', 403, self::fixture( 'error-access-denied-r2.xml' ) );
		$this->on( 'GET', '#/test-bucket\?#', 200, self::fixture( 'list-objects-empty.xml' ) );

		$answer = self::answer( $this->controller()->rest_connection_test() );

		$this->assertSame( 'ok', $answer['data']['status'] );
		$this->assertSame( array( 'test-bucket' ), $answer['data']['buckets'] );
		$this->assertTrue( $answer['data']['scoped'] );
	}

	public function test_an_unreachable_fallback_bucket_is_named(): void {
		add_filter( 'sugarcommerce_storage_browser_s3_connection_test_fallback_bucket', static fn(): string => 'nope' );
		$this->on( 'GET', '#cloudflarestorage\.com/?$#', 403, self::fixture( 'error-access-denied-r2.xml' ) );
		$this->on( 'GET', '#/nope\?#', 403, self::fixture( 'error-access-denied-r2.xml' ) );

		$answer = self::answer( $this->controller()->rest_connection_test() );

		$this->assertSame( 'failed', $answer['data']['status'] );
		$this->assertStringContainsString( '"nope" could not be reached', $answer['data']['summary'] );
	}

	public function test_an_unreachable_provider_is_not_blamed_on_the_credentials(): void {
		$this->on( 'GET', '#cloudflarestorage\.com/?$#', 0, 'ERROR' );

		$answer = self::answer( $this->controller()->rest_connection_test() );

		$this->assertSame( 'failed', $answer['data']['status'] );
		$this->assertSame( 'Could not reach the storage provider.', $answer['data']['message'] );
		$this->assertStringContainsString( 'There is no server at account123.r2.cloudflarestorage.com', $answer['data']['summary'] );
	}

	public function test_wrong_credentials_fail_with_the_providers_words(): void {
		$this->on( 'GET', '#cloudflarestorage\.com/?$#', 400, self::fixture( 'error-signature.xml' ) );

		$answer = self::answer( $this->controller()->rest_connection_test() );

		$this->assertSame( 'failed', $answer['data']['status'] );
		$this->assertSame( 'Connection failed.', $answer['data']['message'] );
		$this->assertStringContainsString( 'signature', strtolower( $answer['data']['summary'] ) );
	}

	/* --- Listing ---------------------------------------------------------- */

	public function test_a_page_of_objects(): void {
		$this->on( 'GET', '#/test-bucket\?#', 200, self::fixture( 'list-objects.xml' ) );

		$answer = self::answer(
			$this->controller()->rest_list_objects(
				self::request(
					array(
						'bucket'             => 'test-bucket',
						'prefix'             => 'testing/',
						'continuation_token' => '',
					)
				)
			)
		);

		$this->assertSame( 200, $answer['status'] );
		$this->assertSame( 3, $answer['data']['count'] );
		$this->assertFalse( $answer['data']['has_more'] );
		$this->assertSame( '', $answer['data']['continuation_token'] );
		$this->assertSame( array( 'folder', 'file', 'file' ), array_column( $answer['data']['items'], 'type' ) );
		$this->assertSame( array( 'stems', 'Phonk - Demo Mix.mp3', 'Déjà vu.wav' ), array_column( $answer['data']['items'], 'name' ) );
		$this->assertSame( 'testing/stems/', $answer['data']['items'][0]['prefix'] );
		$this->assertSame( 'testing/Déjà vu.wav', $answer['data']['items'][2]['key'] );
		$this->assertSame( '7 MB', $answer['data']['items'][1]['size'] );
		$this->assertSame( 'Aug 21, 2026 2:29 PM', $answer['data']['items'][1]['modified'] );
		$this->assertSame( 'audio/mpeg', $answer['data']['items'][1]['mime'] );
		$this->assertStringContainsString( 'data-key="testing/Phonk - Demo Mix.mp3"', $answer['data']['html'] );
		$this->assertStringContainsString( 'data-is-multipart="true"', $answer['data']['html'] );
		$this->assertStringContainsString( 'data-part-count="3"', $answer['data']['html'] );
		$this->assertStringContainsString( 'data-md5="9f1c2d3e4a5b6c7d8e9f0a1b2c3d4e5f"', $answer['data']['html'] );
		$this->assertSame( array( 'GET /test-bucket?delimiter=%2F&list-type=2&max-keys=1000&prefix=testing%2F' ), self::wire() );
	}

	public function test_a_truncated_listing_hands_back_its_token(): void {
		$this->on( 'GET', '#/test-bucket\?#', 200, self::fixture( 'list-objects-truncated.xml' ) );

		$answer = self::answer(
			$this->controller()->rest_list_objects(
				self::request(
					array(
						'bucket'             => 'test-bucket',
						'prefix'             => '',
						'continuation_token' => 'abc',
					)
				)
			)
		);

		$this->assertTrue( $answer['data']['has_more'] );
		$this->assertSame( '1ueGcxLPRx1Tr/XYExHnhbYLgveDs2J/wm36Hy4vbOwM=', $answer['data']['continuation_token'] );
		// The only object is empty, and an empty file is not listed.
		$this->assertSame( 0, $answer['data']['count'] );
		$this->assertStringContainsString( 'continuation-token=abc', self::wire()[0] );
	}

	public function test_a_failed_listing_is_an_empty_page(): void {
		$this->on( 'GET', '#/test-bucket\?#', 404, self::fixture( 'error-no-such-bucket.xml' ) );

		$answer = self::answer(
			$this->controller()->rest_list_objects(
				self::request(
					array(
						'bucket'             => 'test-bucket',
						'prefix'             => '',
						'continuation_token' => '',
					)
				)
			)
		);

		$this->assertSame( 200, $answer['status'] );
		$this->assertSame( 0, $answer['data']['count'] );
	}

	public function test_folders_one_level_down(): void {
		$this->on( 'GET', '#/test-bucket\?#', 200, self::fixture( 'list-objects.xml' ) );

		$answer = self::answer(
			$this->controller()->rest_list_folders(
				self::request(
					array(
						'bucket' => 'test-bucket',
						'prefix' => 'testing/',
					)
				)
			)
		);

		$this->assertSame(
			array(
				'bucket'  => 'test-bucket',
				'prefix'  => 'testing/',
				'folders' => array(
					array(
						'name'   => 'stems',
						'prefix' => 'testing/stems/',
					),
				),
			),
			$answer['data']
		);
	}

	public function test_a_refused_folder_listing_keeps_the_providers_code(): void {
		$this->on( 'GET', '#/test-bucket\?#', 403, self::fixture( 'error-access-denied-r2.xml' ) );

		$answer = self::answer(
			$this->controller()->rest_list_folders(
				self::request(
					array(
						'bucket' => 'test-bucket',
						'prefix' => '',
					)
				)
			)
		);

		$this->assertSame( 403, $answer['status'] );
		$this->assertSame( 'AccessDenied', $answer['code'] );
		$this->assertSame( 'Access Denied', $answer['message'] );
	}

	/* --- Objects ---------------------------------------------------------- */

	public function test_an_object_is_deleted(): void {
		$this->on( 'DELETE', '#/test-bucket/a%20b\.mp3$#', 204 );

		$answer = self::answer(
			$this->controller()->rest_delete_object(
				self::request(
					array(
						'bucket' => 'test-bucket',
						'key'    => 'a b.mp3',
					)
				)
			)
		);

		$this->assertSame( 200, $answer['status'] );
		$this->assertSame( 'File deleted successfully', $answer['data']['message'] );
		$this->assertSame( array( 'DELETE /test-bucket/a%20b.mp3' ), self::wire() );
	}

	public function test_a_refused_delete_is_relayed(): void {
		$this->on( 'DELETE', '#/test-bucket/#', 403, self::fixture( 'error-access-denied-r2.xml' ) );

		$answer = self::answer(
			$this->controller()->rest_delete_object(
				self::request(
					array(
						'bucket' => 'test-bucket',
						'key'    => 'a.mp3',
					)
				)
			)
		);

		$this->assertSame( 403, $answer['status'] );
		$this->assertSame( 'AccessDenied', $answer['code'] );
	}

	public function test_an_object_is_renamed_by_copy_and_delete(): void {
		$renamed = array();
		add_action(
			'sugarcommerce_storage_browser_s3_object_renamed',
			static function ( ...$args ) use ( &$renamed ): void {
				$renamed = $args;
			}
		);

		$this->on( 'HEAD', '#/music/new\.mp3$#', 404 );
		$this->on( 'PUT', '#/music/new\.mp3$#', 200, '<CopyObjectResult><ETag>"x"</ETag></CopyObjectResult>' );
		$this->on( 'DELETE', '#/music/old\.mp3$#', 204 );

		$answer = self::answer(
			$this->controller()->rest_rename_object(
				self::request(
					array(
						'bucket'       => 'test-bucket',
						'current_key'  => 'music/old.mp3',
						'new_filename' => 'new.mp3',
					)
				)
			)
		);

		$this->assertSame( 200, $answer['status'] );
		$this->assertSame( 'music/new.mp3', $answer['data']['new_key'] );
		$this->assertSame( 'File renamed to "new.mp3" successfully', $answer['data']['message'] );
		$this->assertSame( array( 'test-bucket', 'music/old.mp3', 'music/new.mp3' ), $renamed );
		$this->assertSame(
			array( 'HEAD /test-bucket/music/new.mp3', 'PUT /test-bucket/music/new.mp3', 'DELETE /test-bucket/music/old.mp3' ),
			self::wire()
		);

		// The copy names its source, signed.
		$copy = FakeHttp::requests()[1]['args']['headers'];
		$this->assertSame( '/test-bucket/music/old.mp3', '/' . ltrim( (string) array_change_key_case( $copy )['x-amz-copy-source'], '/' ) );
		$this->assertStringContainsString( 'x-amz-copy-source', (string) array_change_key_case( $copy )['authorization'] );
	}

	public function test_a_rename_onto_an_existing_file_is_refused(): void {
		$this->on( 'HEAD', '#/music/new\.mp3$#', 200, '', array( 'content-length' => '10' ) );

		$answer = self::answer(
			$this->controller()->rest_rename_object(
				self::request(
					array(
						'bucket'       => 'test-bucket',
						'current_key'  => 'music/old.mp3',
						'new_filename' => 'new.mp3',
					)
				)
			)
		);

		$this->assertSame( 409, $answer['status'] );
		$this->assertSame( 'rest_rename_conflict', $answer['code'] );
		$this->assertSame( array( 'HEAD /test-bucket/music/new.mp3' ), self::wire() );
	}

	public function test_a_copy_refused_inside_a_200_fails_the_rename(): void {
		$this->on( 'HEAD', '#/new\.mp3$#', 404 );
		$this->on( 'PUT', '#/new\.mp3$#', 200, '<Error><Code>InternalError</Code><Message>We encountered an internal error.</Message></Error>' );

		$answer = self::answer(
			$this->controller()->rest_rename_object(
				self::request(
					array(
						'bucket'       => 'test-bucket',
						'current_key'  => 'old.mp3',
						'new_filename' => 'new.mp3',
					)
				)
			)
		);

		$this->assertNotSame( 200, $answer['status'] );
		$this->assertNotContains( 'DELETE /test-bucket/old.mp3', self::wire(), 'The original must survive a failed copy.' );
	}

	public function test_an_invalid_new_filename_is_refused_before_any_request(): void {
		$answer = self::answer(
			$this->controller()->rest_rename_object(
				self::request(
					array(
						'bucket'       => 'test-bucket',
						'current_key'  => 'old.mp3',
						'new_filename' => 'a/b.mp3',
					)
				)
			)
		);

		$this->assertSame( 'rest_invalid_filename', $answer['code'] );
		$this->assertSame( array(), self::wire() );
	}

	public function test_an_object_is_moved_into_a_folder(): void {
		$this->on( 'HEAD', '#/stems/old\.mp3$#', 404 );
		$this->on( 'PUT', '#/stems/old\.mp3$#', 200, '<CopyObjectResult/>' );
		$this->on( 'DELETE', '#/test-bucket/old\.mp3$#', 204 );

		$answer = self::answer(
			$this->controller()->rest_move_object(
				self::request(
					array(
						'bucket'        => 'test-bucket',
						'key'           => 'old.mp3',
						'target_prefix' => 'stems/',
					)
				)
			)
		);

		$this->assertSame( 200, $answer['status'] );
		$this->assertSame( 'stems/old.mp3', $answer['data']['new_key'] );
		$this->assertSame( 'File moved to "stems/"', $answer['data']['message'] );
	}

	public function test_a_download_link_for_a_file_that_exists(): void {
		$this->on( 'HEAD', '#/a\.mp3$#', 200, '', array( 'content-length' => '10' ) );

		$answer = self::answer(
			$this->controller()->rest_get_download_url(
				self::request(
					array(
						'bucket'          => 'test-bucket',
						'key'             => 'a.mp3',
						'expires_minutes' => 30,
					)
				)
			)
		);

		$this->assertSame( 200, $answer['status'] );
		$this->assertStringStartsWith( self::HOST . '/test-bucket/a.mp3?', $answer['data']['url'] );
		$this->assertStringContainsString( 'X-Amz-Expires=1800', $answer['data']['url'] );
		$this->assertSame( 30, $answer['data']['expires_in'] );
	}

	public function test_no_download_link_for_a_missing_file(): void {
		$this->on( 'HEAD', '#/a\.mp3$#', 404 );

		$answer = self::answer(
			$this->controller()->rest_get_download_url(
				self::request(
					array(
						'bucket'          => 'test-bucket',
						'key'             => 'a.mp3',
						'expires_minutes' => 30,
					)
				)
			)
		);

		$this->assertSame( 404, $answer['status'] );
		$this->assertSame( 'rest_object_not_found', $answer['code'] );
	}

	public function test_no_download_link_when_the_check_itself_fails(): void {
		$this->on( 'HEAD', '#/a\.mp3$#', 403 );

		$answer = self::answer(
			$this->controller()->rest_get_download_url(
				self::request(
					array(
						'bucket'          => 'test-bucket',
						'key'             => 'a.mp3',
						'expires_minutes' => 30,
					)
				)
			)
		);

		$this->assertSame( 502, $answer['status'] );
		$this->assertSame( 'rest_object_check_failed', $answer['code'] );
	}

	public function test_an_upload_link_for_an_allowed_type(): void {
		$answer = self::answer(
			$this->controller()->rest_get_upload_url(
				self::request(
					array(
						'bucket' => 'test-bucket',
						'key'    => 'music/a.mp3',
					)
				)
			)
		);

		$this->assertSame( 200, $answer['status'] );
		$this->assertStringStartsWith( self::HOST . '/test-bucket/music/a.mp3?', $answer['data']['url'] );
		$this->assertStringContainsString( 'X-Amz-Expires=900', $answer['data']['url'] );
		$this->assertSame( array(), self::wire(), 'Signing an upload link sends nothing.' );
	}

	public function test_no_upload_link_for_a_refused_type(): void {
		$answer = self::answer(
			$this->controller()->rest_get_upload_url(
				self::request(
					array(
						'bucket' => 'test-bucket',
						'key'    => 'evil.php',
					)
				)
			)
		);

		$this->assertSame( 400, $answer['status'] );
		$this->assertSame( 'invalid_file_type', $answer['code'] );
		$this->assertSame( 'File type ".php" is not allowed for upload', $answer['message'] );
	}

	/* --- Folders ---------------------------------------------------------- */

	public function test_a_folder_is_created_as_an_empty_marker(): void {
		$this->on( 'GET', '#/test-bucket\?#', 200, self::fixture( 'list-objects-empty.xml' ) );
		$this->on( 'PUT', '#/test-bucket/music/new%20folder/$#', 200 );

		$answer = self::answer(
			$this->controller()->rest_create_folder(
				self::request(
					array(
						'bucket'      => 'test-bucket',
						'prefix'      => 'music/',
						'folder_name' => 'new folder',
					)
				)
			)
		);

		$this->assertSame( 201, $answer['status'] );
		$this->assertSame( 'music/new folder/', $answer['data']['folder_key'] );
		$wire = self::wire();
		$this->assertSame( 'PUT /test-bucket/music/new%20folder/', end( $wire ) );
		$this->assertSame( '', (string) FakeHttp::last()['args']['body'] );
	}

	public function test_an_invalid_folder_name_is_refused(): void {
		$answer = self::answer(
			$this->controller()->rest_create_folder(
				self::request(
					array(
						'bucket'      => 'test-bucket',
						'prefix'      => '',
						'folder_name' => 'a/b',
					)
				)
			)
		);

		$this->assertSame( 'rest_invalid_folder_name', $answer['code'] );
	}

	public function test_a_folder_and_everything_in_it_is_deleted(): void {
		$this->on( 'GET', '#/test-bucket\?#', 200, self::fixture( 'list-objects.xml' ) );
		$this->on( 'POST', '#/test-bucket\?delete#', 200, '<DeleteResult><Deleted><Key>testing/Phonk - Demo Mix.mp3</Key></Deleted><Deleted><Key>testing/Déjà vu.wav</Key></Deleted><Deleted><Key>testing/</Key></Deleted></DeleteResult>' );
		$this->on( 'HEAD', '#/testing/$#', 200 );
		$this->on( 'DELETE', '#/test-bucket/#', 204 );

		$answer = self::answer(
			$this->controller()->rest_delete_folder(
				self::request(
					array(
						'bucket'      => 'test-bucket',
						'folder_path' => 'testing',
					)
				)
			)
		);

		$this->assertSame( 200, $answer['status'] );
		$this->assertSame( 'testing/', $answer['data']['folder_path'] );
		$this->assertSame( 3, $answer['data']['data']['success_count'] );
		$this->assertSame( 0, $answer['data']['data']['error_count'] );
	}

	public function test_the_bucket_root_is_never_deleted(): void {
		$answer = self::answer(
			$this->controller()->rest_delete_folder(
				self::request(
					array(
						'bucket'      => 'test-bucket',
						'folder_path' => '/',
					)
				)
			)
		);

		$this->assertSame( 'rest_refuse_bucket_root', $answer['code'] );
		$this->assertSame( array(), self::wire() );
	}

	/* --- CORS ------------------------------------------------------------- */

	public function test_cors_is_set_for_uploads_and_verified(): void {
		$this->on( 'PUT', '#/test-bucket\?cors#', 200 );
		$this->on( 'GET', '#/test-bucket\?cors#', 200, self::fixture( 'cors-configuration.xml' ) );

		$answer = self::answer(
			$this->controller()->rest_setup_cors(
				self::request(
					array(
						'bucket' => 'test-bucket',
						'origin' => '',
					)
				)
			)
		);

		$this->assertSame( 200, $answer['status'] );
		$this->assertSame( 'https://example.test', $answer['data']['origin'] );
		$this->assertTrue( $answer['data']['verification_passed'] );
		$this->assertSame( array( 'PUT /test-bucket?cors=', 'GET /test-bucket?cors=' ), self::wire() );

		$sent = (string) FakeHttp::requests()[0]['args']['body'];
		$this->assertStringContainsString( '<AllowedOrigin>https://example.test</AllowedOrigin>', $sent );
		$this->assertStringContainsString( '<AllowedMethod>PUT</AllowedMethod>', $sent );
	}

	public function test_a_token_that_cannot_change_cors_is_told_so(): void {
		$this->on( 'PUT', '#/test-bucket\?cors#', 403, self::fixture( 'error-access-denied-r2.xml' ) );

		$answer = self::answer(
			$this->controller()->rest_setup_cors(
				self::request(
					array(
						'bucket' => 'test-bucket',
						'origin' => 'https://shop.test',
					)
				)
			)
		);

		$this->assertSame( 502, $answer['status'] );
		$this->assertSame( 'rest_cors_permission_denied', $answer['code'] );
	}

	public function test_cors_is_removed(): void {
		$this->on( 'DELETE', '#/test-bucket\?cors#', 204 );

		$answer = self::answer( $this->controller()->rest_delete_cors( self::request( array( 'bucket' => 'test-bucket' ) ) ) );

		$this->assertSame( 200, $answer['status'] );
		$this->assertSame( 'CORS configuration removed', $answer['data']['message'] );
		$this->assertSame( array( 'DELETE /test-bucket?cors=' ), self::wire() );
	}

	/* --- Bucket details --------------------------------------------------- */

	public function test_bucket_details(): void {
		$this->on( 'GET', '#/test-bucket\?cors#', 200, self::fixture( 'cors-configuration.xml' ) );
		$this->on( 'GET', '#/test-bucket\?location#', 200, '<LocationConstraint>WNAM</LocationConstraint>' );
		$this->on( 'GET', '#cloudflarestorage\.com/?$#', 200, self::fixture( 'list-buckets.xml' ) );
		$this->on( 'GET', '#/test-bucket\?#', 200, self::fixture( 'list-objects-empty.xml' ) );
		$this->on( 'PUT', '#/test-bucket/permissions-test-#', 200 );
		$this->on( 'DELETE', '#/test-bucket/permissions-test-#', 204 );

		$answer = self::answer( $this->controller()->rest_get_bucket_details( self::request( array( 'bucket' => 'test-bucket' ) ) ) );
		$data   = $answer['data'];

		$this->assertSame( 200, $answer['status'] );
		$this->assertSame( 'test-bucket', $data['bucket'] );
		$this->assertSame( 'WNAM', $data['basic']['region'] );
		$this->assertSame( '2025-12-24 10:07:59', $data['basic']['created'] );
		$this->assertTrue( $data['cors']['upload_ready'] );
		$this->assertSame( 'https://example.test', $data['cors']['current_origin'] );
		$this->assertSame( 1, $data['cors']['analysis']['rules_count'] );
		$this->assertSame(
			array(
				'read'   => true,
				'write'  => true,
				'delete' => true,
			),
			$data['permissions']
		);
		$this->assertArrayNotHasKey( 'debug', $data, 'Debug output is for WP_DEBUG only.' );
	}

	public function test_the_cache_route_flushes(): void {
		$answer = self::answer( $this->controller()->rest_clear_cache() );

		$this->assertSame( 'Cache cleared successfully', $answer['data']['message'] );
	}
}
