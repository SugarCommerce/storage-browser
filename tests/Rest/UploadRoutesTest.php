<?php
/**
 * The large-upload routes and the full connection check.
 *
 * @package SugarCommerce\StorageBrowser
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tests\Rest;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCommerce\StorageBrowser\Browser;
use SugarCommerce\StorageBrowser\Client;
use SugarCommerce\StorageBrowser\Rest\Controller;
use SugarCommerce\S3\Account;
use SugarCommerce\S3\Bucket;
use SugarCommerce\S3\Cors\Policy;
use SugarCommerce\S3\Multipart;
use SugarCommerce\StorageBrowser\Tests\Support\FakeHttp;
use SugarCommerce\StorageBrowser\Tests\Support\FakeS3;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * What the upload script calls, driven through the client and Multipart down
 * to FakeS3, and what a browser's own PUTs leave there.
 */
final class UploadRoutesTest extends TestCase {

	/**
	 * One mebibyte.
	 */
	private const MB = 1048576;

	/**
	 * The bucket in memory.
	 *
	 * @var FakeS3
	 */
	private FakeS3 $s3;

	/**
	 * A fresh bucket and no routes claimed.
	 */
	protected function setUp(): void {
		wp_test_reset();
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		( new ReflectionProperty( Controller::class, 'claimed_routes' ) )->setValue( null, [] );

		$this->s3 = FakeS3::install( 'files' );
	}

	/**
	 * Clear the responder.
	 */
	protected function tearDown(): void {
		FakeHttp::reset();
	}

	/**
	 * Every upload route is registered, and none without the bucket check.
	 */
	public function test_the_routes_are_registered_behind_the_bucket_check(): void {
		$this->controller()->register_rest_routes();

		$routes = [];

		foreach ( $GLOBALS['registered_rest_routes'] as $route ) {
			foreach ( $route['args'] as $handler ) {
				if ( is_array( $handler ) && isset( $handler['methods'] ) ) {
					$routes[ $handler['methods'] . ' ' . $route['route'] ] = $handler['permission_callback'][1] ?? '';
				}
			}
		}

		foreach ( [
			'POST /r2/buckets/(?P<bucket>[^/]+)/uploads',
			'GET /r2/buckets/(?P<bucket>[^/]+)/uploads/(?P<id>[a-f0-9]{32})',
			'DELETE /r2/buckets/(?P<bucket>[^/]+)/uploads/(?P<id>[a-f0-9]{32})',
			'POST /r2/buckets/(?P<bucket>[^/]+)/uploads/(?P<id>[a-f0-9]{32})/parts',
			'POST /r2/buckets/(?P<bucket>[^/]+)/uploads/(?P<id>[a-f0-9]{32})/complete',
			'GET /r2/connection/check',
		] as $route ) {
			$this->assertSame( 'rest_bucket_permission_check', $routes[ $route ] ?? null, $route );
		}
	}

	/**
	 * A browser's whole upload: start, sign, PUT, complete.
	 */
	public function test_a_whole_upload(): void {
		$bytes = str_repeat( 'a', 16 * self::MB ) . str_repeat( 'b', 16 * self::MB ) . 'tail';
		$start = $this->answer( $this->controller()->rest_start_upload( $this->request( [
			'key'         => 'packs/Library.zip',
			'size'        => strlen( $bytes ),
			'mime_type'   => 'application/zip',
			'fingerprint' => 'Library.zip|33554436|1700000000',
		] ) ) );

		$this->assertSame( 201, $start['status'] );
		$this->assertSame( Multipart::PART_SIZE, $start['data']['part_size'] );
		$this->assertSame( 3, $start['data']['part_count'] );
		$this->assertSame( [], $start['data']['done'] );
		$this->assertFalse( $start['data']['resumed'] );

		$id    = $start['data']['id'];
		$signs = $this->answer( $this->controller()->rest_sign_upload_parts( $this->request( [ 'id' => $id, 'parts' => [ 1, 2, 3, 4 ] ] ) ) );
		$urls  = (array) $signs['data']['urls'];

		$this->assertSame( [ 1, 2, 3 ], array_keys( $urls ) );

		foreach ( $urls as $number => $url ) {
			$this->browser_put( $url, substr( $bytes, ( $number - 1 ) * Multipart::PART_SIZE, Multipart::PART_SIZE ) );
		}

		$done = $this->answer( $this->controller()->rest_complete_upload( $this->request( [ 'id' => $id ] ) ) );

		$this->assertSame( 200, $done['status'] );
		$this->assertSame( 's3://files/packs/Library.zip', $done['data']['address'] );
		$this->assertSame( $bytes, $this->s3->objects['packs/Library.zip'] );
		$this->assertSame( 'application/zip', $this->s3->types['packs/Library.zip'] );
	}

