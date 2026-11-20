<?php
/**
 * Resumable browser uploads: start, status, part URLs, finish and abort.
 *
 * @package   SugarCommerce\StorageBrowser
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Traits\Controller;

use SugarCommerce\S3\Bucket;
use SugarCommerce\S3\Cors\Origin;
use SugarCommerce\S3\Multipart;
use SugarCommerce\S3\Probe;
use SugarCommerce\S3\Upload;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Trait UploadRoutes
 *
 * Resumable browser uploads: start, status, part URLs, finish and abort.
 *
 * An upload id is only ever the current user's, in the bucket the route
 * names: owned_upload() is the one way any of these finds one.
 *
 * @since 1.0.0
 */
trait UploadRoutes {

	/**
	 * Start a large upload, or find the one this file already began
	 *
	 * The same file dropped again -- after a reload, a closed tab, a lost
	 * connection -- has the same key, size and fingerprint, so its upload is
	 * picked up where storage says it stopped rather than started over.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_start_upload( WP_REST_Request $request ) {
		$bucket    = (string) $request['bucket'];
		$key       = (string) $request['key'];
		$size      = (int) $request['size'];
		$owner     = get_current_user_id();
		$multipart = $this->multipart( $bucket );
		$upload    = $multipart->match( $key, $size, (string) $request['fingerprint'], $owner );
		$resumed   = null !== $upload;

		if ( null !== $upload ) {
			$upload = $multipart->resume( $upload );

			// Storage no longer has it: start again.
			if ( is_wp_error( $upload ) ) {
				$upload  = null;
				$resumed = false;
			}
		}

		if ( null === $upload ) {
			$upload = $multipart->begin( $key, $size, [
				'mime_type'   => (string) $request['mime_type'],
				'fingerprint' => (string) $request['fingerprint'],
				'owner'       => $owner,
			] );
		}

		if ( is_wp_error( $upload ) ) {
			return $this->upload_error( $upload );
		}

		return $this->rest_ok( $this->upload_payload( $upload, $resumed ) + [ 'checksums' => $this->checksums_allowed( $bucket ) ], $resumed ? 200 : 201 );
	}

	/**
	 * Whether the bucket lets this site's browser send a part's Content-MD5
	 *
	 * Asked the way the browser asks, by a preflight, and kept for an hour.
	 * A bucket that refuses the header refuses the part outright, which
	 * looks like a dropped connection to the script; so the script is told
	 * up front and leaves the header off. A bucket that allows no uploads
	 * from here at all fails the upload either way, so that is a yes.
	 *
	 * @param string $bucket Bucket name.
	 *
	 * @return bool
	 */
	private function checksums_allowed( string $bucket ): bool {
		$origin = Origin::current();

		if ( '' === $origin ) {
			return true;
		}

		$cache  = $this->client->cache();
		$key    = $cache->key( 'upload_checksums', [ 'origin' => $origin ], $bucket );
		$cached = $cache->get( $key );

		if ( is_array( $cached ) ) {
			return (bool) $cached['allowed'];
		}

		$url     = $this->client->signer()->presign_put( $bucket, Probe::PREFIX . 'preflight.txt', 300 );
		$allowed = Probe::preflight( $url, $origin, 'content-type,content-md5' ) || ! Probe::preflight( $url, $origin, 'content-type' );

		$cache->set( $key, [ 'allowed' => $allowed ], HOUR_IN_SECONDS );

		return $allowed;
	}

