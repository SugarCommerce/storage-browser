<?php
/**
 * The bucket list, one bucket's details and listing, and the cache.
 *
 * @package   SugarCommerce\StorageBrowser
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Traits\Controller;

use SugarCommerce\S3\Cors\Origin;
use SugarCommerce\StorageBrowser\Picker\Listing;
use SugarCommerce\StorageBrowser\Tables\Objects;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Trait BucketRoutes
 *
 * The bucket list, one bucket's details and listing, and the cache.
 *
 * @since 1.0.0
 */
trait BucketRoutes {

	/**
	 * Clear all cached S3 data
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_clear_cache() {
		if ( ! $this->client->cache()->flush() ) {
			return $this->rest_fail( 'rest_cache_clear_failed', __( 'Failed to clear cache', 'sugarcommerce' ), 500 );
		}

		return $this->rest_ok( [ 'message' => __( 'Cache cleared successfully', 'sugarcommerce' ) ] );
	}

	/**
	 * Get details for a single bucket
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_get_bucket_details( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$data = $this->client->get_bucket_details( $bucket, Origin::current(), false );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// The debug payload carries raw provider error strings; it is a
		// development aid, not something to ship to every browser.
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			unset( $data['debug'] );
		}

		return $this->rest_ok( $data );
	}

	/**
	 * List a page of objects
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_list_objects( WP_REST_Request $request ) {
		$page = Objects::get_page_data(
			$this->client,
			$this->provider_id,
			(string) $request['bucket'],
			(string) $request['prefix'],
			(string) $request['continuation_token']
		);

		if ( is_wp_error( $page ) ) {
			return $page;
		}

		return $this->rest_ok( $page );
	}

	/**
	 * The buckets the picker may open
	 *
	 * The ones this browser is confined to when it is; otherwise every bucket
	 * the key can list.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_list_buckets() {
		$names = array_values( array_unique( array_filter( array_map( 'strval', ( $this->allowed_buckets )() ) ) ) );

		if ( ! $names ) {
			$listed = $this->client->get_buckets();

			if ( is_wp_error( $listed ) ) {
				return $listed;
			}

			$names = array_map( static fn( array $bucket ): string => (string) $bucket['name'], $listed['buckets'] );
		}

		return $this->rest_ok( [
			'buckets' => array_map( static fn( string $name ): array => [ 'name' => $name ], $names ),
			'default' => $this->default_bucket,
		] );
	}

	/**
	 * A folder, or a search beneath it, for the picker
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_browse( WP_REST_Request $request ) {
		$page = Listing::browse(
			$this->client,
			(string) $request['bucket'],
			(string) $request['prefix'],
			(string) $request['search'],
			(string) $request['continuation_token']
		);

		return is_wp_error( $page ) ? $page : $this->rest_ok( $page );
	}
}