	/**
	 * The same file dropped again picks up where storage says it stopped.
	 */
	public function test_the_same_file_resumes(): void {
		$args  = [ 'key' => 'v.mp4', 'size' => 40 * self::MB, 'fingerprint' => 'v.mp4|41943040|1' ];
		$start = $this->answer( $this->controller()->rest_start_upload( $this->request( $args ) ) );
		$url   = (array) $this->answer( $this->controller()->rest_sign_upload_parts( $this->request( [ 'id' => $start['data']['id'], 'parts' => [ 2 ] ] ) ) )['data']['urls'];

		$this->browser_put( $url[2], str_repeat( 'x', Multipart::PART_SIZE ) );

		$again = $this->answer( $this->controller()->rest_start_upload( $this->request( $args ) ) );

		$this->assertSame( 200, $again['status'] );
		$this->assertTrue( $again['data']['resumed'] );
		$this->assertSame( $start['data']['id'], $again['data']['id'] );
		$this->assertSame( [ 2 ], $again['data']['done'] );
		$this->assertCount( 1, $this->s3->uploads );

		// Another file of the same name and size is a new upload.
		$other = $this->answer( $this->controller()->rest_start_upload( $this->request( [ 'fingerprint' => 'v.mp4|41943040|2' ] + $args ) ) );

		$this->assertSame( 201, $other['status'] );
		$this->assertNotSame( $start['data']['id'], $other['data']['id'] );
	}

	/**
	 * A remembered upload storage dropped is started again, not resumed.
	 */
	public function test_a_dropped_upload_starts_again(): void {
		$args  = [ 'key' => 'v.mp4', 'size' => 40 * self::MB, 'fingerprint' => 'f' ];
		$start = $this->answer( $this->controller()->rest_start_upload( $this->request( $args ) ) );

		$this->s3->uploads = [];

		$again = $this->answer( $this->controller()->rest_start_upload( $this->request( $args ) ) );

		$this->assertSame( 201, $again['status'] );
		$this->assertFalse( $again['data']['resumed'] );
		$this->assertNotSame( $start['data']['id'], $again['data']['id'] );
	}

	/**
	 * Completing early says which parts are missing, with a 409.
	 */
	public function test_completing_early_names_the_missing_parts(): void {
		$start = $this->answer( $this->controller()->rest_start_upload( $this->request( [ 'key' => 'a.bin', 'size' => 40 * self::MB ] ) ) );
		$done  = $this->answer( $this->controller()->rest_complete_upload( $this->request( [ 'id' => $start['data']['id'] ] ) ) );

		$this->assertSame( 409, $done['status'] );
		$this->assertSame( 's3_upload_incomplete', $done['code'] );
		$this->assertSame( [ 1, 2, 3 ], $done['data']['missing'] );
	}

	/**
	 * Status reads storage's list of parts.
	 */
	public function test_status_reads_storage(): void {
		$start = $this->answer( $this->controller()->rest_start_upload( $this->request( [ 'key' => 'a.bin', 'size' => 40 * self::MB ] ) ) );
		$url   = (array) $this->answer( $this->controller()->rest_sign_upload_parts( $this->request( [ 'id' => $start['data']['id'], 'parts' => [ 1 ] ] ) ) )['data']['urls'];

		$this->browser_put( $url[1], str_repeat( 'x', Multipart::PART_SIZE ) );

		$status = $this->answer( $this->controller()->rest_upload_status( $this->request( [ 'id' => $start['data']['id'] ] ) ) );

		$this->assertSame( [ 1 ], $status['data']['done'] );
		$this->assertSame( Multipart::PART_SIZE, $status['data']['progress']['bytes'] );
	}

