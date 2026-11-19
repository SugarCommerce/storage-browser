<?php
/**
 * Validate
 *
 * @package     SugarCommerce\StorageBrowser
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Utils;

/**
 * Class Validate
 *
 * The rules a new folder's name is held to. A renamed file's are
 * Files\Filename::problem().
 */
class Validate {

	/**
	 * Validate folder name with comprehensive checks including space support
	 *
	 * @param string $folder_name Folder name to validate
	 *
	 * @return array Validation result with 'valid' boolean and 'message' string
	 */
	public static function folder_name( string $folder_name ): array {
		// Check length
		if ( strlen( $folder_name ) === 0 ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name cannot be empty', 'sugarcommerce' ),
			];
		}

		if ( strlen( $folder_name ) > 63 ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name cannot exceed 63 characters', 'sugarcommerce' ),
			];
		}

		// Check for valid characters (now includes spaces)
		if ( ! preg_match( '/^[a-zA-Z0-9._\s-]+$/', $folder_name ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name can only contain letters, numbers, spaces, dots, hyphens, and underscores', 'sugarcommerce' ),
			];
		}

		// Cannot start or end with dot, hyphen, or space
		$first_char = $folder_name[0];
		$last_char  = $folder_name[ strlen( $folder_name ) - 1 ];

		if ( in_array( $first_char, [ '.', '-', ' ' ], true ) || in_array( $last_char, [ '.', '-', ' ' ], true ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name cannot start or end with dots, hyphens, or spaces', 'sugarcommerce' ),
			];
		}

		// Cannot contain consecutive dots
		if ( str_contains( $folder_name, '..' ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name cannot contain consecutive dots', 'sugarcommerce' ),
			];
		}

		// Cannot contain multiple consecutive spaces
		if ( preg_match( '/\s{2,}/', $folder_name ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name cannot contain multiple consecutive spaces', 'sugarcommerce' ),
			];
		}

		// Check for reserved system names
		$reserved = [
			'CON',
			'PRN',
			'AUX',
			'NUL',
			'COM1',
			'COM2',
			'COM3',
			'COM4',
			'COM5',
			'COM6',
			'COM7',
			'COM8',
			'COM9',
			'LPT1',
			'LPT2',
			'LPT3',
			'LPT4',
			'LPT5',
			'LPT6',
			'LPT7',
			'LPT8',
			'LPT9',
		];

		if ( in_array( strtoupper( $folder_name ), $reserved, true ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name cannot be a reserved system name', 'sugarcommerce' ),
			];
		}

		return [
			'valid'   => true,
			'message' => '',
		];
	}
}
