<?php
/**
 * The buckets these keys can see, and what one of them is: count, options, existence, region and details.
 *
 * @package   SugarCommerce\StorageBrowser
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Traits\Client;

use SugarCommerce\S3\Bucket;
use SugarCommerce\S3\Cors\Origin;
use SugarCommerce\StorageBrowser\Utils\Runtime;
use Throwable;
use WP_Error;

/**
 * Trait BucketCalls
 *
 * The buckets these keys can see, and what one of them is: count, options, existence, region and details.
 *
 * @since 1.0.0
 */
trait BucketCalls {

	/**
	 * Tell the client which buckets to fall back to when listing is refused.
	 *
	 * @param string[] $names Bucket names.
	 *
	 * @return self
	 */
	public function set_known_buckets( array $names ): self {
		$this->known_buckets = self::names( $names );

		return $this;
	}

	/**
	 * The buckets these credentials can reach.
	 *
	 * When listing is refused -- a token scoped to one bucket cannot call
	 * ListBuckets, and Cloudflare recommends scoping tokens -- the buckets this
	 * client was told about, and the `s3_known_buckets_fallback` filter's, are
	 * checked one by one instead. A filter alone cannot serve this: two
	 * browsers on one site would overwrite each other's answer.
	 *
	 * @param bool $use_cache Whether a cached answer will do.
	 *
	 * @return array{buckets: array<int, array{name: string, created: string}>, scoped: bool}|WP_Error
	 *         `bucket_listing_forbidden` (403) for a scoped token with no
	 *         bucket that answers.
	 */
	public function get_buckets( bool $use_cache = true ): array|WP_Error {
		$key    = $this->cache->key( 'buckets' );
		$cached = $use_cache ? $this->cache->get( $key ) : false;

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$listed = Bucket::all( $this->signer );

		if ( ! is_wp_error( $listed ) ) {
			$result = [
				'buckets' => $listed,
				'scoped'  => false,
			];

			if ( $use_cache ) {
				$this->cache->set( $key, $result );
			}

			return $result;
		}

		$found = [];

		foreach ( self::names( (array) apply_filters( Runtime::hook( 's3_known_buckets_fallback' ), $this->known_buckets, '' ) ) as $name ) {
			$exists = $this->bucket_exists( $name, $use_cache );

			if ( ! is_wp_error( $exists ) && $exists['exists'] ) {
				$found[] = [
					'name'    => $name,
					'created' => '',
				];
			}
		}

		if ( $found ) {
			return [
				'buckets' => $found,
				'scoped'  => true,
			];
		}

		// A 403 here is not a credential problem: the request was signed and
		// accepted, the token simply lacks ListAllMyBuckets. Bad credentials
		// present differently -- InvalidAccessKeyId, SignatureDoesNotMatch --
		// so the two must not share a message.
		if ( 403 === self::status( $listed ) ) {
			return new WP_Error(
				'bucket_listing_forbidden',
				__( 'This API token cannot list buckets. That is expected for a bucket-scoped token — specify the bucket name directly.', 'sugarcommerce' ),
				[
					'status'       => 403,
					'scoped_token' => true,
				]
			);
		}

		return $this->failure( $listed, __( 'Unable to retrieve buckets.', 'sugarcommerce' ) );
	}

	/**
	 * How many buckets these credentials can reach, and their names.
	 *
	 * @param bool $use_cache Whether a cached listing will do.
	 *
	 * @return array{count: int, buckets: array<int, string>}|WP_Error
	 */
	public function get_bucket_count( bool $use_cache = false ): array|WP_Error {
		$result = $this->get_buckets( $use_cache );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$names = array_column( $result['buckets'], 'name' );

		return [
			'count'   => count( $names ),
			'buckets' => $names,
		];
	}

	/**
	 * Bucket names for a select field, keyed by themselves.
	 *
	 * @param bool $use_cache Whether a cached listing will do.
	 *
	 * @return array<string, string> Empty when nothing could be listed.
	 */
	public function get_bucket_options( bool $use_cache = true ): array {
		$result = $this->get_buckets( $use_cache );

		if ( is_wp_error( $result ) ) {
			return [];
		}

		$names = array_column( $result['buckets'], 'name' );

		return array_combine( $names, $names );
	}

