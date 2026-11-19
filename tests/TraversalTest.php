<?php
declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\StorageBrowser\Client;
use SugarCommerce\S3\Account;
use SugarCommerce\StorageBrowser\Tests\Support\FakeHttp;
use WP_Error;

/**
 * A key with a `.` or `..` segment never reaches the wire.
 *
 * The REST routes only strip control characters from a key, because a real
 * key carries spaces, quotes and brackets; traversal is refused where the
 * key is encoded for the request. This proves the two meet: whatever the
 * operation, no request is sent for such a key.
 */
final class TraversalTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset();
	}

	/**
	 * @return array<string, array{0: string, 1: array}>
	 */
	public static function operations(): array {
		return [
			'delete'   => [ 'delete_object', [ 'shop', 'a/../../wp-config.php' ] ],
			'rename'   => [ 'rename_object', [ 'shop', '../x.zip', 'y.zip' ] ],
			'folder'   => [ 'create_folder', [ 'shop', 'a/../b/' ] ],
			'exists'   => [ 'object_exists', [ 'shop', 'a/./x.zip', false ] ],
			'upload'   => [ 'get_presigned_upload_url', [ 'shop', 'a/../x.zip' ] ],
			'download' => [ 'get_presigned_url', [ 'shop', 'a/../x.zip' ] ],
		];
	}

	#[DataProvider( 'operations' )]
	public function test_a_traversal_key_sends_nothing( string $method, array $args ): void {
		$client = new Client( Account::r2( 'abc123' ), 'key', 'secret', false );

		$result = $client->$method( ...$args );

		$this->assertInstanceOf( WP_Error::class, $result, $method );

		$this->assertSame( [], FakeHttp::requests(), $method );
	}
}
