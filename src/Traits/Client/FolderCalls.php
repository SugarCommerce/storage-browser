<?php
/**
 * Folders, which S3 does not have: an empty marker object to make one, every key under a prefix to delete one.
 *
 * @package   SugarCommerce\StorageBrowser
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Traits\Client;

use SugarCommerce\S3\Bucket;
use SugarCommerce\S3\Validate;
use SugarCommerce\StorageBrowser\Utils\Directory;
use WP_Error;

/**
 * Trait FolderCalls
 *
 * Folders, which S3 does not have: an empty marker object to make one, every key under a prefix to delete one.
 *
 * @since 1.0.0
 */
trait FolderCalls {

	/**
	 * Make a folder: an empty object whose key ends in a slash.
	 *
	 * @param string $bucket      Bucket name.
	 * @param string $folder_path The folder's key, ending in `/`.
	 *
	 * @return array{bucket: string, folder_path: string, created?: bool, existed?: bool}|WP_Error
	 */
	public function create_folder( string $bucket, string $folder_path ): array|WP_Error {
		if ( '' === $bucket || '' === $folder_path ) {
			return self::invalid( __( 'Bucket and folder path are required', 'sugarcommerce' ) );
		}

		// Checked before the listing, not left to the write: nothing is sent
		// for a key that could never be written.
		if ( ! Validate::segments( $folder_path ) ) {
			return self::invalid( __( 'A folder path cannot contain a "." or ".." segment.', 'sugarcommerce' ) );
		}

		$result = [
			'bucket'      => $bucket,
			'folder_path' => $folder_path,
		];

		$existing = $this->listing( $bucket, 1, $folder_path, '/', '', false );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		if ( $existing['objects'] || $existing['prefixes'] ) {
			return $result + [ 'existed' => true ];
		}

		$put = $this->attempt( fn() => $this->bucket( $bucket )->put( $folder_path, '', self::FOLDER_TYPE ) );

		if ( is_wp_error( $put ) ) {
			return $this->failure(
				$put,
				/* translators: %s: folder path */
				sprintf( __( 'Failed to create folder "%s"', 'sugarcommerce' ), $folder_path )
			);
		}

		$this->cache->flush_bucket( $bucket );

		return $result + [ 'created' => true ];
	}

	/**
	 * Delete a folder and everything under it.
	 *
	 * Every key under the folder, every page of them, then DeleteObjects a
	 * thousand at a time. Where DeleteObjects is refused whole -- a provider
	 * that does not implement it, a request that timed out -- each key is
	 * deleted on its own instead.
	 *
	 * @param string $bucket      Bucket name.
	 * @param string $folder_path The folder, with or without its slash.
	 *
	 * @return array{folder_path: string, total_requested: int, success_count: int, error_count: int, deleted_objects: array, failed_objects: array}|WP_Error
	 *         `folder_batch_delete_partial_failure` when some keys stayed.
	 */
	public function delete_folder( string $bucket, string $folder_path ): array|WP_Error {
		$folder = Directory::normalize( $folder_path );

		if ( '' === $bucket || '' === $folder ) {
			return self::invalid( __( 'Bucket and folder path are required', 'sugarcommerce' ) );
		}

		$keys  = [];
		$token = '';

		do {
			$page = $this->listing( $bucket, 1000, $folder, '', $token, false );

			if ( is_wp_error( $page ) ) {
				return $page;
			}

			$keys  = array_merge( $keys, array_column( $page['objects'], 'key' ) );
			$token = $page['next_token'];
		} while ( $page['truncated'] && '' !== $token );

		// The marker itself, which a provider may not list. Deleting a key
		// that is not there succeeds, so it is asked for either way.
		$keys   = array_values( array_unique( array_merge( $keys, [ $folder ] ) ) );
		$result = $this->delete_keys( $bucket, $keys );

		$this->cache->flush_bucket( $bucket );

		$data = [
			'folder_path'     => $folder,
			'total_requested' => count( $keys ),
			'success_count'   => count( $result['deleted'] ),
			'error_count'     => count( $result['errors'] ),
			'deleted_objects' => array_map( static fn( string $key ): array => [ 'key' => $key ], $result['deleted'] ),
			'failed_objects'  => $result['errors'],
		];

		if ( $result['errors'] ) {
			return new WP_Error(
				'folder_batch_delete_partial_failure',
				sprintf(
					/* translators: %1$s: folder name, %2$d: number of objects removed, %3$d: number that failed */
					__( 'Folder "%1$s" deleted: %2$d objects removed, %3$d failed', 'sugarcommerce' ),
					$folder,
					$data['success_count'],
					$data['error_count']
				),
				[ 'status' => 502 ] + $data
			);
		}

		return $data;
	}
}