	/**
	 * Whether a bucket exists, by listing one key of it.
	 *
	 * @param string $bucket    Bucket name.
	 * @param bool   $use_cache Whether a cached answer will do.
	 *
	 * @return array{bucket: string, exists: bool}|WP_Error A refusal other
	 *         than NoSuchBucket is an error: it says nothing about existence.
	 */
	public function bucket_exists( string $bucket, bool $use_cache = true ): array|WP_Error {
		if ( '' === $bucket ) {
			return self::invalid( __( 'Bucket name is required', 'sugarcommerce' ) );
		}

		$key    = $this->cache->key( 'bucket_exists', [ 'bucket' => $bucket ] );
		$cached = $use_cache ? $this->cache->get( $key ) : false;

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$listed = $this->attempt( fn() => $this->bucket( $bucket )->list( '', '', '', 1 ) );

		if ( is_wp_error( $listed ) && 'NoSuchBucket' !== self::storage_code( $listed ) ) {
			return $this->failure( $listed, __( 'Unable to determine whether the bucket exists.', 'sugarcommerce' ) );
		}

		$result = [
			'bucket' => $bucket,
			'exists' => ! is_wp_error( $listed ),
		];

		if ( $use_cache ) {
			$this->cache->set( $key, $result );
		}

		return $result;
	}

	/**
	 * Where a bucket is.
	 *
	 * @param string $bucket    Bucket name.
	 * @param bool   $use_cache Whether a cached answer will do.
	 *
	 * @return string|WP_Error
	 */
	public function get_bucket_location( string $bucket, bool $use_cache = true ): string|WP_Error {
		$key    = $this->cache->key( 'bucket_location', [ 'bucket' => $bucket ] );
		$cached = $use_cache ? $this->cache->get( $key ) : false;

		if ( is_string( $cached ) ) {
			return $cached;
		}

		$region = $this->attempt( fn() => $this->bucket( $bucket )->region() );

		if ( is_wp_error( $region ) ) {
			return $this->failure( $region );
		}

		if ( $use_cache ) {
			$this->cache->set( $key, $region );
		}

		return $region;
	}

	/**
	 * Everything the bucket details panel shows: region, creation date, CORS
	 * as it affects uploads from this site, and what the key may do.
	 *
	 * A part that cannot be read is left empty rather than failing the whole
	 * panel; why it could not be read goes in `debug`.
	 *
	 * @param string      $bucket         Bucket name.
	 * @param string|null $current_origin Origin uploads come from; this site's by default.
	 * @param bool        $use_cache      Whether cached answers will do.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_bucket_details( string $bucket, ?string $current_origin = null, bool $use_cache = true ): array|WP_Error {
		if ( '' === $bucket ) {
			return self::invalid( __( 'Bucket name is required', 'sugarcommerce' ) );
		}

		$current_origin ??= Origin::current();

		$details = [
			'bucket'      => $bucket,
			'basic'       => [
				'name'    => $bucket,
				'region'  => null,
				'created' => null,
			],
			'cors'        => [
				'analysis'       => null,
				'upload_ready'   => false,
				'current_origin' => $current_origin,
				'details'        => __( 'CORS not configured', 'sugarcommerce' ),
			],
			'permissions' => null,
			'debug'       => [],
		];

		// CORS fresh, always: it is what the admin is here to check.
		$analysis = $this->analyze_cors_configuration( $bucket, false );

		if ( is_wp_error( $analysis ) ) {
			$details['debug']['cors_analysis_error'] = $analysis->get_error_message();
		} else {
			$details['cors']['analysis'] = $analysis;

			$upload = $this->cors_allows_upload( $bucket, $current_origin, false );

			if ( is_wp_error( $upload ) ) {
				$details['debug']['upload_check_error'] = $upload->get_error_message();
			} else {
				$details['cors']['upload_ready']    = $upload['allows_upload'];
				$details['cors']['allowed_methods'] = $upload['allowed_methods'] ?? [];
				$details['cors']['details']         = $upload['allows_upload']
					? __( 'Upload allowed from current domain', 'sugarcommerce' )
					: __( 'Upload not allowed from current domain', 'sugarcommerce' );
			}
		}

		$region = $this->get_bucket_location( $bucket, $use_cache );

		if ( ! is_wp_error( $region ) ) {
			$details['basic']['region'] = $region;
		}

		$buckets = $this->get_buckets( $use_cache );

		foreach ( is_wp_error( $buckets ) ? [] : $buckets['buckets'] as $listed ) {
			if ( $listed['name'] === $bucket ) {
				$details['basic']['created'] = '' === $listed['created'] ? '' : (string) wp_date( 'Y-m-d H:i:s', (int) strtotime( $listed['created'] ) );
				break;
			}
		}

		try {
			$permissions            = $this->permissions()->check( $bucket );
			$details['permissions'] = [
				'read'   => $permissions['read'],
				'write'  => $permissions['write'],
				'delete' => $permissions['delete'],
			];
		} catch ( Throwable $e ) {
			$details['debug']['permissions_error'] = $e->getMessage();
		}

		return $details;
	}
}