	/**
	 * Where a large upload stands, by storage's own list of parts
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_upload_status( WP_REST_Request $request ) {
		$upload = $this->owned_upload( $request );

		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		$upload = $this->multipart( (string) $request['bucket'] )->resume( $upload );

		return is_wp_error( $upload ) ? $this->upload_error( $upload ) : $this->rest_ok( $this->upload_payload( $upload, true ) );
	}

	/**
	 * Presigned URLs for some of an upload's parts
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_sign_upload_parts( WP_REST_Request $request ) {
		$upload = $this->owned_upload( $request );

		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		$numbers = array_slice( array_map( 'intval', (array) $request['parts'] ), 0, self::PARTS_PER_SIGNING );
		$urls    = $this->multipart( (string) $request['bucket'] )->sign( $upload, $numbers, self::PART_URL_SECONDS );

		return $this->rest_ok( [
			'id'         => $upload->id,
			// An object, not a list: the keys are part numbers.
			'urls'       => (object) $urls,
			'expires_in' => self::PART_URL_SECONDS,
		] );
	}

	/**
	 * Put a large upload together once every part is in storage
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_complete_upload( WP_REST_Request $request ) {
		$upload = $this->owned_upload( $request );

		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		$bucket  = (string) $request['bucket'];
		$address = $this->multipart( $bucket )->finish( $upload );

		if ( is_wp_error( $address ) ) {
			return $this->upload_error( $address );
		}

		$this->client->cache()->flush_bucket( $bucket );

		return $this->rest_ok( [
			'id'      => $upload->id,
			'bucket'  => $bucket,
			'key'     => $upload->key,
			'address' => $address,
			'size'    => $upload->size,
			'message' => __( 'Upload complete.', 'sugarcommerce' ),
		] );
	}

	/**
	 * Give a large upload up, freeing its parts in storage
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_abort_upload( WP_REST_Request $request ) {
		$upload = $this->owned_upload( $request );

		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		if ( ! $this->multipart( (string) $request['bucket'] )->abort( $upload ) ) {
			return $this->rest_fail( 'rest_upload_abort_failed', __( 'Storage would not cancel the upload. It will be cleared away automatically within a week.', 'sugarcommerce' ), 502 );
		}

		return $this->rest_ok( [
			'id'      => $upload->id,
			'aborted' => true,
		] );
	}

	/**
	 * Uploads to one bucket
	 *
	 * @param string $bucket Bucket name.
	 *
	 * @return Multipart
	 */
	private function multipart( string $bucket ): Multipart {
		return new Multipart( $this->client->bucket( $bucket ) );
	}

	/**
	 * The upload a request names, if it is the current user's
	 *
	 * An upload id is not a secret anybody else should be able to spend: it
	 * is the current user's, in the bucket the route names, or nothing.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return Upload|WP_Error
	 */
	private function owned_upload( WP_REST_Request $request ): Upload|WP_Error {
		$upload = $this->multipart( (string) $request['bucket'] )->find( (string) $request['id'] );

		if ( null === $upload ) {
			return $this->rest_fail( 'rest_upload_not_found', __( 'That upload is finished, cancelled or was never started.', 'sugarcommerce' ), 404 );
		}

		if ( $upload->owner !== get_current_user_id() ) {
			return $this->rest_fail( 'rest_upload_forbidden', __( 'That upload was started by somebody else.', 'sugarcommerce' ), rest_authorization_required_code() );
		}

		return $upload;
	}

	/**
	 * What the script needs to send an upload's parts
	 *
	 * @param Upload $upload  The upload.
	 * @param bool   $resumed Whether it was begun before.
	 *
	 * @return array<string, mixed>
	 */
	private function upload_payload( Upload $upload, bool $resumed ): array {
		return [
			'id'         => $upload->id,
			'key'        => $upload->key,
			'size'       => $upload->size,
			'part_size'  => $upload->part_size,
			'part_count' => $upload->part_count(),
			'done'       => array_map( 'intval', array_keys( $upload->parts ) ),
			'progress'   => $upload->progress(),
			'resumed'    => $resumed,
		];
	}

	/**
	 * An upload failure, with a status a REST response can carry
	 *
	 * The upload's own errors keep theirs; storage's refusals become a 502
	 * with storage's words, since storage refusing is not this site
	 * refusing the browser.
	 *
	 * @param WP_Error $error The failure.
	 *
	 * @return WP_Error
	 */
	private function upload_error( WP_Error $error ): WP_Error {
		$data = (array) $error->get_error_data();

		if ( str_starts_with( (string) $error->get_error_code(), 's3_upload_' ) && isset( $data['status'] ) ) {
			return $error;
		}

		$reason = (string) ( $data['reason'] ?? '' );

		return new WP_Error(
			(string) $error->get_error_code(),
			'' === $reason
				? $error->get_error_message()
				/* translators: %s: storage's own words for why it refused. */
				: sprintf( __( 'Storage refused: %s', 'sugarcommerce' ), $reason ),
			[
				'status'     => 502,
				'error_code' => (string) ( $data['error_code'] ?? '' ),
			]
		);
	}
}
