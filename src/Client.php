<?php
/**
 * Browser Client
 *
 * @package     SugarCommerce\StorageBrowser
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser;

use InvalidArgumentException;
use SugarCommerce\StorageBrowser\Models\S3Object;
use SugarCommerce\StorageBrowser\Traits\Shared\Context;
use SugarCommerce\StorageBrowser\Traits\Shared\Debug;
use SugarCommerce\StorageBrowser\Utils\Directory;
use SugarCommerce\StorageBrowser\Utils\Sanitize;
use SugarCommerce\Storage\Files\Filename;
use SugarCommerce\Storage\Files\Mime;
use SugarCommerce\S3\Account;
use SugarCommerce\S3\Bucket;
use SugarCommerce\S3\Cors\Analysis;
use SugarCommerce\S3\Cors\Origin;
use SugarCommerce\S3\Cors\Rules;
use SugarCommerce\S3\Method;
use SugarCommerce\S3\Signer;
use SugarCommerce\S3\Transport;
use SugarCommerce\S3\Validate;
use SugarCommerce\StorageBrowser\Utils\Runtime;
use Throwable;
use WP_Error;

/**
 * Class Client
 *
 * What the browser asks of storage, cached, and answered the way its REST
 * routes and list tables want: an array, or a WP_Error carrying storage's own
 * code, its words and a status a REST response can use.
 *
 * Every request goes through S3\Bucket. The browser used to carry a second S3
 * client of its own -- signing, XML parsing and response objects -- that
 * repeated head, delete, copy and CORS; there is one now.
 */
class Client {
	use Debug;
	use Context;

	/**
	 * Longest a presigned link may last, in minutes: SigV4's seven days.
	 */
	private const MAX_MINUTES = 10080;

	/**
	 * The content type of a folder's marker object.
	 */
	private const FOLDER_TYPE = 'application/x-directory';

	/**
	 * Statuses a REST response passes on as they are; anything else storage
	 * answers is a bad gateway.
	 */
	private const REST_STATUSES = [ 400, 403, 404, 409, 429 ];

	/**
	 * The storage account requests go to.
	 *
	 * @var Account
	 */
	private Account $account;

	/**
	 * Signs every request.
	 *
	 * @var Signer
	 */
	private Signer $signer;

	/**
	 * Response cache.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Credential permission probe, built on first use.
	 *
	 * @var Permissions|null
	 */
	private ?Permissions $permissions = null;

	/**
	 * Buckets known to exist without listing them.
	 *
	 * A bucket-scoped token cannot call ListBuckets, so the only way to show a
	 * bucket is to be told its name and confirm it.
	 *
	 * @var string[]
	 */
	private array $known_buckets = [];

	/**
	 * Build a client.
	 *
	 * @param Account     $account    Storage account.
	 * @param string      $access_key Access key id.
	 * @param string      $secret_key Secret access key.
	 * @param bool        $use_cache  Whether to cache answers.
	 * @param int         $cache_ttl  Cache lifetime in seconds.
	 * @param bool        $debug      Whether to report refusals through debug().
	 * @param string|null $context    Which integration this client serves.
	 *
	 * @throws InvalidArgumentException When a credential is empty.
	 */
	public function __construct(
		Account $account,
		#[\SensitiveParameter]
		string $access_key,
		#[\SensitiveParameter]
		string $secret_key,
		bool $use_cache = true,
		int $cache_ttl = DAY_IN_SECONDS,
		bool $debug = false,
		?string $context = null
	) {
		$this->account = $account;
		$this->signer  = $account->signer( $access_key, $secret_key );
		$this->cache   = new Cache( $use_cache, $cache_ttl, self::cache_prefix( $context ) );

		$this->set_debug( $debug );

		if ( null !== $context ) {
			$this->set_context( $context );
		}
	}

	/**
	 * Cache key prefix for this build of the library.
	 *
	 * Two plugins each shipping their own Strauss-prefixed copy must not
	 * address the same transients. The root namespace differs per build
	 * ({@see Runtime::build()}); $context separates several clients in one plugin.
	 *
	 * @param string|null $context Optional context identifier.
	 *
	 * @return string
	 */
	private static function cache_prefix( ?string $context ): string {
		$build = Runtime::build();
		$parts = [ '' === $build ? 'sugarcommerce' : $build, 's3' ];

		if ( null !== $context && '' !== $context ) {
			$parts[] = Sanitize::slug( $context );
		}

		return implode( '_', array_filter( $parts ) ) . '_';
	}

