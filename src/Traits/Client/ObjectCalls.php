<?php
/**
 * A page of what is in a bucket, and changing one object: exists, delete, rename.
 *
 * @package   SugarCommerce\StorageBrowser
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Traits\Client;

use SugarCommerce\S3\Bucket;
use SugarCommerce\StorageBrowser\Models\S3Object;
use SugarCommerce\StorageBrowser\Utils\Runtime;
use WP_Error;

/**
 * Trait ObjectCalls
 *
 * A page of what is in a bucket, and changing one object: exists, delete, rename.
 *
 * @since 1.0.0
 */
trait ObjectCalls {

	/**
	 * One page of a bucket, as the browser shows it.
	 *
	 * Folder markers, empty files and hidden files (`.DS_Store` and the like;
	 * see the `s3_object_hidden_files` filter) are left out of `objects`.
	 *
	 * A page that was listed goes through the `s3_get_objects_response`
	 * filter (and its `_{context}` form) with the bucket, prefix, max keys and
	 * context -- the old `arraypress_s3_get_objects_response`. Listing a
	 * bucket is the moment every browser route has in common, so an
	 * integration that has to act on a bucket being browsed hooks it:
	 * WooCommerce approving the bucket as a download directory. A filter
	 * returns the page it was given, or another of the same shape.
	 *
	 * @param string $bucket             Bucket name.
	 * @param int    $max_keys           Keys a page, 1 to 1000.
	 * @param string $prefix             Only keys under this.
	 * @param string $delimiter          Roll keys up into folders at this; '' for none.
	 * @param string $continuation_token The previous page's token.
	 * @param bool   $use_cache          Whether a cached page will do.
	 *
	 * @return array{objects: array<int, S3Object>, prefixes: array<int, string>, truncated: bool, continuation_token: string}|WP_Error
	 */
	public function get_objects(
		string $bucket,
		int $max_keys = 1000,
		string $prefix = '',
		string $delimiter = '/',
		string $continuation_token = '',
		bool $use_cache = true
	): array|WP_Error {
		$page = $this->listing( $bucket, $max_keys, $prefix, $delimiter, $continuation_token, $use_cache );

		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$objects = [];

		foreach ( $page['objects'] as $listed ) {
			$object = new S3Object( $listed );

			if ( ! $object->should_be_excluded( $prefix ) ) {
				$objects[] = $object;
			}
		}

		$result = [
			'objects'            => $objects,
			'prefixes'           => array_map( static fn( string $folder ): string => rtrim( $folder, '/' ) . '/', $page['prefixes'] ),
			'truncated'          => $page['truncated'],
			'continuation_token' => $page['next_token'],
		];

		$args  = [ $bucket, $prefix, $max_keys, $this->get_context() ];
		$value = apply_filters( Runtime::hook( 's3_get_objects_response' ), $result, ...$args );

		if ( $this->has_context() ) {
			$value = apply_filters( Runtime::hook( 's3_get_objects_response_' . $this->get_context() ), $value, ...$args );
		}

		return is_array( $value ) || is_wp_error( $value ) ? $value : $result;
	}

	/**
	 * Whether an object is there.
	 *
	 * @param string $bucket     Bucket name.
	 * @param string $object_key Object key.
	 * @param bool   $use_cache  Whether a cached answer will do.
	 *
	 * @return array{exists: bool, size: int, mime_type: string}|WP_Error Any
	 *         answer but "found" and "not found" is an error: it says nothing
	 *         about the object.
	 */
	public function object_exists( string $bucket, string $object_key, bool $use_cache = true ): array|WP_Error {
		if ( '' === $bucket || '' === $object_key ) {
			return self::invalid( __( 'Bucket and object key are required', 'sugarcommerce' ) );
		}

		$key    = $this->cache->key(
			'object_exists',
			[
				'bucket' => $bucket,
				'key'    => $object_key,
			]
		);
		$cached = $use_cache ? $this->cache->get( $key ) : false;

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$head = $this->attempt( fn() => $this->bucket( $bucket )->head( $object_key ) );

		if ( is_wp_error( $head ) && 404 !== self::status( $head ) ) {
			return $this->failure( $head, __( 'Unable to determine whether the file exists.', 'sugarcommerce' ) );
		}

		$result = is_wp_error( $head )
			? [
				'exists'    => false,
				'size'      => 0,
				'mime_type' => '',
			]
			: [ 'exists' => true ] + $head;

		if ( $use_cache ) {
			$this->cache->set( $key, $result );
		}

		return $result;
	}

