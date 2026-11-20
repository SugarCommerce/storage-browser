<?php
/**
 * Presigned links to download or upload one object, with a fallback for a caller that wants a URL either way.
 *
 * @package   SugarCommerce\StorageBrowser
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Traits\Client;

use SugarCommerce\S3\Bucket;
use SugarCommerce\S3\Method;
use SugarCommerce\Storage\Files\Filename;
use SugarCommerce\Storage\Files\Mime;
use WP_Error;

/**
 * Trait Links
 *
 * Presigned links to download or upload one object, with a fallback for a caller that wants a URL either way.
 *
 * @since 1.0.0
 */
trait Links {

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
}
