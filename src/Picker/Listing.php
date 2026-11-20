<?php
/**
 * What the picker lists
 *
 * @package     SugarCommerce\StorageBrowser\Picker
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Picker;

use SugarCommerce\S3\Address;
use SugarCommerce\StorageBrowser\Client;
use SugarCommerce\StorageBrowser\Models\S3Object;
use SugarCommerce\StorageBrowser\Utils\Directory;
use WP_Error;

/**
 * A folder for the picker, as JSON: its folders and files, or the files
 * anywhere beneath it whose names hold the words searched for.
 *
 * Storage has no search. A search lists everything under the folder, a
 * thousand keys a request, and keeps the names that match -- at most
 * SEARCH_PAGES requests before it hands back what it found with a token to
 * carry on from, so a bucket of millions of keys costs a few seconds, not
 * a timeout.
 */
final class Listing {

	/**
	 * Keys a request.
	 */
	public const int PAGE = 1000;

	/**
	 * Requests one search makes before it stops to answer.
	 */
	public const int SEARCH_PAGES = 10;

	/**
	 * Matches one search answer carries before it stops to answer.
	 */
	public const int SEARCH_MATCHES = 200;

	/**
	 * One page of a folder, or of a search beneath it.
	 *
	 * @param Client $client The browser's client.
	 * @param string $bucket Bucket.
	 * @param string $prefix The folder; '' for the top.
	 * @param string $search Words a name must hold; '' to list the folder.
	 * @param string $token  Where the previous page stopped.
	 *
	 * @return array{bucket: string, prefix: string, search: string, folders: array<int, array{name: string, prefix: string}>, objects: array<int, array<string, mixed>>, truncated: bool, continuation_token: string}|WP_Error
	 */
	public static function browse( Client $client, string $bucket, string $prefix = '', string $search = '', string $token = '' ): array|WP_Error {
		$prefix = '' === trim( $prefix, '/' ) ? '' : trim( $prefix, '/' ) . '/';
		$search = trim( $search );

		return '' === $search
			? self::folder( $client, $bucket, $prefix, $token )
			: self::search( $client, $bucket, $prefix, $search, $token );
	}

	/**
	 * One level of a folder.
	 *
	 * @param Client $client The client.
	 * @param string $bucket Bucket.
	 * @param string $prefix The folder.
	 * @param string $token  Where the previous page stopped.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private static function folder( Client $client, string $bucket, string $prefix, string $token ): array|WP_Error {
		// Fresh, not from the browser's hour-long cache: a picker is opened
		// to choose a file that has often only just been uploaded.
		$page = $client->get_objects( $bucket, self::PAGE, $prefix, '/', $token, false );

		if ( is_wp_error( $page ) ) {
			return $page;
		}

		return [
			'bucket'             => $bucket,
			'prefix'             => $prefix,
			'search'             => '',
			'folders'            => array_map(
				static fn( string $folder ): array => [
					'name'   => Directory::name( $folder ),
					'prefix' => $folder,
				],
				$page['prefixes']
			),
			'objects'            => array_map( static fn( S3Object $object ): array => self::object( $bucket, $object ), $page['objects'] ),
			'truncated'          => (bool) $page['truncated'],
			'continuation_token' => (string) $page['continuation_token'],
		];
	}

	/**
	 * Every file beneath a folder whose name holds the words, a few pages
	 * at a time.
	 *
	 * @param Client $client The client.
	 * @param string $bucket Bucket.
	 * @param string $prefix The folder.
	 * @param string $search The words.
	 * @param string $token  Where the previous answer stopped.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private static function search( Client $client, string $bucket, string $prefix, string $search, string $token ): array|WP_Error {
		$found = [];
		$pages = 0;

		do {
			// Not cached: a scan's pages are read once, and ten of them would
			// sit in the options table for an hour for nothing.
			$page = $client->get_objects( $bucket, self::PAGE, $prefix, '', $token, false );

			if ( is_wp_error( $page ) ) {
				return $page;
			}

			foreach ( $page['objects'] as $object ) {
				if ( self::matches( $object->get_filename(), $search ) ) {
					$found[] = self::object( $bucket, $object );
				}
			}

			$token = $page['truncated'] ? (string) $page['continuation_token'] : '';
			++$pages;
		} while ( '' !== $token && $pages < self::SEARCH_PAGES && count( $found ) < self::SEARCH_MATCHES );

		return [
			'bucket'             => $bucket,
			'prefix'             => $prefix,
			'search'             => $search,
			'folders'            => [],
			'objects'            => $found,
			'truncated'          => '' !== $token,
			'continuation_token' => $token,
		];
	}

	/**
	 * Whether a name holds every word searched for, ignoring case.
	 *
	 * @param string $name   A file's name.
	 * @param string $search The words.
	 *
	 * @return bool
	 */
	public static function matches( string $name, string $search ): bool {
		foreach ( preg_split( '/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY ) ?: [] as $word ) {
			if ( false === mb_stripos( $name, $word ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A file, as the picker shows and returns it.
	 *
	 * @param string   $bucket Bucket.
	 * @param S3Object $object The file.
	 *
	 * @return array{key: string, name: string, size: int, size_label: string, modified: string, modified_label: string, mime_type: string, category: string, address: string}
	 */
	public static function object( string $bucket, S3Object $object ): array {
		return [
			'key'            => $object->get_key(),
			'name'           => $object->get_filename(),
			'size'           => (int) $object->get_size(),
			'size_label'     => (string) $object->get_size( true ),
			'modified'       => $object->get_last_modified(),
			'modified_label' => $object->get_last_modified( true, (string) get_option( 'date_format', 'Y-m-d' ) ),
			'mime_type'      => $object->get_mime_type(),
			'category'       => $object->get_category(),
			'address'        => Address::to( $bucket, $object->get_key() ),
		];
	}
}
