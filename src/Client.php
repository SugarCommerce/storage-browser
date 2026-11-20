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
use SugarCommerce\S3\Account;
use SugarCommerce\S3\Bucket;
use SugarCommerce\S3\Signer;
use SugarCommerce\StorageBrowser\Traits\Client\BucketCalls;
use SugarCommerce\StorageBrowser\Traits\Client\CorsCalls;
use SugarCommerce\StorageBrowser\Traits\Client\Failures;
use SugarCommerce\StorageBrowser\Traits\Client\FolderCalls;
use SugarCommerce\StorageBrowser\Traits\Client\Links;
use SugarCommerce\StorageBrowser\Traits\Client\ObjectCalls;
use SugarCommerce\StorageBrowser\Traits\Shared\Context;
use SugarCommerce\StorageBrowser\Traits\Shared\Debug;
use SugarCommerce\StorageBrowser\Utils\Runtime;
use SugarCommerce\StorageBrowser\Utils\Sanitize;
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
 *
 * One trait per job in Traits/Client: buckets, objects, folders, links,
 * CORS, and turning a refusal into a WP_Error.
 */
class Client {
	use Debug;
	use Context;

	use BucketCalls;
	use ObjectCalls;
	use FolderCalls;
	use Links;
	use CorsCalls;
	use Failures;

	/**
	 * Longest a presigned link may last, in minutes: SigV4's seven days.
	 */
	private const int MAX_MINUTES = 10080;

	/**
	 * The content type of a folder's marker object.
	 */
	private const string FOLDER_TYPE = 'application/x-directory';

	/**
	 * Statuses a REST response passes on as they are; anything else storage
	 * answers is a bad gateway.
	 */
	private const array REST_STATUSES = [ 400, 403, 404, 409, 429 ];

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
}
