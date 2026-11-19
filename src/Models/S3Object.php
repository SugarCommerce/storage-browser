<?php
/**
 * S3 Object
 *
 * @package     SugarCommerce\StorageBrowser
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Models;

use SugarCommerce\StorageBrowser\Utils\Runtime;
use SugarCommerce\Storage\Files\Filename;
use SugarCommerce\Storage\Files\Mime;

/**
 * Class S3Object
 *
 * One file in a listing, as the browser shows it: its name, size and date
 * formatted, its type, the data attributes the scripts read, and whether it
 * is hidden from the listing at all.
 */
class S3Object {

	/**
	 * Object key
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Object size in bytes
	 *
	 * @var int
	 */
	private int $size;

	/**
	 * Last modified timestamp
	 *
	 * @var string
	 */
	private string $last_modified;

	/**
	 * ETag value (cleaned, without quotes)
	 *
	 * @var string
	 */
	private string $etag;

	/**
	 * Storage class
	 *
	 * @var string
	 */
	private string $storage_class;


	/**
	 * Build one from a line of S3\Bucket::list().
	 *
	 * @param array{key?: string, size?: int, last_modified?: string, etag?: string, storage_class?: string} $data The listed object.
	 */
	public function __construct( array $data ) {
		$this->key           = (string) ( $data['key'] ?? '' );
		$this->size          = (int) ( $data['size'] ?? 0 );
		$this->last_modified = (string) ( $data['last_modified'] ?? '' );
		$this->etag          = trim( (string) ( $data['etag'] ?? '' ), '"' );
		$this->storage_class = (string) ( $data['storage_class'] ?? 'STANDARD' );
	}


	/**
	 * Check if this object should be excluded from display
	 *
	 * @param string $current_prefix The current prefix/path being browsed
	 *
	 * @return bool True if the object should be excluded, false otherwise
	 */
	public function should_be_excluded( string $current_prefix = '' ): bool {
		// Always exclude objects with empty keys
		if ( empty( $this->key ) ) {
			return true;
		}

		// Exclude folder markers (keys ending with '/')
		if ( substr( $this->key, - 1 ) === '/' ) {
			return true;
		}

		// If this object is the current prefix (folder), don't exclude it
		if ( $this->key === $current_prefix ) {
			return false;
		}

		// Exclude zero-size files (empty files)
		if ( $this->size === 0 ) {
			return true;
		}

		// Exclude system or hidden files
		$filename     = $this->get_filename();
		$hidden_files = [
			'.DS_Store',
			'Thumbs.db',
			'.htaccess',
			'.git',
			'.svn',
			'.tmp',
			'.gitignore',
			'.gitkeep',
			'desktop.ini',
			'Icon\r',
			'.localized',
			'__MACOSX',
			'.fseventsd',
			'.Spotlight-V100',
			'.Trashes',
			'._.DS_Store',
			'$RECYCLE.BIN',
		];

		$hidden_files = apply_filters( Runtime::hook( 's3_object_hidden_files' ), $hidden_files, $this->key, $current_prefix );

		if ( in_array( $filename, $hidden_files, true ) ) {
			return true;
		}

		// Exclude files that start with . (hidden files)
		if ( strpos( $filename, '.' ) === 0 && strlen( $filename ) > 1 ) {
			return true;
		}

		return false;
	}

	/**
	 * Get HTML data attributes for modal/JavaScript use
	 *
	 * @return string HTML data attributes string
	 */
	public function get_data_attributes(): string {
		$data_attrs = [
			'data-filename'           => esc_attr( $this->get_filename() ),
			'data-key'                => esc_attr( $this->get_key() ),
			'data-size-bytes'         => esc_attr( $this->get_size() ), // Raw bytes
			'data-size-formatted'     => esc_attr( $this->get_size( true ) ), // Formatted
			'data-modified'           => esc_attr( $this->get_last_modified() ), // Raw
			'data-modified-formatted' => esc_attr( $this->get_last_modified( true ) ), // Formatted
			'data-etag'               => esc_attr( $this->get_etag() ),
			'data-md5'                => esc_attr( $this->get_md5_checksum() ?: '' ),
			'data-is-multipart'       => $this->is_multipart() ? 'true' : 'false',
			'data-storage-class'      => esc_attr( $this->get_storage_class() ),
			'data-mime-type'          => esc_attr( $this->get_mime_type() ),
			'data-category'           => esc_attr( $this->get_category() ),
		];

		// Add multipart info if applicable
		if ( $this->is_multipart() ) {
			$multipart_info = $this->get_multipart_info();
			if ( $multipart_info ) {
				$data_attrs['data-part-count'] = esc_attr( (string) $multipart_info['part_count'] );
			}
		}

		return implode( ' ', array_map( function ( $key, $value ) {
			return sprintf( '%s="%s"', $key, $value );
		}, array_keys( $data_attrs ), $data_attrs ) );
	}

