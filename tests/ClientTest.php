<?php
declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\StorageBrowser\Client;
use SugarCommerce\S3\Account;
use SugarCommerce\StorageBrowser\Tests\Support\FakeHttp;
use WP_Error;

/**
 * The browser's client: the parts the REST routes lean on that a single
 * request does not show -- paging, falling back, caching, error shapes.
 */
final class ClientTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset();
	}

	private static function client( bool $cache = false ): Client {
		return new Client( Account::r2( 'abc123' ), 'EXAMPLEKEYIDNOTREAL', 'secret', $cache );
	}

	private static function page( array $keys, string $next = '' ): array {
		$contents = implode( '', array_map( static fn( string $key ): string => '<Contents><Key>' . $key . '</Key><Size>1</Size></Contents>', $keys ) );
		$more     = '' === $next ? 'false' : 'true';

		return FakeHttp::response( 200, '<ListBucketResult><IsTruncated>' . $more . '</IsTruncated><NextContinuationToken>' . $next . '</NextContinuationToken>' . $contents . '</ListBucketResult>' );
	}

	/**
	 * @return string[]
	 */
	private static function methods(): array {
		return array_map( static fn( array $request ): string => strtoupper( (string) $request['args']['method'] ), FakeHttp::requests() );
	}

	/* --- delete_folder ---------------------------------------------------- */

	/**
	 * Every page of the folder, not just the first thousand keys.
	 */
	public function test_a_folder_is_emptied_across_every_page(): void {
		FakeHttp::respond_with(
			static function ( string $url, array $args ): array {
				if ( 'POST' === $args['method'] ) {
					preg_match_all( '#<Key>([^<]+)</Key>#', $args['body'], $keys );

					return FakeHttp::response( 200, '<DeleteResult>' . implode( '', array_map( static fn( $key ) => '<Deleted><Key>' . $key . '</Key></Deleted>', $keys[1] ) ) . '</DeleteResult>' );
				}

				return str_contains( $url, 'continuation-token=p2' ) ? self::page( [ 'f/c.txt' ] ) : self::page( [ 'f/a.txt', 'f/b.txt' ], 'p2' );
			}
		);

		$result = self::client()->delete_folder( 'shop', 'f' );

		$this->assertSame( 'f/', $result['folder_path'] );
		$this->assertSame( 4, $result['success_count'], 'Three files and the folder marker.' );
		$this->assertSame( [ 'GET', 'GET', 'POST' ], self::methods() );
		$this->assertStringContainsString( '<Key>f/</Key>', FakeHttp::last()['args']['body'] );
	}

	/**
	 * A provider without DeleteObjects is asked one key at a time.
	 */
	public function test_a_refused_batch_falls_back_to_one_key_at_a_time(): void {
		FakeHttp::respond_with(
			static function ( string $url, array $args ): array {
				return match ( $args['method'] ) {
					'POST'   => FakeHttp::response( 501, '<Error><Code>NotImplemented</Code></Error>' ),
					'DELETE' => str_contains( $url, 'locked' ) ? FakeHttp::response( 403, '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>' ) : FakeHttp::response( 204 ),
					default  => self::page( [ 'f/a.txt', 'f/locked.txt' ] ),
				};
			}
		);

		$result = self::client()->delete_folder( 'shop', 'f/' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'folder_batch_delete_partial_failure', $result->get_error_code() );
		$this->assertSame( 2, $result->get_error_data()['success_count'] );
		$this->assertSame(
			[
				[
					'key'     => 'f/locked.txt',
					'code'    => 'AccessDenied',
					'message' => 'Access Denied',
				],
			],
			$result->get_error_data()['failed_objects']
		);
		$this->assertSame( [ 'GET', 'POST', 'DELETE', 'DELETE', 'DELETE' ], self::methods() );
	}

	public function test_a_folder_that_cannot_be_listed_is_left_alone(): void {
		FakeHttp::queue( 403, '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>' );

		$result = self::client()->delete_folder( 'shop', 'f' );

		$this->assertSame( 'AccessDenied', $result->get_error_code() );
		$this->assertSame( [ 'GET' ], self::methods() );
	}

	/* --- rename ----------------------------------------------------------- */

	public function test_a_rename_whose_delete_fails_says_both_copies_remain(): void {
		FakeHttp::queue( 200, '<CopyObjectResult/>' );
		FakeHttp::queue( 403, '<Error><Code>AccessDenied</Code></Error>' );

		$result = self::client()->rename_object( 'shop', 'a.zip', 'b.zip' );

		$this->assertSame( 'b.zip', $result['target_key'] );
		$this->assertArrayHasKey( 'warning', $result );
	}

	/* --- object_exists ---------------------------------------------------- */

	public function test_an_object_is_there_not_there_or_unknown(): void {
		FakeHttp::queue( 200, '', [ 'content-length' => '12', 'content-type' => 'audio/wav' ] );
		$this->assertSame(
			[
				'exists'    => true,
				'size'      => 12,
				'mime_type' => 'audio/wav',
			],
			self::client()->object_exists( 'shop', 'a.wav' )
		);

		FakeHttp::queue( 404 );
		$this->assertFalse( self::client()->object_exists( 'shop', 'a.wav' )['exists'] );

		FakeHttp::queue( 403 );
		$this->assertInstanceOf( WP_Error::class, self::client()->object_exists( 'shop', 'a.wav' ) );
	}

	/* --- caching ---------------------------------------------------------- */

	public function test_a_listing_is_cached_until_the_bucket_is_flushed(): void {
		FakeHttp::respond_with( static fn(): array => self::page( [ 'a.txt' ] ) );

		$client = self::client( true );

		$this->assertSame( 'a.txt', $client->get_objects( 'shop' )['objects'][0]->get_key() );
		$client->get_objects( 'shop' );
		$this->assertCount( 1, FakeHttp::requests() );

		$client->cache()->flush_bucket( 'shop' );
		$client->get_objects( 'shop' );
		$this->assertCount( 2, FakeHttp::requests() );
	}

	/**
	 * Asking for a fresh answer neither reads the cache nor fills it.
	 */
	public function test_a_fresh_listing_is_not_kept(): void {
		FakeHttp::respond_with( static fn(): array => self::page( [ 'a.txt' ] ) );

		$client = self::client( true );

		$client->get_objects( 'shop', 1000, '', '/', '', false );
		$client->get_objects( 'shop' );

		$this->assertCount( 2, FakeHttp::requests() );
	}

	public function test_a_failure_is_never_cached(): void {
		FakeHttp::queue( 500 );
		FakeHttp::queue( 200, '<ListBucketResult/>' );

		$client = self::client( true );

		$this->assertInstanceOf( WP_Error::class, $client->get_objects( 'shop' ) );
		$this->assertSame( [], $client->get_objects( 'shop' )['objects'] );
	}

	/* --- error shapes ----------------------------------------------------- */

	/**
	 * A REST route relays these as they are, so the status has to be one the
	 * browser means the same thing by.
	 */
	public function test_storages_status_is_kept_only_where_it_means_the_same(): void {
		FakeHttp::queue( 403, '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>' );
		$this->assertSame( 403, self::client()->get_objects( 'shop' )->get_error_data()['status'] );

		FakeHttp::queue( 500, '<Error><Code>InternalError</Code><Message>Oops</Message></Error>' );
		$error = self::client()->get_objects( 'shop' );
		$this->assertSame( 502, $error->get_error_data()['status'] );
		$this->assertSame( 'InternalError', $error->get_error_code() );
		$this->assertSame( 'Oops', $error->get_error_message() );
	}

	public function test_no_answer_is_explained_and_the_raw_reason_kept(): void {
		FakeHttp::queue_error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		$error = self::client()->get_objects( 'shop' );

		$this->assertSame( 'http_request_failed', $error->get_error_code() );
		$this->assertStringContainsString( 'did not respond', $error->get_error_message() );
		$this->assertSame( 'cURL error 28: Operation timed out', $error->get_error_data()['transport_error'] );
	}

	/**
	 * A delete, a copy or a write that got no answer comes back from S3 core
	 * as status 0 rather than the HTTP API's error; it is still no answer.
	 */
	public function test_a_write_that_got_no_answer_is_no_answer(): void {
		FakeHttp::queue_error( 'http_request_failed', 'cURL error 6: Could not resolve host' );

		$this->assertSame( 'http_request_failed', self::client()->delete_object( 'shop', 'a.zip' )->get_error_code() );
	}

	public function test_bucket_options_are_names_keyed_by_themselves(): void {
		FakeHttp::queue( 200, (string) file_get_contents( __DIR__ . '/fixtures/list-buckets.xml' ) );

		$this->assertSame(
			[
				'test-bucket'      => 'test-bucket',
				'customer-backups' => 'customer-backups',
			],
			self::client()->get_bucket_options( false )
		);

		FakeHttp::queue( 403 );
		$this->assertSame( [], self::client()->get_bucket_options( false ) );
	}

	public function test_a_fallback_link_when_none_can_be_made(): void {
		$this->assertSame( 'nope', self::client()->get_presigned_url_or_fallback( 'shop', '../a.zip', 5, 'nope' ) );
		$this->assertStringStartsWith( 'https://abc123.r2.cloudflarestorage.com/shop/a.zip?', self::client()->get_presigned_url_or_fallback( 'shop', 'a.zip' ) );
		$this->assertSame( '', self::client()->get_presigned_upload_url_or_fallback( 'shop', 'a.php' ) );
	}
}
