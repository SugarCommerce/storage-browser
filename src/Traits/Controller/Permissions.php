<?php
/**
 * Who may call a route, which buckets it may name, and what its arguments may hold.
 *
 * @package   SugarCommerce\StorageBrowser
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Traits\Controller;

use SugarCommerce\S3\Bucket;
use SugarCommerce\StorageBrowser\Utils\Sanitize;
use SugarCommerce\StorageBrowser\Utils\Validate;
use WP_Error;
use WP_REST_Request;

/**
 * Trait Permissions
 *
 * Who may call a route, which buckets it may name, and what its arguments may hold.
 *
 * @since 1.0.0
 */
trait Permissions {

	/**
	 * Base permission check: capability only
	 *
	 * Nonce verification is deliberately absent. For cookie-authenticated REST
	 * requests WordPress validates the X-WP-Nonce header itself, in
	 * rest_cookie_check_errors(), before any permission_callback runs — so
	 * re-checking here would be redundant, and checking a *different* nonce
	 * would break legitimate clients using application passwords.
	 *
	 * @return bool|WP_Error
	 */
	public function rest_permission_check() {
		if ( ! current_user_can( $this->capability ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'sugarcommerce' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Permission check for bucket-scoped routes
	 *
	 * Authorization lives here rather than in the handler so that every
	 * bucket-scoped route is gated by construction: a route cannot be added
	 * without a permission_callback, and this one cannot pass without the
	 * bucket being allowed.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return bool|WP_Error
	 */
	public function rest_bucket_permission_check( WP_REST_Request $request ) {
		$allowed = $this->rest_permission_check();

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$bucket = (string) $request['bucket'];

		if ( ! $this->is_bucket_allowed( $bucket ) ) {
			return new WP_Error(
				'rest_bucket_forbidden',
				__( 'You do not have permission to access this bucket.', 'sugarcommerce' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Permission check for a bucket's own settings: its CORS rules
	 *
	 * Rewriting or removing them changes the bucket for every site and every
	 * uploader that uses it, which is a site owner's decision, not an
	 * author's. So the browser's capability and an allowed bucket, and
	 * manage_options as well.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return bool|WP_Error
	 */
	public function rest_settings_permission_check( WP_REST_Request $request ) {
		$allowed = $this->rest_bucket_permission_check( $request );

		if ( true !== $allowed ) {
			return $allowed;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Only an administrator can change a bucket\'s settings.', 'sugarcommerce' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Check whether a bucket may be addressed through this browser
	 *
	 * Every AJAX endpoint takes the bucket straight from $_POST, so without a
	 * check here the capability gate ('upload_files' by default — Author and
	 * up) grants read, write, rename and delete over *every* bucket the
	 * configured credentials can reach, not just the one the browser is
	 * pointed at. S3 credentials are usually account-wide, so that is a real
	 * privilege boundary and not a theoretical one.
	 *
	 * An empty allow-list preserves the historical behaviour of permitting any
	 * bucket. Sites that only ever use one bucket should set it — see
	 * Browser::set_allowed_buckets() and the 'browser_allowed_buckets'
	 * filter.
	 *
	 * @param string $bucket Bucket name from the request
	 *
	 * @return bool True if the bucket may be used
	 */
	protected function is_bucket_allowed( string $bucket ): bool {
		if ( '' === $bucket ) {
			return false;
		}

		// Reject anything that is not a syntactically valid bucket name before
		// it reaches URL building — the value ends up in a Host header or a
		// request path.
		if ( ! preg_match( '/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/i', $bucket ) ) {
			return false;
		}

		$allowed = ( $this->allowed_buckets )();

		if ( empty( $allowed ) ) {
			return true;
		}

		return in_array( $bucket, $allowed, true );
	}

	/**
	 * Validate a bucket name argument
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return bool|WP_Error
	 */
	public function rest_validate_bucket( $value ) {
		if ( ! is_string( $value ) || ! $this->is_bucket_allowed( $value ) ) {
			return new WP_Error(
				'rest_invalid_bucket',
				__( 'Invalid bucket name.', 'sugarcommerce' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Sanitize an object key or prefix
	 *
	 * Object keys legitimately contain spaces, quotes, brackets and unicode, so
	 * they get slash-stripping and control-character removal rather than
	 * sanitize_text_field(), which would mangle them. Traversal is rejected
	 * downstream by S3\Signing\Endpoint::request_key().
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	public function rest_sanitize_object_key( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = wp_unslash( (string) $value );

		// Strip control characters (including NUL) but keep everything else.
		return (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
	}

	/**
	 * Sanitize a plain text argument
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	public function rest_sanitize_text( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( (string) $value ) );
	}
}