	/**
	 * Get object filename (without path)
	 *
	 * @return string
	 */
	public function get_filename(): string {
		return Filename::basename( $this->key );
	}
	/**
	 * Get object key
	 *
	 * @return string
	 */
	public function get_key(): string {
		return $this->key;
	}
	/**
	 * Get object size
	 *
	 * @param bool $formatted Whether to return formatted size or raw bytes
	 * @param int  $precision Number of decimal places for formatted size
	 *
	 * @return string|int Formatted size string or raw bytes
	 */
	public function get_size( bool $formatted = false, int $precision = 2 ) {
		return $formatted ? size_format( $this->size, $precision ) : $this->size;
	}
	/**
	 * Get last modified date
	 *
	 * @param bool   $formatted Whether to return formatted date or raw timestamp
	 * @param string $format    PHP date format for formatted output
	 *
	 * @return string Raw timestamp or formatted date
	 */
	public function get_last_modified( bool $formatted = false, string $format = 'Y-m-d H:i:s' ): string {
		if ( ! $formatted ) {
			return $this->last_modified;
		}

		// wp_date(), not date(): this is shown to an administrator, and
		// WordPress sets PHP's default timezone to UTC, so date() would
		// render every timestamp in UTC regardless of the timezone the
		// site is configured for.
		return empty( $this->last_modified ) ? '' : (string) wp_date( $format, strtotime( $this->last_modified ) );
	}
	/**
	 * Get MIME type
	 *
	 * @return string
	 */
	public function get_mime_type(): string {
		return Mime::of( $this->get_filename() );
	}
	/**
	 * Get file category (image, video, audio, document, archive, other)
	 *
	 * @return string
	 */
	public function get_category(): string {
		return Mime::category( $this->get_filename() );
	}

	/**
	 * Get ETag (already cleaned of quotes)
	 *
	 * @return string
	 */
	public function get_etag(): string {
		return $this->etag;
	}
	/**
	 * Get MD5 checksum with caveats
	 *
	 * @return string|null MD5 hash if available and reliable, null otherwise
	 */
	public function get_md5_checksum(): ?string {
		if ( empty( $this->etag ) ) {
			return null;
		}

		// For multipart uploads, return the composite hash part
		if ( $this->is_multipart() ) {
			$parts = explode( '-', $this->etag );
			return $parts[0] ?? null;
		}

		// For single-part uploads, ETag IS the MD5 (unless encrypted)
		return $this->etag;
	}
	/**
	 * Get storage class
	 *
	 * @return string
	 */
	public function get_storage_class(): string {
		return $this->storage_class;
	}
	/**
	 * Check if this is a multipart upload
	 *
	 * @return bool
	 */
	public function is_multipart(): bool {
		return ! empty( $this->etag ) && strpos( $this->etag, '-' ) !== false;
	}
	/**
	 * Get multipart information if applicable
	 *
	 * @return array|null Array with parts info if multipart, null otherwise
	 */
	public function get_multipart_info(): ?array {
		if ( ! $this->is_multipart() ) {
			return null;
		}

		$parts = explode( '-', $this->etag );
		if ( count( $parts ) === 2 && is_numeric( $parts[1] ) ) {
			return [
				'composite_hash' => $parts[0],
				'part_count'     => (int) $parts[1],
				'full_etag'      => $this->etag,
			];
		}

		return null;
	}
}
