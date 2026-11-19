<?php
declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCommerce\StorageBrowser\Client;
use SugarCommerce\S3\Account;
use SugarCommerce\StorageBrowser\Tests\Support\FakeHttp;

/**
 * Buckets known without listing them.
 *
 * A bucket-scoped token — Cloudflare's recommendation for R2 — cannot call
 * ListBuckets, so the browser can only show a bucket if it is told the name.
 * This list is held per client rather than in a global filter, because two
 * Browser instances on one site would otherwise overwrite each other.
 */
final class KnownBucketsTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset();
	}

	private static function client(): Client {
		return new Client( Account::r2( 'abc123' ), 'EXAMPLEKEYIDNOTREAL', 'secret', false );
	}

	private static function known( Client $client ): array {
		return ( new ReflectionProperty( Client::class, 'known_buckets' ) )->getValue( $client );
	}

	public function test_names_are_stored(): void {
		$client = self::client()->set_known_buckets( [ 'store-downloads' ] );

		$this->assertSame( [ 'store-downloads' ], self::known( $client ) );
	}

	public function test_blank_and_duplicate_names_are_discarded(): void {
		$client = self::client()->set_known_buckets( [ 'a', '', 'a', 'b', '   ' => '   ' ] );

		$this->assertSame( [ 'a', 'b' ], self::known( $client ) );
	}

	public function test_defaults_to_empty(): void {
		$this->assertSame( [], self::known( self::client() ) );
	}

	/**
	 * Two clients must not share the list, which is exactly what a global
	 * filter would have caused.
	 */
	public function test_lists_are_per_instance(): void {
		$edd = self::client()->set_known_buckets( [ 'edd-downloads' ] );
		$woo = self::client()->set_known_buckets( [ 'woo-downloads' ] );

		$this->assertSame( [ 'edd-downloads' ], self::known( $edd ) );
		$this->assertSame( [ 'woo-downloads' ], self::known( $woo ) );
	}

	/**
	 * Listing refused, the known bucket is confirmed and shown -- and only
	 * one that answers: a name that does not exist is not listed.
	 */
	public function test_a_refused_listing_falls_back_to_the_buckets_that_answer(): void {
		FakeHttp::respond_with(
			static function ( string $url ): array {
				if ( str_ends_with( $url, 'cloudflarestorage.com/' ) ) {
					return FakeHttp::response( 403, '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>' );
				}

				return str_contains( $url, '/real?' )
					? FakeHttp::response( 200, '<ListBucketResult><IsTruncated>false</IsTruncated></ListBucketResult>' )
					: FakeHttp::response( 404, '<Error><Code>NoSuchBucket</Code><Message>The specified bucket does not exist</Message></Error>' );
			}
		);

		$result = self::client()->set_known_buckets( [ 'real', 'typo' ] )->get_buckets( false );

		$this->assertSame(
			[
				'buckets' => [
					[
						'name'    => 'real',
						'created' => '',
					],
				],
				'scoped'  => true,
			],
			$result
		);
	}

	public function test_a_scoped_token_with_nothing_known_says_so(): void {
		FakeHttp::queue( 403, '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>' );

		$result = self::client()->get_buckets( false );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bucket_listing_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}
}
