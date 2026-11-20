<?php
/**
 * Objects: delete, rename, move, what refers to one, and links to download or upload.
 *
 * @package   SugarCommerce\StorageBrowser
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Traits\Controller;

use SugarCommerce\S3\Bucket;
use SugarCommerce\Storage\Files\Filename;
use SugarCommerce\StorageBrowser\Tables\Objects;
use SugarCommerce\StorageBrowser\Utils\Directory;
use SugarCommerce\StorageBrowser\Utils\Runtime;
use SugarCommerce\StorageBrowser\Utils\Sanitize;
use SugarCommerce\StorageBrowser\Utils\Timestamp;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Trait ObjectRoutes
 *
 * Objects: delete, rename, move, what refers to one, and links to download or upload.
 *
 * @since 1.0.0
 */
trait ObjectRoutes {

	/**
	 * Delete a single object
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_delete_object( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$key    = (string) $request['key'];

		$result = $this->client->delete_object( $bucket, $key );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->client->cache()->flush_bucket( $bucket );

		return $this->rest_ok( [
			'message' => __( 'File deleted successfully', 'sugarcommerce' ),
			'bucket'  => $bucket,
			'key'     => $key,
		] );
	}

	/**
	 * Rename an object
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_rename_object( WP_REST_Request $request ) {
		$bucket       = (string) $request['bucket'];
		$current_key  = (string) $request['current_key'];
		$new_filename = (string) $request['new_filename'];

		$problem = Filename::problem( $new_filename );
		if ( '' !== $problem ) {
			return $this->rest_fail( 'rest_invalid_filename', $problem );
		}

		if ( Directory::is_rename_same_key( $current_key, $new_filename ) ) {
			return $this->rest_fail(
				'rest_rename_noop',
				__( 'The new filename is the same as the current filename', 'sugarcommerce' )
			);
		}

		$new_key = Directory::build_rename_key( $current_key, $new_filename );

		$exists = $this->client->object_exists( $bucket, $new_key, false );
		if ( ! is_wp_error( $exists ) && $exists['exists'] ) {
			return $this->rest_fail(
				'rest_rename_conflict',
				/* translators: %s: new filename */
				sprintf( __( 'A file named "%s" already exists in this location', 'sugarcommerce' ), $new_filename ),
				409
			);
		}

		$result = $this->client->rename_object( $bucket, $current_key, $new_key );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->client->cache()->flush_bucket( $bucket );

		/**
		 * Fires after an object has been renamed.
		 *
		 * A key stored elsewhere does not follow the object, so anything
		 * holding one has to be told. Runs only after the rename succeeded.
		 *
		 * @param string $bucket  Bucket name.
		 * @param string $old_key Key the object had.
		 * @param string $new_key Key the object now has.
		 */
		do_action( Runtime::hook( 's3_object_renamed' ), $bucket, $current_key, $new_key );

		return $this->rest_ok( [
			/* translators: %s: new filename */
			'message'      => sprintf( __( 'File renamed to "%s" successfully', 'sugarcommerce' ), $new_filename ),
			'bucket'       => $bucket,
			'old_key'      => $current_key,
			'new_key'      => $new_key,
			'new_filename' => $new_filename,
		] );
	}

	/**
	 * Move an object into another folder
	 *
	 * A move is a rename that keeps the filename and changes the folder, so it
	 * goes through the same copy-then-delete, the same collision check, and
	 * fires the same renamed action -- anything holding the old key has to
	 * follow it here exactly as it does for a rename.
	 *
	 * One object at a time, deliberately. A batch of these is a batch of
	 * non-atomic copy-and-delete pairs, and a failure halfway leaves the admin
	 * working out which of them moved.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_move_object( WP_REST_Request $request ) {
		$bucket        = (string) $request['bucket'];
		$current_key   = (string) $request['key'];
		$target_prefix = (string) $request['target_prefix'];

		$new_key = Directory::build_move_key( $current_key, $target_prefix );

		if ( $new_key === $current_key ) {
			return $this->rest_fail(
				'rest_move_noop',
				__( 'The file is already in that folder', 'sugarcommerce' )
			);
		}

		$exists = $this->client->object_exists( $bucket, $new_key, false );

		if ( ! is_wp_error( $exists ) && $exists['exists'] ) {
			return $this->rest_fail(
				'rest_move_conflict',
				sprintf(
					/* translators: %s: name of the destination folder */
					__( 'A file with this name already exists in "%s"', 'sugarcommerce' ),
					$target_prefix ?: __( 'the bucket root', 'sugarcommerce' )
				),
				409
			);
		}

		$result = $this->client->rename_object( $bucket, $current_key, $new_key );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->client->cache()->flush_bucket( $bucket );

		/** This action is documented in rest_rename_object(). */
		do_action( Runtime::hook( 's3_object_renamed' ), $bucket, $current_key, $new_key );

		return $this->rest_ok( [
			'message' => sprintf(
				/* translators: %s: name of the destination folder */
				__( 'File moved to "%s"', 'sugarcommerce' ),
				$target_prefix ?: __( 'the bucket root', 'sugarcommerce' )
			),
			'bucket'  => $bucket,
			'old_key' => $current_key,
			'new_key' => $new_key,
		] );
	}

	/**
	 * Report what else points at an object
	 *
	 * A key stored elsewhere -- a product's download file, a post meta value --
	 * does not follow the object when it is renamed or moved. Nothing in this
	 * library knows what those places are, so it asks: a consumer answers the
	 * filter with whatever it holds, and the browser warns before renaming.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_object_references( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$key    = (string) $request['key'];

		/**
		 * Filter the things referring to an object.
		 *
		 * @param array  $references List of [ 'label' => string, 'edit_url' => string ].
		 * @param string $bucket     Bucket name.
		 * @param string $key        Object key.
		 */
		$references = (array) apply_filters( Runtime::hook( 's3_object_references' ), [], $bucket, $key );

		$clean = [];

		foreach ( $references as $reference ) {
			$label = trim( (string) ( $reference['label'] ?? '' ) );

			if ( '' === $label ) {
				continue;
			}

			$clean[] = [
				'label'    => $label,
				'edit_url' => esc_url_raw( (string) ( $reference['edit_url'] ?? '' ) ),
			];
		}

		return $this->rest_ok( [
			'bucket'     => $bucket,
			'key'        => $key,
			'references' => $clean,
			'count'      => count( $clean ),
		] );
	}

	/**
	 * Mint a presigned download URL
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_get_download_url( WP_REST_Request $request ) {
		$bucket  = (string) $request['bucket'];
		$key     = (string) $request['key'];
		$minutes = Sanitize::minutes( (int) $request['expires_minutes'] );

		$exists = $this->client->object_exists( $bucket, $key, false );
		if ( is_wp_error( $exists ) ) {
			return $this->rest_fail( 'rest_object_check_failed', __( 'Error checking if file exists', 'sugarcommerce' ), 502 );
		}

		if ( ! $exists['exists'] ) {
			return $this->rest_fail( 'rest_object_not_found', __( 'File does not exist', 'sugarcommerce' ), 404 );
		}

		$url = $this->client->get_presigned_url( $bucket, $key, $minutes );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		return $this->rest_ok( [
			'url'        => $url,
			'expires_at' => Timestamp::in_minutes( $minutes ),
			'expires_in' => $minutes,
			'bucket'     => $bucket,
			'key'        => $key,
			'message'    => sprintf(
				/* translators: %1$d: count */
				__( 'Link generated successfully (expires in %d minutes)', 'sugarcommerce' ),
				$minutes
			),
		] );
	}

	/**
	 * Mint a presigned upload URL
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_get_upload_url( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$key    = (string) $request['key'];

		$url = $this->client->get_presigned_upload_url( $bucket, $key );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$this->client->cache()->flush_bucket( $bucket );

		return $this->rest_ok( [
			'url'     => $url,
			'expires' => Timestamp::in_minutes( 15 ),
		] );
	}
}
