<?php
/**
 * Debug Trait
 *
 * @package     SugarCommerce\StorageBrowser
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Traits\Shared;

/**
 * Trait Debug
 *
 * Off by default. On, every refusal from storage is reported -- what was
 * asked, and storage's own code and words -- to a callback, or to the PHP
 * error log. Nothing reported carries a header, so no signature or key can
 * reach it.
 */
trait Debug {

	/**
	 * Whether to report.
	 *
	 * @var bool
	 */
	private bool $debug = false;

	/**
	 * Where reports go instead of the error log.
	 *
	 * @var callable|null
	 */
	private $debug_callback = null;

	/**
	 * Report something, when debugging is on.
	 *
	 * @param string $title What happened.
	 * @param mixed  $data  Detail.
	 *
	 * @return void
	 */
	protected function debug( string $title, $data = null ): void {
		if ( ! $this->debug ) {
			return;
		}

		if ( is_callable( $this->debug_callback ) ) {
			call_user_func( $this->debug_callback, $title, $data );

			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions -- debug output, asked for.
		error_log( '[S3 DEBUG] ' . $title . ( null === $data ? '' : ': ' . print_r( $data, true ) ) );
	}

	/**
	 * Turn reporting on or off.
	 *
	 * @param bool $enable Whether to report.
	 *
	 * @return self
	 */
	public function set_debug( bool $enable ): self {
		$this->debug = $enable;

		return $this;
	}

	/**
	 * Send reports to a callback rather than the error log.
	 *
	 * @param callable $callback Receives the title and the detail.
	 *
	 * @return self
	 */
	public function set_debug_callback( callable $callback ): self {
		$this->debug_callback = $callback;

		return $this;
	}
}