	/**
	 * This client's response cache.
	 *
	 * @return Cache
	 */
	public function cache(): Cache {
		return $this->cache;
	}

	/**
	 * The credential permission probe.
	 *
	 * @return Permissions
	 */
	public function permissions(): Permissions {
		return $this->permissions ??= new Permissions( $this, $this->cache );
	}

	/**
	 * The storage account requests go to.
	 *
	 * @return Account
	 */
	public function get_account(): Account {
		return $this->account;
	}

	/**
	 * The signer every request is signed with.
	 *
	 * @return Signer
	 */
	public function signer(): Signer {
		return $this->signer;
	}

	/**
	 * One bucket, for anything this client does not do itself.
	 *
	 * @param string $name Bucket name.
	 *
	 * @return Bucket
	 */
	public function bucket( string $name ): Bucket {
		return new Bucket( $this->signer, $name );
	}

	/* ─── Buckets ───────────────────────────────────────────────────── */

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

	/* ─── Objects ───────────────────────────────────────────────────── */

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

	/* ─── Folders ───────────────────────────────────────────────────── */

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

	/* ─── Links ─────────────────────────────────────────────────────── */

	/**
	 * A link that downloads an object.
	 *
	 * @param string $bucket     Bucket name.
	 * @param string $object_key Object key.
	 * @param int    $expires    Minutes it works for, 1 to 10080.
	 *
	 * @return string|WP_Error
	 */
	public function get_presigned_url( string $bucket, string $object_key, int $expires = 60 ): string|WP_Error {
		return $this->presign( Method::GET, $bucket, $object_key, $expires );
	}

	/**
	 * A download link, or a fallback when one cannot be made.
	 *
	 * @param string $bucket     Bucket name.
	 * @param string $object_key Object key.
	 * @param int    $expires    Minutes it works for.
	 * @param string $fallback   What to return instead.
	 *
	 * @return string
	 */
	public function get_presigned_url_or_fallback( string $bucket, string $object_key, int $expires = 60, string $fallback = '' ): string {
		$url = $this->get_presigned_url( $bucket, $object_key, $expires );

		return is_wp_error( $url ) ? $fallback : $url;
	}

	/**
	 * A link a browser can PUT a file to.
	 *
	 * Refused for a file type this context may not upload: the link is the
	 * permission, so the type is checked before it exists.
	 *
	 * @param string $bucket     Bucket name.
	 * @param string $object_key Object key.
	 * @param int    $expires    Minutes it works for.
	 *
	 * @return string|WP_Error `invalid_file_type` for a refused type.
	 */
	public function get_presigned_upload_url( string $bucket, string $object_key, int $expires = 15 ): string|WP_Error {
		$filename = Filename::basename( $object_key );

		if ( ! Mime::is_allowed( $filename, $this->get_context() ) ) {
			return new WP_Error(
				'invalid_file_type',
				sprintf(
					/* translators: %s: file extension */
					__( 'File type ".%s" is not allowed for upload', 'sugarcommerce' ),
					Filename::extension( $filename )
				),
				[ 'status' => 400 ]
			);
		}

		return $this->presign( Method::PUT, $bucket, $object_key, $expires );
	}

	/**
	 * An upload link, or a fallback when one cannot be made.
	 *
	 * @param string $bucket     Bucket name.
	 * @param string $object_key Object key.
	 * @param int    $expires    Minutes it works for.
	 * @param string $fallback   What to return instead.
	 *
	 * @return string
	 */
	public function get_presigned_upload_url_or_fallback( string $bucket, string $object_key, int $expires = 15, string $fallback = '' ): string {
		$url = $this->get_presigned_upload_url( $bucket, $object_key, $expires );

		return is_wp_error( $url ) ? $fallback : $url;
	}

	/* ─── CORS ──────────────────────────────────────────────────────── */

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

	/* ─── Internals ─────────────────────────────────────────────────── */

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

	/**
	 * A presigned link, its lifetime clamped to what SigV4 allows.
	 *
	 * @param Method $method     GET or PUT.
	 * @param string $bucket     Bucket name.
	 * @param string $object_key Object key.
	 * @param int    $minutes    Minutes it works for.
	 *
	 * @return string|WP_Error `invalid_parameters` for a key or bucket that
	 *                         cannot be signed.
	 */
	private function presign( Method $method, string $bucket, string $object_key, int $minutes ): string|WP_Error {
		if ( '' === $bucket || '' === $object_key ) {
			return self::invalid( __( 'Bucket and object key are required', 'sugarcommerce' ) );
		}

		$minutes = max( 1, min( $minutes, self::MAX_MINUTES ) );

		return $this->attempt( fn() => $this->signer->presign( $method, $bucket, $object_key, [], $minutes * 60 ) );
	}

