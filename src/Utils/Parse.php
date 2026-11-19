<?php
/**
 * Parse
 *
 * @package     SugarCommerce\StorageBrowser
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Utils;

use SugarCommerce\S3\Address;

/**
 * Class Parse
 *
 * Reading a file path somebody typed -- into a product's download field, a
 * CSV for the WP-CLI import -- as a bucket and an object.
 *
 * Looser than {@see Address::parse()}, which reads stored addresses: the
 * scheme is optional, surrounding slashes are forgiven, and a trailing slash
 * is dropped rather than kept as a folder. Stricter in two ways a typed field
 * needs: a full URL is somebody else's file, and a value carrying a shortcode
 * (`[download id=…]`) belongs to the plugin that owns the shortcode.
 */
final class Parse {

	/**
	 * A typed path as a bucket and an object.
	 *
	 * @param string $path Path to parse: `s3://bucket/key` or `bucket/key`.
	 *
	 * @return array{bucket: string, object: string}|false False when it is not one.
	 */
	public static function path( string $path ): array|false {
		$path = trim( $path );

		if ( Address::is( $path ) ) {
			$path = substr( $path, strlen( Address::SCHEME ) );
		} elseif ( false !== filter_var( $path, FILTER_VALIDATE_URL ) ) {
			// Asked only of what is not already an s3:// address, which is a
			// valid URL too: the check used to come first and refused every
			// stored address it was handed.
			return false;
		}

		if ( '' === $path || self::has_shortcode( $path ) ) {
			return false;
		}

		$parsed = Address::parse( trim( $path, '/' ), true );

		return null === $parsed ? false : [
			'bucket' => $parsed['bucket'],
			'object' => $parsed['key'],
		];
	}

	/**
	 * Whether a typed path names a file in storage, not a folder.
	 *
	 * A path that parses and whose last segment has an extension.
	 *
	 * @param string $path Path to check.
	 *
	 * @return bool
	 */
	public static function is_file( string $path ): bool {
		$parsed = self::path( $path );

		return false !== $parsed && '' !== pathinfo( basename( $parsed['object'] ), PATHINFO_EXTENSION );
	}

	/**
	 * Whether a value carries a shortcode.
	 *
	 * @param string $path The value.
	 *
	 * @return bool
	 */
	public static function has_shortcode( string $path ): bool {
		return str_contains( $path, '[' ) && str_contains( $path, ']' );
	}
}
