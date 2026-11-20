<?php
/**
 * A bucket's CORS rules: read, set for a scenario, remove, and whether a browser upload will work.
 *
 * @package   SugarCommerce\StorageBrowser
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Traits\Client;

use SugarCommerce\S3\Bucket;
use SugarCommerce\S3\Cors\Analysis;
use SugarCommerce\S3\Cors\Rules;
use WP_Error;

/**
 * Trait CorsCalls
 *
 * A bucket's CORS rules: read, set for a scenario, remove, and whether a browser upload will work.
 *
 * @since 1.0.0
 */
trait CorsCalls {

	/**
	 * A bucket's CORS rules, in the shape S3\Cors\Analysis reads.
	 *
	 * @param string $bucket    Bucket name.
	 * @param bool   $use_cache Whether a cached answer will do.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error No configuration is no rules.
	 */
	public function get_cors_rules( string $bucket, bool $use_cache = true ): array|WP_Error {
		$key    = $this->cache->key( 'cors_config', [ 'bucket' => $bucket ] );
		$cached = $use_cache ? $this->cache->get( $key ) : false;

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$cors = $this->attempt( fn() => $this->bucket( $bucket )->cors() );

		if ( is_wp_error( $cors ) ) {
			return $cors;
		}

		if ( null === $cors['policy'] ) {
			return $this->refused( $cors );
		}

		$rules = $cors['policy']->to_array();

		if ( $use_cache ) {
			$this->cache->set( $key, $rules );
		}

		return $rules;
	}

	/**
	 * Replace a bucket's CORS policy with one of S3\Cors\Rules' scenarios.
	 *
	 * @param string   $bucket   Bucket name.
	 * @param string   $scenario A Rules scenario, e.g. 'upload_only'.
	 * @param string[] $origins  Origins it allows.
	 * @param array    $extra    Anything the scenario takes besides.
	 *
	 * @return true|WP_Error
	 */
	public function set_cors_scenario( string $bucket, string $scenario = 'public_read', array $origins = [ '*' ], array $extra = [] ): true|WP_Error {
		$set = $this->attempt( fn() => $this->bucket( $bucket )->set_cors( Rules::policy( $scenario, $origins, $extra ) ) );

		$this->cache->flush_bucket( $bucket );

		if ( is_wp_error( $set ) ) {
			return $set;
		}

		return $set['ok'] ? true : $this->refused( $set );
	}

	/**
	 * Remove a bucket's CORS policy.
	 *
	 * @param string $bucket Bucket name.
	 *
	 * @return true|WP_Error
	 */
	public function delete_cors_configuration( string $bucket ): true|WP_Error {
		$deleted = $this->attempt( fn() => $this->bucket( $bucket )->delete_cors() );

		$this->cache->flush_bucket( $bucket );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return $deleted['ok'] ? true : $this->refused( $deleted );
	}

	/**
	 * Whether a bucket's CORS lets a browser on an origin upload to it.
	 *
	 * @param string $bucket    Bucket name.
	 * @param string $origin    The origin, or '*'.
	 * @param bool   $use_cache Whether cached rules will do.
	 *
	 * @return array<string, mixed>|WP_Error S3\Cors\Analysis::allows_upload(), with the bucket.
	 */
	public function cors_allows_upload( string $bucket, string $origin = '*', bool $use_cache = true ): array|WP_Error {
		$rules = $this->get_cors_rules( $bucket, $use_cache );

		return is_wp_error( $rules ) ? $rules : [ 'bucket' => $bucket ] + Analysis::allows_upload( $rules, $origin );
	}

	/**
	 * What a bucket's CORS allows, in words an admin can act on.
	 *
	 * @param string $bucket    Bucket name.
	 * @param bool   $use_cache Whether cached rules will do.
	 *
	 * @return array<string, mixed>|WP_Error S3\Cors\Analysis::describe(), with the bucket.
	 */
	public function analyze_cors_configuration( string $bucket, bool $use_cache = true ): array|WP_Error {
		$rules = $this->get_cors_rules( $bucket, $use_cache );

		return is_wp_error( $rules ) ? $rules : [ 'bucket' => $bucket ] + Analysis::describe( $rules );
	}
}