	/**
	 * Run a storage call, turning a request that cannot be signed into an error.
	 *
	 * The signer throws for a key with a `.` or `..` segment, or a bucket that
	 * cannot be a hostname; nothing has been sent when it does.
	 *
	 * @param callable $call The call.
	 *
	 * @return mixed What it returned, or a WP_Error.
	 */
	private function attempt( callable $call ): mixed {
		try {
			return $call();
		} catch ( InvalidArgumentException $e ) {
			return self::invalid( $e->getMessage() );
		}
	}

	/**
	 * A failure from S3 core, as the browser reports one.
	 *
	 * Storage's own code and words come first: the code is what separates a
	 * scoped token from wrong credentials, and replacing it sends the admin to
	 * re-enter keys that were never the problem. A request that never got an
	 * answer is said in words an admin can act on, with what the transport
	 * said kept beside it.
	 *
	 * @param WP_Error $error    What S3 core returned.
	 * @param string   $fallback Words for a refusal storage gave none for.
	 *
	 * @return WP_Error With a `status` a REST response can use.
	 */
	private function failure( WP_Error $error, string $fallback = '' ): WP_Error {
		$data   = (array) $error->get_error_data();
		$status = self::status( $error );

		$this->debug( 'Storage refused (' . $error->get_error_code() . ')', $error->get_error_message() );

		if ( 'invalid_parameters' === $error->get_error_code() ) {
			return $error;
		}

		// Nothing answered: the HTTP API's own error, or S3 core's status 0.
		if ( Transport::is_transport_error( (string) $error->get_error_code() ) || ( 0 === $status && isset( $data['operation'] ) ) ) {
			return new WP_Error(
				'http_request_failed',
				Transport::explain( $error->get_error_message(), $this->account->get_endpoint() ),
				[
					'status'          => 400,
					'transport_error' => $error->get_error_message(),
				]
			);
		}

		$code   = self::storage_code( $error );
		$reason = (string) ( $data['reason'] ?? '' );

		return new WP_Error(
			'' === $code ? 'request_failed' : $code,
			'' !== $reason ? $reason : ( '' !== $fallback ? $fallback : $error->get_error_message() ),
			[ 'status' => self::rest_status( $status ) ]
		);
	}

	/**
	 * A refusal S3 core answered with `ok: false` rather than a WP_Error.
	 *
	 * @param array $outcome What Bucket returned: `error`, `status`, `code`, `reason`.
	 *
	 * @return WP_Error
	 */
	private function refused( array $outcome ): WP_Error {
		$this->debug( 'Storage refused', $outcome['error'] ?? '' );

		$code   = (string) ( $outcome['code'] ?? '' );
		$reason = (string) ( $outcome['reason'] ?? '' );

		return new WP_Error(
			'' === $code ? 'request_failed' : $code,
			'' !== $reason ? $reason : (string) ( $outcome['error'] ?? '' ),
			[ 'status' => self::rest_status( (int) ( $outcome['status'] ?? 0 ) ) ]
		);
	}

	/**
	 * An argument refused before anything was sent.
	 *
	 * @param string $message Why.
	 *
	 * @return WP_Error
	 */
	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'invalid_parameters', $message, [ 'status' => 400 ] );
	}

	/**
	 * The status a REST response relays: storage's own where the browser
	 * means the same by it, a bad gateway otherwise.
	 *
	 * @param int $status Storage's status.
	 *
	 * @return int
	 */
	private static function rest_status( int $status ): int {
		return in_array( $status, self::REST_STATUSES, true ) ? $status : 502;
	}

	/**
	 * The HTTP status a failure carries, or 0.
	 *
	 * @param WP_Error $error The failure.
	 *
	 * @return int
	 */
	private static function status( WP_Error $error ): int {
		return (int) ( ( (array) $error->get_error_data() )['status'] ?? 0 );
	}

	/**
	 * Storage's own name for a refusal, or ''.
	 *
	 * @param WP_Error $error The failure.
	 *
	 * @return string
	 */
	private static function storage_code( WP_Error $error ): string {
		return (string) ( ( (array) $error->get_error_data() )['error_code'] ?? '' );
	}

	/**
	 * Bucket names, without blanks or repeats.
	 *
	 * @param array $names Names.
	 *
	 * @return string[]
	 */
	private static function names( array $names ): array {
		return array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', $names ) ) ) ) );
	}
}
