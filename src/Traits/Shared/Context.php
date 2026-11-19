<?php
/**
 * Context Trait
 *
 * @package     SugarCommerce\StorageBrowser
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Traits\Shared;

/**
 * Trait Context
 *
 * Which integration an instance serves -- 'edd', 'woocommerce' -- so two on
 * one site keep their own caches and upload rules.
 */
trait Context {

	/**
	 * The context, or null.
	 *
	 * @var string|null
	 */
	private ?string $context = null;

	/**
	 * Set the context.
	 *
	 * @param string|null $context Context identifier.
	 *
	 * @return self
	 */
	public function set_context( ?string $context ): self {
		$this->context = $context;

		return $this;
	}

	/**
	 * The context, or null.
	 *
	 * @return string|null
	 */
	public function get_context(): ?string {
		return $this->context;
	}

	/**
	 * Whether there is one.
	 *
	 * @return bool
	 */
	public function has_context(): bool {
		return null !== $this->context;
	}
}
