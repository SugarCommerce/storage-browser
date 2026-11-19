<?php
/**
 * Runtime names
 *
 * @package     SugarCommerce\StorageBrowser
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Utils;

use SugarCommerce\Plugin\Build;

/**
 * Every runtime string this package registers, derived from its own namespace.
 *
 * Strauss rewrites class namespaces and leaves string literals alone. Two
 * plugins each bundling a prefixed copy of this package would otherwise
 * register identical hooks, script handles and transient keys -- so one
 * plugin's cached answer about its own protected folder would be read as the
 * other's, and a filter one of them added would steer both.
 *
 * The derivation uses the one thing Strauss does rewrite: this file's
 * namespace. In a prefixed build `__NAMESPACE__` begins with the consumer's
 * prefix ("MyPlugin\SugarCommerce\StorageBrowser\Utils"), unique per plugin by
 * construction, so every name comes out distinct with no configuration.
 *
 * Unprefixed, a hook is `sugarcommerce_storage_browser_{name}`; in a build prefixed
 * `MyPlugin\` it is `myplugin_sugarcommerce_storage_browser_{name}`.
 */
final class Runtime {

	/**
	 * This package's own identifier, used when running unprefixed.
	 */
	private const LIBRARY = 'sugarcommerce_storage_browser';

	/**
	 * How many segments this class's own namespace has --
	 * SugarCommerce\StorageBrowser\Utils -- before any prefix. Counted rather than
	 * written out: Strauss rewrites a namespace it finds in a string, and
	 * would prefix the very thing being compared against.
	 */
	private const OWN_SEGMENTS = 3;

	/**
	 * The per-build prefix.
	 *
	 * @return string
	 */
	public static function prefix(): string {
		return self::prefix_for( __NAMESPACE__ );
	}

	/**
	 * The name of this build, as a hyphenated slug; empty unprefixed.
	 *
	 * For names that are not hooks or keys -- the REST namespace, the cache
	 * prefix -- but must still be this build's own.
	 *
	 * @return string
	 */
	public static function build(): string {
		return self::build_for( __NAMESPACE__ );
	}

	/**
	 * The prefix a given namespace would produce.
	 *
	 * Split out so the rule can be tested: prefix() reads __NAMESPACE__,
	 * which cannot change at runtime, so a test reaches the prefixed case by
	 * asking the rule directly.
	 *
	 * @param string $under The namespace this class is compiled under, prefixed or not.
	 *
	 * @return string
	 */
	public static function prefix_for( string $under ): string {
		$build = self::build_for( $under );

		return '' === $build ? self::LIBRARY : str_replace( '-', '_', $build ) . '_' . self::LIBRARY;
	}

	/**
	 * The name of the build a namespace belongs to, as a hyphenated slug.
	 *
	 * Empty when the namespace is this package's own, unprefixed. Otherwise
	 * what the build put around it, in one of two shapes:
	 *
	 * - A prefix in front of the whole namespace,
	 *   "MyShop\SugarCommerce\…\Utils": the prefix's first segment, as it
	 *   always was ("SugarCart\Vendor" is "sugarcart"), or all of it when it
	 *   starts "SugarCommerce" too -- a family plugin's
	 *   "SugarCommerce\Playground\Vendor" would otherwise be taken for the
	 *   library itself.
	 * - A plugin's own namespace with the vendor segment dropped,
	 *   "ArrayPress\EDD\FraudFilter\Lib\…\Utils": every segment before "Lib".
	 *   The first alone is the vendor every such plugin shares, and two of
	 *   them would answer each other's routes and load each other's scripts.
	 *
	 * @param string $under The namespace this class is compiled under, prefixed or not.
	 *
	 * @return string
	 */
	public static function build_for( string $under ): string {
		return Build::name( $under, self::OWN_SEGMENTS );
	}

	/**
	 * A hook name for this build.
	 *
	 * @param string $name What the hook is for.
	 *
	 * @return string
	 */
	public static function hook( string $name ): string {
		return self::prefix() . '_' . $name;
	}

	/**
	 * An option or transient key for this build.
	 *
	 * @param string $suffix Optional key suffix.
	 *
	 * @return string
	 */
	public static function key( string $suffix = '' ): string {
		return '' === $suffix ? self::prefix() : self::prefix() . '_' . $suffix;
	}

	/**
	 * A script or style handle for this build.
	 *
	 * Hyphenated, as handles are.
	 *
	 * @param string $suffix Optional handle suffix.
	 *
	 * @return string
	 */
	public static function handle( string $suffix = '' ): string {
		$base = str_replace( '_', '-', self::prefix() );

		return '' === $suffix ? $base : $base . '-' . $suffix;
	}
}
