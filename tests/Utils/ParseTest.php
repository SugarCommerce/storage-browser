<?php
declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tests\Utils;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\StorageBrowser\Utils\Parse;

/**
 * Reading a path somebody typed into a download field or a CSV.
 */
final class ParseTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: array|false}>
	 */
	public static function paths(): array {
		return [
			'stored address'        => [ 's3://shop/packs/a.zip', [ 'bucket' => 'shop', 'object' => 'packs/a.zip' ] ],
			'no scheme'             => [ 'shop/packs/a.zip', [ 'bucket' => 'shop', 'object' => 'packs/a.zip' ] ],
			'surrounding slashes'   => [ ' /shop/packs/ ', [ 'bucket' => 'shop', 'object' => 'packs' ] ],
			'slash after scheme'    => [ 's3:///shop/a.zip', [ 'bucket' => 'shop', 'object' => 'a.zip' ] ],
			'doubled slash'         => [ 'shop//a.zip', [ 'bucket' => 'shop', 'object' => 'a.zip' ] ],
			'bucket only'           => [ 'shop', false ],
			'bucket and slash'      => [ 's3://shop/', false ],
			'empty'                 => [ '   ', false ],
			'a URL is not storage'  => [ 'https://example.com/a.zip', false ],
			'a shortcode is theirs' => [ '[download id="5"]/a.zip', false ],
		];
	}

	#[DataProvider( 'paths' )]
	public function test_a_typed_path( string $path, array|false $expected ): void {
		$this->assertSame( $expected, Parse::path( $path ) );
	}

	public function test_a_file_has_an_extension(): void {
		$this->assertTrue( Parse::is_file( 's3://shop/packs/a.zip' ) );
		$this->assertTrue( Parse::is_file( 'shop/a.tar.gz' ) );
		$this->assertFalse( Parse::is_file( 'shop/packs/' ) );
		$this->assertFalse( Parse::is_file( 'shop/README' ) );
		$this->assertFalse( Parse::is_file( 'https://example.com/a.zip' ) );
	}
}
