<?php
/**
 * Folders, which S3 does not have: listing, creating and deleting prefixes.
 *
 * @package   SugarCommerce\StorageBrowser
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Traits\Controller;

use SugarCommerce\StorageBrowser\Utils\Directory;
use SugarCommerce\StorageBrowser\Utils\Validate;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Trait FolderRoutes
 *
 * Folders, which S3 does not have: listing, creating and deleting prefixes.
 *
 * @since 1.0.0
 */
trait FolderRoutes {

	/**
	 * List the folders directly inside a prefix
	 *
	 * One level, not a tree. A delimited listing returns the immediate child
	 * prefixes and nothing deeper, so a folder picker can expand a node at a
	 * time and a bucket with a deep layout costs one request per node the
	 * admin actually opens, rather than a walk of the whole thing.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_list_folders( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$prefix = (string) $request['prefix'];

		$result = $this->client->get_objects( $bucket, 1000, $prefix, '/' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$folders = [];

		foreach ( $result['prefixes'] as $folder ) {
			$folders[] = [
				'name'   => Directory::name( $folder ),
				'prefix' => $folder,
			];
		}

		return $this->rest_ok( [
			'bucket'  => $bucket,
			'prefix'  => $prefix,
			'folders' => $folders,
		] );
	}

	/**
	 * Create a folder
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_create_folder( WP_REST_Request $request ) {
		$bucket      = (string) $request['bucket'];
		$prefix      = (string) $request['prefix'];
		$folder_name = (string) $request['folder_name'];

		$validation = Validate::folder_name( $folder_name );
		if ( ! $validation['valid'] ) {
			return $this->rest_fail( 'rest_invalid_folder_name', $validation['message'] );
		}

		$folder_key = Directory::build_folder_key( $prefix, $folder_name );
		$result     = $this->client->create_folder( $bucket, $folder_key );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->client->cache()->flush_bucket( $bucket );

		return $this->rest_ok( [
			/* translators: %1$s: folder name */
			'message'    => sprintf( __( 'Folder "%s" created successfully', 'sugarcommerce' ), $folder_name ),
			'folder_key' => $folder_key,
			'bucket'     => $bucket,
			'prefix'     => $prefix,
		], 201 );
	}

	/**
	 * Delete a folder and everything under it
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_delete_folder( WP_REST_Request $request ) {
		$bucket      = (string) $request['bucket'];
		$folder_path = (string) $request['folder_path'];

		// Refuse to recursively delete the bucket root. After normalization a
		// folder_path of '' or '/' resolves to depth 0, which would wipe the
		// entire bucket, so require at least one path segment.
		if ( Directory::depth( $folder_path ) < 1 ) {
			return $this->rest_fail(
				'rest_refuse_bucket_root',
				__( 'Refusing to delete bucket root. Specify a folder.', 'sugarcommerce' )
			);
		}

		$data = $this->client->delete_folder( $bucket, $folder_path );

		$this->client->cache()->flush_bucket( $bucket );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return $this->rest_ok( [
			'message'     => $this->format_folder_deletion_message( $data ),
			'bucket'      => $bucket,
			'folder_path' => $data['folder_path'] ?? Directory::normalize( $folder_path ),
			'data'        => $data,
		] );
	}

	/**
	 * Format the folder deletion success message
	 *
	 * @param array $data Deletion result data.
	 *
	 * @return string
	 */
	private function format_folder_deletion_message( array $data ): string {
		if ( ! empty( $data['deleted_count'] ) ) {
			/* translators: %1$d: count */
			return sprintf( __( 'Folder deleted successfully (%d items removed)', 'sugarcommerce' ), $data['deleted_count'] );
		}

		if ( ! empty( $data['success_count'] ) ) {
			/* translators: %1$d: count */
			return sprintf( __( 'Folder deleted successfully (%d objects removed)', 'sugarcommerce' ), $data['success_count'] );
		}

		return __( 'Folder deleted successfully', 'sugarcommerce' );
	}
}
