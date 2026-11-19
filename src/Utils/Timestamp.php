<?php
/**
 * Timestamp Utility Class
 *
 * Simple timestamp operations for S3 operations.
 *
 * @package     SugarCommerce\StorageBrowser\Utils
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Utils;

/**
 * Class Timestamp
 *
 * Simple timestamp operations
 */
class Timestamp {

	/**
	 * Get timestamp for X minutes from now
	 *
	 * @param int $minutes Minutes from now
	 *
	 * @return int Future timestamp
	 */
	public static function in_minutes( int $minutes ): int {
		return time() + ( $minutes * 60 );
	}
}
