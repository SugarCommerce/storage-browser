<?php
declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tests\Admin;

use SugarCommerce\StorageBrowser\Admin\Translations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The translation table.
 *
 * The browser's JavaScript reads these as i18n.<group>.<key> with no fallback,
 * so a missing key renders the literal word "undefined" in the interface. PHP
 * gives no warning for that -- the mismatch only shows up on screen.
 */
final class TranslationsTest extends TestCase {

	/**
	 * Every translation key the shipped JavaScript reads.
	 *
	 * Two forms appear. Most references are fully qualified --
	 * s3BrowserConfig.i18n.buckets.detailsTitle -- but a function that uses
	 * one group repeatedly aliases it first:
	 *
	 *     var i18n = s3BrowserConfig.i18n.buckets;
	 *     i18n.corsSetupSuccess.replace( ... )
	 *
	 * so a bare i18n.<key> has to be resolved against the alias in scope, or
	 * the key reads as a group name and the group name is lost.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function keys_used_by_javascript(): array {
		$refs  = [];
		$files = array_merge(
			glob( dirname( __DIR__, 2 ) . '/assets/js/*.js' ) ?: [],
			glob( dirname( __DIR__, 2 ) . '/assets/js/*/*.js' ) ?: []
		);

		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file );

			// Every filterable string is read through config.js's t( group, key ).
			preg_match_all( "/\\bt\\(\\s*'(\\w+)',\\s*'(\\w+)'\\s*\\)/", $source, $found, PREG_SET_ORDER );

			foreach ( $found as [ , $group, $key ] ) {
				$refs[ "{$group}.{$key}" ] = [ $group, $key ];
			}

			// A local helper bound to one group: s( 'key' ) in buckets.js.
			if ( preg_match( "/const s = \\( key \\) => t\\( '(\\w+)', key \\)/", $source, $bound ) ) {
				preg_match_all( "/\\bs\\(\\s*'(\\w+)'\\s*\\)/", $source, $keys );

				foreach ( $keys[1] as $key ) {
					$refs[ "{$bound[1]}.{$key}" ] = [ $bound[1], $key ];
				}
			}
		}

		ksort( $refs );

		return $refs;
	}

	/**
	 * The scan found what it should: a scan that matches nothing passes every
	 * check it makes.
	 */
	public function test_the_scan_finds_the_strings(): void {
		$keys = self::keys_used_by_javascript();

		$this->assertGreaterThan( 60, count( $keys ) );
		$this->assertArrayHasKey( 'upload.cancelUploadConfirm', $keys );
		$this->assertArrayHasKey( 'buckets.corsWhereR2', $keys );
	}

	#[DataProvider( 'keys_used_by_javascript' )]
	public function test_key_the_javascript_reads_is_defined( string $group, string $key ): void {
		$strings = Translations::all();

		$this->assertArrayHasKey( $group, $strings, "JavaScript reads i18n.{$group} but no such group exists" );
		$this->assertArrayHasKey( $key, $strings[ $group ], "JavaScript reads i18n.{$group}.{$key} but it is not defined" );
		$this->assertNotSame( '', $strings[ $group ][ $key ] );
	}

	public function test_every_string_is_a_string(): void {
		foreach ( Translations::all() as $group => $strings ) {
			$this->assertIsArray( $strings, "Group {$group} is not an array" );

			foreach ( $strings as $key => $value ) {
				$this->assertIsString( $value, "{$group}.{$key} is not a string" );
			}
		}
	}

	/**
	 * The table is sent to the browser as JSON. A key that is not valid UTF-8
	 * takes the whole payload down with it, not just its own entry.
	 */
	public function test_table_survives_json_encoding(): void {
		$this->assertIsString( json_encode( Translations::all(), JSON_THROW_ON_ERROR ) );
	}
}
