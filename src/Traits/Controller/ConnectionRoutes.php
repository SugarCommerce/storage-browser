<?php
/**
 * Testing the storage connection, quickly or step by step.
 *
 * @package   SugarCommerce\StorageBrowser
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Traits\Controller;

use SugarCommerce\S3\Cors\Origin;
use SugarCommerce\S3\Probe;
use SugarCommerce\S3\Probe\Step;
use SugarCommerce\S3\Transport;
use SugarCommerce\StorageBrowser\Utils\Runtime;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Trait ConnectionRoutes
 *
 * Testing the storage connection, quickly or step by step.
 *
 * @since 1.0.0
 */
trait ConnectionRoutes {

	/**
	 * Test the storage connection
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_connection_test() {
		$answer = $this->connection_answer();
		$data   = $answer->get_data();

		// The bucket the full check (connection/check) should try next, for
		// a script that shows each step.
		if ( is_array( $data ) && 'failed' !== ( $data['status'] ?? '' ) ) {
			$bucket = $this->check_bucket();

			if ( '' !== $bucket ) {
				$data['check_bucket'] = $bucket;
				$answer               = $this->rest_ok( $data );
			}
		}

		return $answer;
	}

	/**
	 * Run the whole connection test against one bucket
	 *
	 * What a store will do with real files, done with a tiny one: list,
	 * write, read back, delete, start and abort a multipart upload, and ask
	 * whether a browser on this site may upload straight to the bucket. Each
	 * step comes back with what went wrong and what to change.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_connection_check( WP_REST_Request $request ): WP_REST_Response {
		$bucket = (string) $request['bucket'];
		$origin = Origin::current();
		$steps  = ( new Probe() )->run( $this->client->bucket( $bucket ), '' === $origin ? [] : [ $origin ] );
		$failed = Probe::failure( $steps );
		$notes  = array_values( array_filter( array_map( static fn( Step $step ): string => $step->note, $steps ) ) );

		$this->client->cache()->flush_bucket( $bucket );

		return $this->rest_ok( [
			'status'  => null === $failed ? 'ok' : 'failed',
			'bucket'  => $bucket,
			'message' => null === $failed
				/* translators: %s: bucket name */
				? sprintf( __( '"%s" passed every check.', 'sugarcommerce' ), $bucket )
				: __( 'The connection test found a problem.', 'sugarcommerce' ),
			'summary' => null === $failed ? '' : $failed->message,
			'notes'   => $notes,
			'checks'  => array_map( static fn( Step $step ): array => $step->to_array(), $steps ),
		] );
	}

	/**
	 * The bucket the full connection check tries
	 *
	 * The fallback bucket a consumer filters in, else the bucket the browser
	 * opens on, else the one bucket it is confined to.
	 *
	 * @return string '' when there is none to try.
	 */
	private function check_bucket(): string {
		$bucket = (string) apply_filters( Runtime::hook( 's3_connection_test_fallback_bucket' ), '' );

		if ( '' === $bucket ) {
			$allowed = ( $this->allowed_buckets )();
			$bucket  = '' !== $this->default_bucket ? $this->default_bucket : ( 1 === count( $allowed ) ? (string) reset( $allowed ) : '' );
		}

		return $this->is_bucket_allowed( $bucket ) ? $bucket : '';
	}

	/**
	 * The first half of the connection test: the account and its buckets
	 *
	 * @return WP_REST_Response
	 */
	private function connection_answer(): WP_REST_Response {
		// Account-level ListBuckets works for admin / master tokens.
		$result = $this->client->get_bucket_count();

		if ( ! is_wp_error( $result ) ) {
			$data = $result;

			return $this->rest_ok( [
				'status'  => 'ok',
				'message' => __( 'Connection successful.', 'sugarcommerce' ),
				'summary' => sprintf(
					/* translators: %1$d: count */
					_n( 'Found %d accessible bucket', 'Found %d accessible buckets', $data['count'], 'sugarcommerce' ),
					$data['count']
				),
				'buckets' => $data['buckets'] ?? [],
				'count'   => $data['count'],
			] );
		}

		// A token scoped to specific buckets cannot list them. That is not a
		// failure — Cloudflare recommends scoping R2 tokens — so it must not be
		// reported as one. The only thing missing is the bucket name, which the
		// consumer supplies through this filter.
		// Nothing answered, so nothing has been learned about the credentials.
		// Falling through would try the bucket next, fail the same way, and
		// report a reachability problem as a problem with the bucket.
		if ( Transport::is_transport_error( (string) $result->get_error_code() ) ) {
			return $this->rest_ok( [
				'status'  => 'failed',
				'message' => __( 'Could not reach the storage provider.', 'sugarcommerce' ),
				'summary' => $result->get_error_message(),
			] );
		}

		$scoped          = 'bucket_listing_forbidden' === $result->get_error_code();
		$fallback_bucket = (string) apply_filters( Runtime::hook( 's3_connection_test_fallback_bucket' ), '' );

		if ( '' !== $fallback_bucket ) {
			$exists = $this->client->bucket_exists( $fallback_bucket, false );

			if ( ! is_wp_error( $exists ) && $exists['exists'] ) {
				return $this->rest_ok( [
					'status'  => 'ok',
					'message' => __( 'Connection successful.', 'sugarcommerce' ),
					'summary' => sprintf(
						/* translators: %s: bucket name */
						__( 'Verified access to "%s". This token is scoped to specific buckets, so it cannot list the others.', 'sugarcommerce' ),
						$fallback_bucket
					),
					'buckets' => [ $fallback_bucket ],
					'count'   => 1,
					'scoped'  => true,
				] );
			}

			// The name is wrong, or the token cannot reach it. Say which
			// bucket was tried — otherwise the admin has no way to tell a typo
			// from a permissions problem.
			return $this->rest_ok( [
				'status'  => 'failed',
				'message' => __( 'Could not reach that bucket.', 'sugarcommerce' ),
				'summary' => sprintf(
					/* translators: 1: bucket name, 2: error from the provider */
					__( 'The credentials work, but "%1$s" could not be reached: %2$s', 'sugarcommerce' ),
					$fallback_bucket,
					is_wp_error( $exists ) ? $exists->get_error_message() : __( 'The bucket does not exist.', 'sugarcommerce' )
				),
				'scoped'  => $scoped,
			] );
		}

		if ( $scoped ) {
			// Credentials are fine; the configuration is simply incomplete.
			return $this->rest_ok( [
				'status'  => 'needs_bucket',
				'message' => __( 'Credentials accepted.', 'sugarcommerce' ),
				'summary' => __( 'This token is scoped to specific buckets, so it cannot list them — which is the recommended setup. Enter the bucket name to finish configuring and verify access.', 'sugarcommerce' ),
				'scoped'  => true,
			] );
		}

		// Anything else really is a credential or endpoint problem, and the
		// provider's own message is more useful than a generic one.
		return $this->rest_ok( [
			'status'  => 'failed',
			'message' => __( 'Connection failed.', 'sugarcommerce' ),
			'summary' => $result->get_error_message(),
		] );
	}
}