	/**
	 * Cancelling frees the parts, and the upload is then unknown.
	 */
	public function test_cancel(): void {
		$start = $this->answer( $this->controller()->rest_start_upload( $this->request( [ 'key' => 'a.bin', 'size' => 40 * self::MB ] ) ) );
		$abort = $this->answer( $this->controller()->rest_abort_upload( $this->request( [ 'id' => $start['data']['id'] ] ) ) );

		$this->assertTrue( $abort['data']['aborted'] );
		$this->assertSame( [], $this->s3->uploads );
		$this->assertSame( 404, $this->answer( $this->controller()->rest_upload_status( $this->request( [ 'id' => $start['data']['id'] ] ) ) )['status'] );
	}

	/**
	 * Somebody else's upload cannot be signed, finished or cancelled.
	 */
	public function test_another_user_s_upload_is_refused(): void {
		$start = $this->answer( $this->controller()->rest_start_upload( $this->request( [ 'key' => 'a.bin', 'size' => 40 * self::MB ] ) ) );

		$GLOBALS['test_user_id'] = 2;

		foreach ( [ 'rest_sign_upload_parts', 'rest_complete_upload', 'rest_abort_upload', 'rest_upload_status' ] as $handler ) {
			$answer = $this->answer( $this->controller()->$handler( $this->request( [ 'id' => $start['data']['id'], 'parts' => [ 1 ] ] ) ) );

			$this->assertSame( 'rest_upload_forbidden', $answer['code'], $handler );
		}

		$this->assertCount( 1, $this->s3->uploads );
	}

	/**
	 * A refusal from storage is a 502 in storage's words.
	 */
	public function test_a_storage_refusal_is_a_502(): void {
		$this->s3->fail( static fn( $method, $key, $query ) => isset( $query['uploads'] ), FakeS3::error( 403, 'AccessDenied', 'Access Denied' ) );

		$answer = $this->answer( $this->controller()->rest_start_upload( $this->request( [ 'key' => 'a.bin', 'size' => 40 * self::MB ] ) ) );

		$this->assertSame( 502, $answer['status'] );
		$this->assertSame( 'Storage refused: Access Denied', $answer['message'] );
		$this->assertSame( 'AccessDenied', $answer['data']['error_code'] );
	}

	/**
	 * A file larger than storage keeps is refused with a 400.
	 */
	public function test_a_file_too_large_is_a_400(): void {
		$answer = $this->answer( $this->controller()->rest_start_upload( $this->request( [ 'key' => 'a.bin', 'size' => Bucket::MAX_OBJECT_SIZE + 1 ] ) ) );

		$this->assertSame( 400, $answer['status'] );
		$this->assertSame( 's3_upload_size', $answer['code'] );
	}

	/**
	 * The full check runs every step for the bucket, with this site's origin.
	 */
	public function test_the_full_connection_check(): void {
		( new Bucket( $this->client()->signer(), 'files' ) )->set_cors( Policy::for_browser_uploads( [ 'https://example.test' ] ) );

		$answer = $this->answer( $this->controller()->rest_connection_check( $this->request( [] ) ) );

		$this->assertSame( 'ok', $answer['data']['status'] );
		$this->assertCount( 6, $answer['data']['checks'] );
		$this->assertTrue( $answer['data']['checks'][5]['ok'] );
		$this->assertSame( [], $answer['data']['notes'] );
	}

	/**
	 * A failed step is the summary.
	 */
	public function test_a_failed_check_is_summed_up(): void {
		$this->s3->fail( static fn( $method ) => 'PUT' === $method, FakeS3::error( 403, 'AccessDenied', 'Access Denied' ) );

		$answer = $this->answer( $this->controller()->rest_connection_check( $this->request( [] ) ) );

		$this->assertSame( 'failed', $answer['data']['status'] );
		$this->assertStringContainsString( 'may not write to', $answer['data']['summary'] );
	}

