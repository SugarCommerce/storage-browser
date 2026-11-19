<?php
declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tests\Admin;

use SugarCommerce\StorageBrowser\Tables\Buckets;
use SugarCommerce\StorageBrowser\Tables\Objects;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * "2 days ago" under a bucket's or a file's date.
 *
 * Translated whole, as one string with a placeholder: a language that puts
 * the word first ("il y a 2 jours") cannot be built by appending " ago".
 */
final class RelativeDateTest extends TestCase {

	/**
	 * The tables extend core's list table; the bootstrap stubs it.
	 */
	public static function setUpBeforeClass(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
	}

	protected function setUp(): void {
		$GLOBALS['storage_test_translations'] = [ 'sugarcommerce' => [ '%s ago' => 'il y a %s' ] ];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['storage_test_translations'] );
	}

	public function test_a_buckets_age_is_translated_whole(): void {
		$table = ( new ReflectionClass( Buckets::class ) )->newInstanceWithoutConstructor();
		$item  = [
			'created' => '17 Nov 2026',
			'raw'     => '2026-11-16T10:00:00Z',
		];

		$this->assertSame( '17 Nov 2026<br><small class="description">il y a 2 days</small>', $table->column_created( $item ) );
	}

	public function test_a_files_age_is_translated_whole(): void {
		$table = ( new ReflectionClass( Objects::class ) )->newInstanceWithoutConstructor();
		$item  = [
			'type'     => 'file',
			'modified' => '17 Nov 2026',
			'object'   => new class() {
				public function get_last_modified(): string {
					return '2026-11-16T10:00:00Z';
				}
			},
		];

		$this->assertSame( '17 Nov 2026<br><small class="description">il y a 2 days</small>', $table->column_modified( $item ) );
	}
}
