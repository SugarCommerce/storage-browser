<?php
declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\StorageBrowser\Client;
use SugarCommerce\S3\Account;
use SugarCommerce\StorageBrowser\Tests\Support\FakeHttp;

/**
 * A listed page of a bucket goes through `s3_get_objects_response`.
 *
 * The old `arraypress_s3_get_objects_response`. WooCommerce refuses a download
 * outside an approved directory, so wc-cloudflare-r2 approves a bucket at the
 * moment the store browses it -- which is this filter.
 */
final class ObjectsResponseTest extends TestCase {

	private const PAGE = '<ListBucketResult><IsTruncated>false</IsTruncated>'
		. '<Contents><Key>packs/a.zip</Key><Size>5</Size><LastModified>2026-01-01T00:00:00.000Z</LastModified></Contents>'
		. '</ListBucketResult>';

	protected function setUp(): void {
		wp_test_reset();
		FakeHttp::reset();
	}

	protected function tearDown(): void {
		FakeHttp::reset();
	}

	private static function client( ?string $context = null ): Client {
		return new Client( Account::r2( 'abc123' ), 'EXAMPLEKEYIDNOTREAL', 'secret', false, DAY_IN_SECONDS, false, $context );
	}

	public function test_a_listed_page_is_filtered_with_the_bucket_prefix_and_context(): void {
		FakeHttp::queue( 200, self::PAGE );
		FakeHttp::queue( 200, self::PAGE );

		$seen = [];

		add_filter(
			'sugarcommerce_storage_browser_s3_get_objects_response',
			static function ( $page, ...$args ) use ( &$seen ) {
				$seen[] = $args;

				return $page;
			}
		);
		add_filter(
			'sugarcommerce_storage_browser_s3_get_objects_response_woo',
			static function ( $page ) {
				$page['seen_by_context'] = true;

				return $page;
			}
		);

		$plain = self::client()->get_objects( 'store-files', 50, 'packs/' );
		$woo   = self::client( 'woo' )->get_objects( 'store-files', 50, 'packs/' );

		$this->assertIsArray( $plain );
		$this->assertCount( 1, $plain['objects'] );
		$this->assertArrayNotHasKey( 'seen_by_context', $plain );
		$this->assertTrue( $woo['seen_by_context'] ?? false, 'The context filter did not run.' );
		$this->assertSame( [ [ 'store-files', 'packs/', 50, null ], [ 'store-files', 'packs/', 50, 'woo' ] ], $seen );
	}

	public function test_a_refused_listing_is_not_filtered(): void {
		FakeHttp::queue( 403, '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>' );

		$called = false;

		add_filter(
			'sugarcommerce_storage_browser_s3_get_objects_response',
			static function ( $page ) use ( &$called ) {
				$called = true;

				return $page;
			}
		);

		$this->assertInstanceOf( \WP_Error::class, self::client()->get_objects( 'store-files' ) );
		$this->assertFalse( $called );
	}

	public function test_a_filter_returning_nonsense_leaves_the_page(): void {
		FakeHttp::queue( 200, self::PAGE );

		add_filter( 'sugarcommerce_storage_browser_s3_get_objects_response', static fn() => 'nonsense' );

		$this->assertCount( 1, self::client()->get_objects( 'store-files' )['objects'] );
	}
}
