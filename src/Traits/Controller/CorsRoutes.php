<?php
/**
 * A bucket's CORS rules: set for browser uploads, or removed.
 *
 * @package   SugarCommerce\StorageBrowser
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Traits\Controller;

use SugarCommerce\S3\Cors\Origin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Trait CorsRoutes
 *
 * A bucket's CORS rules: set for browser uploads, or removed.
 *
 * @since 1.0.0
 */
trait CorsRoutes {

	/**
	 * Configure CORS for browser uploads
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_setup_cors( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$origin = (string) $request['origin'] ?: Origin::current();

		if ( '' === $origin ) {
			return $this->rest_fail( 'rest_origin_required', __( 'Origin is required for CORS setup', 'sugarcommerce' ) );
		}

		$this->client->cache()->flush_bucket( $bucket );

		$result = $this->client->set_cors_scenario( $bucket, 'upload_only', [ $origin ] );

		if ( is_wp_error( $result ) ) {
			// AccessDenied on PutBucketCors is not a transient failure and no
			// amount of retrying fixes it: R2 API tokens carry a permission
			// level, and a token with Object Read & Write can upload objects
			// while being refused any change to bucket configuration. That is
			// the recommended way to scope a token, so it is a normal state
			// rather than a misconfiguration — but it does mean CORS has to be
			// set once in the provider's console. Saying "setup failed" sends
			// people to retry something that cannot succeed.
			$denied = in_array(
				$result->get_error_code(),
				[ 'AccessDenied', 'access_denied' ],
				true
			) || 403 === ( ( (array) $result->get_error_data() )['status'] ?? 0 );

			return $this->rest_fail(
				$denied ? 'rest_cors_permission_denied' : 'rest_cors_setup_failed',
				$denied
					? __( 'This API token can read and write objects but is not permitted to change bucket settings, so CORS has to be configured once in your provider\'s console. The rule to add is below.', 'sugarcommerce' )
					: $result->get_error_message(),
				502
			);
		}

		$this->client->cache()->flush_bucket( $bucket );

		$verification = $this->client->cors_allows_upload( $bucket, $origin, false );
		$verified     = ! is_wp_error( $verification ) && $verification['allows_upload'];

		return $this->rest_ok( [
			'bucket'              => $bucket,
			'origin'              => $origin,
			'verification_passed' => $verified,
			'message'             => $verified
				? __( 'CORS configured successfully for uploads', 'sugarcommerce' )
				: __( 'CORS was saved but is not active yet — it can take a moment to propagate.', 'sugarcommerce' ),
		] );
	}

	/**
	 * Remove the bucket's CORS configuration
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_delete_cors( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$result = $this->client->delete_cors_configuration( $bucket );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->client->cache()->flush_bucket( $bucket );

		return $this->rest_ok( [
			'bucket'  => $bucket,
			'message' => __( 'CORS configuration removed', 'sugarcommerce' ),
		] );
	}
}
