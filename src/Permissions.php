<?php
/**
 * Credential Permission Probe
 *
 * Works out what a set of credentials can actually do with a bucket. There is
 * no API that answers this: S3 has no "describe my permissions" call, so the
 * only way to know whether a token may write is to write something.
 *
 * That makes this the one part of the library that deliberately modifies a
 * customer's bucket, and everything here is arranged around keeping that rare
 * and tidy.
 *
 * @package     SugarCommerce\StorageBrowser
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser;


/**
 * Class Permissions
 */
class Permissions {

	/**
	 * How long a probe result stands before it is taken again.
	 *
	 * Credentials change rarely, and each re-probe costs a write against the
	 * customer's bucket, so this is deliberately longer than the client's own
	 * cache lifetime rather than inheriting it.
	 */
	private const TTL = 86400;

	/**
	 * Build a probe.
	 *
	 * @param Client $client Client to probe with.
	 * @param Cache  $cache  Where results are kept between requests.
	 */
	public function __construct(
		private Client $client,
		private Cache $cache
	) {
	}

	/**
	 * Report what the credentials can do with a bucket.
	 *
	 * Reads are established by listing, which changes nothing. Writes cannot
	 * be established without writing, so $probe_writes exists for callers that
	 * only want the harmless half.
	 *
	 * @param string $bucket       Bucket to probe.
	 * @param bool   $use_cache    Whether a stored result may be returned.
	 * @param bool   $probe_writes Whether to attempt a write and a delete.
	 *
	 * @return array Read, write and delete flags, plus any errors.
	 */
	public function check( string $bucket, bool $use_cache = true, bool $probe_writes = true ): array {
		$key = $this->cache->key( 'key_permissions', [
			'bucket' => $bucket,
			'writes' => $probe_writes,
		], $bucket );

		if ( $use_cache ) {
			$cached = $this->cache->get( $key );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$permissions = [
			'read'      => false,
			'write'     => false,
			'delete'    => false,
			'errors'    => [],
			'tested_at' => time(),
			'bucket'    => $bucket,
		];

		// Listing is the only probe that leaves the bucket as it found it, so
		// it runs first and gates the rest: a token that cannot read is not
		// going to be allowed to write.
		$permissions['read'] = ! is_wp_error( $this->client->get_objects( $bucket, 1, '', '/', '', false ) );

		if ( $permissions['read'] && $probe_writes ) {
			$permissions = $this->probe_writes( $bucket, $permissions );
		}

		if ( $use_cache ) {
			$this->cache->set( $key, $permissions, self::TTL );
		}

		return $permissions;
	}

	/**
	 * Forget what was learned about a bucket, so the next check re-probes.
	 *
	 * @param string $bucket Bucket name.
	 *
	 * @return void
	 */
	public function forget( string $bucket ): void {
		$this->cache->flush_bucket( $bucket );
	}

	/**
	 * Write an object, then try to remove it.
	 *
	 * @param string $bucket      Bucket to probe.
	 * @param array  $permissions Result so far.
	 *
	 * @return array Updated result.
	 */
	private function probe_writes( string $bucket, array $permissions ): array {
		$key = 'permissions-test-' . wp_generate_password( 16, false ) . '.txt';

		if ( ! $this->put( $bucket, $key, $this->probe_content() ) ) {
			$permissions['errors']['write'] = __( 'Upload was refused.', 'sugarcommerce' );

			return $permissions;
		}

		$permissions['write'] = true;

		if ( true === $this->client->delete_object( $bucket, $key ) ) {
			$permissions['delete'] = true;

			return $permissions;
		}

		// The probe object is now stranded: the token could put it there but
		// cannot take it away. Say which key it is so an admin can remove it,
		// rather than writing a second file to explain the first -- that one
		// would be equally undeletable, and doubles the litter every time
		// this runs.
		$permissions['errors']['delete'] = sprintf(
			/* translators: %s: object key left in the bucket. */
			__( 'The test object could not be removed. Delete "%s" manually.', 'sugarcommerce' ),
			$key
		);
		$permissions['leftover_key'] = $key;

		return $permissions;
	}

	/**
	 * Write the probe object.
	 *
	 * @param string $bucket  Bucket name.
	 * @param string $key     Object key.
	 * @param string $content Body.
	 *
	 * @return bool Whether storage took it.
	 */
	private function put( string $bucket, string $key, string $content ): bool {
		try {
			return true === $this->client->bucket( $bucket )->put( $key, $content, 'text/plain' );
		} catch ( \InvalidArgumentException ) {
			return false;
		}
	}

	/**
	 * Body for the probe object.
	 *
	 * Says what it is, so an admin who finds one left behind knows it is not
	 * theirs and is safe to remove.
	 *
	 * @return string
	 */
	private function probe_content(): string {
		return sprintf(
			'S3 permissions test file. Safe to delete. Created: %s',
			gmdate( 'Y-m-d H:i:s' ) . ' UTC'
		);
	}
}