	/**
	 * The connection test names the bucket the full check should try.
	 */
	public function test_the_connection_test_names_the_bucket_to_check(): void {
		FakeHttp::respond_with( static fn() => FakeHttp::response( 200, '<ListAllMyBucketsResult><Buckets><Bucket><Name>files</Name></Bucket></Buckets></ListAllMyBucketsResult>' ) );

		$this->assertSame( 'files', $this->answer( $this->controller()->rest_connection_test() )['data']['check_bucket'] );
	}

	/**
	 * A browser clears its own bucket's abandoned uploads, once a day.
	 */
	public function test_the_browser_prunes_daily(): void {
		$browser = new Browser( Account::r2( 'account123' ), 'key', 'secret', [], 'files', 'upload_files', 'shop' );

		$browser->schedule_prune();
		$browser->schedule_prune();

		$this->assertSame( 'daily', $GLOBALS['wp_test_cron'][ $browser->get_prune_hook() ]['recurrence'] );
		$this->assertSame( Browser::prune_hook( 'r2_shop' ), $browser->get_prune_hook() );
		$this->assertStringContainsString( 'prune_uploads_r2_shop', $browser->get_prune_hook() );

		$this->s3->now = time() - 8 * 86400;
		( new Multipart( new Bucket( $this->client()->signer(), 'files' ) ) )->begin( 'old.bin', 40 * self::MB );

		$this->assertSame( 1, $browser->prune_uploads()['files']['aborted'] );
		$this->assertSame( [], $this->s3->uploads );

		$browser->unschedule_prune();

		$this->assertArrayNotHasKey( $browser->get_prune_hook(), $GLOBALS['wp_test_cron'] );
	}

	/* ─── Helpers ───────────────────────────────────────────────────── */

	/**
	 * A client on fake keys.
	 *
	 * @return Client
	 */
	private function client(): Client {
		return new Client( Account::r2( 'account123' ), 'EXAMPLEKEYIDNOTREAL', 'secret', false );
	}

	/**
	 * A controller for the bucket `files`.
	 *
	 * @return Controller
	 */
	private function controller(): Controller {
		return new Controller( $this->client(), 'r2', 'upload_files', 'r2', static fn(): array => [ 'files' ], 'files' );
	}

	/**
	 * A request for the bucket `files`.
	 *
	 * @param array $params Parameters.
	 *
	 * @return WP_REST_Request
	 */
	private function request( array $params ): WP_REST_Request {
		$request = new WP_REST_Request();

		foreach ( $params + [ 'bucket' => 'files', 'mime_type' => 'application/octet-stream', 'fingerprint' => '' ] as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/**
	 * PUT a part as the browser does: to its URL, with its Content-MD5.
	 *
	 * @param string $url   Presigned URL.
	 * @param string $bytes The part.
	 *
	 * @return void
	 */
	private function browser_put( string $url, string $bytes ): void {
		$answer = wp_remote_request( $url, [
			'method'  => 'PUT',
			'headers' => [ 'Content-MD5' => base64_encode( md5( $bytes, true ) ) ],
			'body'    => $bytes,
		] );

		$this->assertSame( 200, $answer['response']['code'] );
	}

	/**
	 * A handler's answer as status and payload, either way it came back.
	 *
	 * @param mixed $result A WP_REST_Response or a WP_Error.
	 *
	 * @return array{status: int, data: mixed, code?: string, message?: string}
	 */
	private function answer( mixed $result ): array {
		if ( $result instanceof WP_Error ) {
			$data = (array) $result->get_error_data();

			return [
				'status'  => (int) ( $data['status'] ?? 0 ),
				'code'    => (string) $result->get_error_code(),
				'message' => $result->get_error_message(),
				'data'    => $data,
			];
		}

		$this->assertInstanceOf( WP_REST_Response::class, $result );

		return [
			'status' => (int) $result->get_status(),
			'data'   => $result->get_data(),
		];
	}
}
