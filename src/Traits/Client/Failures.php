<?php
/**
 * Turning a refusal from storage into the WP_Error a REST route or a list table can use.
 *
 * @package   SugarCommerce\StorageBrowser
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Traits\Client;

use InvalidArgumentException;
use SugarCommerce\S3\Bucket;
use SugarCommerce\S3\Transport;
use WP_Error;

/**
 * Trait Failures
 *
 * Turning a refusal from storage into the WP_Error a REST route or a list table can use.
 *
 * Storage's own code, its words and a status a REST response can use, so
 * why storage said no reaches the person who has to fix it.
 *
 * @since 1.0.0
 */
trait Failures {

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
