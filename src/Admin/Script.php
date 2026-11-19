<?php
/**
 * Built scripts
 *
 * @package     SugarCommerce\StorageBrowser\Admin
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Admin;

use SugarCommerce\Plugin\Assets as AssetFiles;

/**
 * The scripts @wordpress/scripts built into assets/build, registered the way
 * the build says.
 *
 * Each entry is `assets/build/{entry}.js` with an `{entry}.asset.php` beside
 * it naming the WordPress scripts it imports (`wp-api-fetch`, `wp-i18n`, …)
 * and a version that changes when its contents do. Reading that file, rather
 * than writing the dependencies out again here, is what keeps the two from
 * drifting: an import added to a module is a dependency on the next build.
 *
 * The build is committed, because Composer and Strauss copy a package as it
 * is, with no install step to run one.
 */
final class Script {

	/**
	 * What the build says an entry needs.
	 *
	 * @param string $entry The entry's name: browser, picker, connection, host, edd, wc.
	 *
	 * @return array{dependencies: string[], version: string|bool}|null Null when the entry was never built.
	 */
	public static function manifest( string $entry ): ?array {
		$path = AssetFiles::path( __FILE__, 'build/' . sanitize_key( $entry ) . '.asset.php' );

		if ( null === $path ) {
			return null;
		}

		$asset = require $path;

		if ( ! is_array( $asset ) ) {
			return null;
		}

		return [
			'dependencies' => array_values( array_map( 'strval', (array) ( $asset['dependencies'] ?? [] ) ) ),
			'version'      => isset( $asset['version'] ) ? (string) $asset['version'] : false,
		];
	}

	/**
	 * Register and enqueue a built entry.
	 *
	 * @param string   $handle The handle.
	 * @param string   $entry  The entry's name.
	 * @param string[] $deps   Handles it needs that it does not import: the configuration script, media-views.
	 *
	 * @return bool Whether it was enqueued.
	 */
	public static function enqueue( string $handle, string $entry, array $deps = [] ): bool {
		$manifest = self::manifest( $entry );

		if ( null === $manifest ) {
			return false;
		}

		$enqueued = AssetFiles::enqueue_script(
			$handle,
			__FILE__,
			'build/' . sanitize_key( $entry ) . '.js',
			array_values( array_unique( array_merge( $manifest['dependencies'], $deps ) ) ),
			$manifest['version']
		);

		// Strings written with __() in the scripts are translated from the
		// plugin's language files, under this text domain.
		if ( $enqueued && in_array( 'wp-i18n', $manifest['dependencies'], true ) ) {
			wp_set_script_translations( $handle, 'sugarcommerce' );
		}

		return $enqueued;
	}
}