	/**
	 * Delete one object.
	 *
	 * @param string $bucket     Bucket name.
	 * @param string $object_key Object key.
	 *
	 * @return true|WP_Error
	 */
	public function delete_object( string $bucket, string $object_key ): true|WP_Error {
		$deleted = $this->attempt( fn() => $this->bucket( $bucket )->delete( $object_key ) );

		// The bucket's cache is not flushed here: the routes do that once an
		// operation is done, and a flush here would also throw away the
		// permission probe's own answer, which deletes its test object.
		return is_wp_error( $deleted ) ? $this->failure( $deleted ) : true;
	}

	/**
	 * Rename an object: copy it to the new key, then delete the old one.
	 *
	 * Not atomic -- S3 has no rename. A copy that fails, including one refused
	 * inside a 200, leaves the original untouched; a delete that fails after a
	 * good copy leaves both, and says so.
	 *
	 * @param string $bucket     Bucket name.
	 * @param string $source_key Key it has.
	 * @param string $target_key Key it gets.
	 *
	 * @return array{source_key: string, target_key: string, warning?: string}|WP_Error
	 */
	public function rename_object( string $bucket, string $source_key, string $target_key ): array|WP_Error {
		$copied = $this->attempt( fn() => $this->bucket( $bucket )->copy( $source_key, $target_key ) );

		if ( is_wp_error( $copied ) ) {
			return $this->failure( $copied, __( 'Failed to copy object during rename operation', 'sugarcommerce' ) );
		}

		$result = [
			'source_key' => $source_key,
			'target_key' => $target_key,
		];

		if ( is_wp_error( $this->delete_object( $bucket, $source_key ) ) ) {
			$result['warning'] = __( 'The object was copied but the original could not be deleted', 'sugarcommerce' );
		}

		return $result;
	}

	/**
	 * One page of Bucket::list(), cached.
	 *
	 * The raw page is what is cached -- arrays of strings and numbers -- so
	 * another build's copy of this library can never read back an object it
	 * has no class for.
	 *
	 * @param string $bucket    Bucket name.
	 * @param int    $max_keys  Keys a page.
	 * @param string $prefix    Only keys under this.
	 * @param string $delimiter Roll up at this; '' for none.
	 * @param string $token     The previous page's token.
	 * @param bool   $use_cache Whether a cached page will do.
	 *
	 * @return array{objects: array, prefixes: array<int, string>, truncated: bool, next_token: string}|WP_Error
	 */
	private function listing( string $bucket, int $max_keys, string $prefix, string $delimiter, string $token, bool $use_cache ): array|WP_Error {
		$key    = $this->cache->key(
			'objects_' . $bucket,
			[
				'max_keys'           => $max_keys,
				'prefix'             => $prefix,
				'delimiter'          => $delimiter,
				'continuation_token' => $token,
			],
			$bucket
		);
		$cached = $use_cache ? $this->cache->get( $key ) : false;

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$page = $this->attempt( fn() => $this->bucket( $bucket )->list( $prefix, $delimiter, $token, $max_keys ) );

		if ( is_wp_error( $page ) ) {
			return $this->failure( $page, __( 'Unable to retrieve objects.', 'sugarcommerce' ) );
		}

		if ( $use_cache ) {
			$this->cache->set( $key, $page );
		}

		return $page;
	}

	/**
	 * Delete keys with DeleteObjects, or one at a time where that is refused.
	 *
	 * @param string             $bucket Bucket name.
	 * @param array<int, string> $keys   Object keys.
	 *
	 * @return array{deleted: array<int, string>, errors: array<int, array{key: string, code: string, message: string}>}
	 */
	private function delete_keys( string $bucket, array $keys ): array {
		$batch = $this->attempt( fn() => $this->bucket( $bucket )->delete_many( $keys ) );

		if ( ! is_wp_error( $batch ) ) {
			return $batch;
		}

		$this->debug( 'DeleteObjects refused; deleting one key at a time', $batch->get_error_message() );

		$result = [
			'deleted' => [],
			'errors'  => [],
		];

		foreach ( $keys as $key ) {
			$deleted = $this->attempt( fn() => $this->bucket( $bucket )->delete( $key ) );

			if ( ! is_wp_error( $deleted ) ) {
				$result['deleted'][] = $key;
				continue;
			}

			$failure            = $this->failure( $deleted );
			$result['errors'][] = [
				'key'     => $key,
				'code'    => (string) $failure->get_error_code(),
				'message' => $failure->get_error_message(),
			];
		}

		return $result;
	}
}
