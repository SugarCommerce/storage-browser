<?php
/**
 * "Choose from your bucket"
 *
 * @package     SugarCommerce\StorageBrowser\Picker
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Picker;

use Closure;
use SugarCommerce\Plugin\Assets as AssetFiles;
use SugarCommerce\StorageBrowser\Admin\Assets;
use SugarCommerce\StorageBrowser\Admin\Config;
use SugarCommerce\StorageBrowser\Admin\Icon;
use SugarCommerce\StorageBrowser\Rest\Controller;
use SugarCommerce\StorageBrowser\Utils\Runtime;

/**
 * A dialog that browses a browser's buckets and hands back one file, for any
 * field on any admin page.
 *
 *     $picker = $browser->picker();
 *
 *     // A button beside an input of your own; the input gets s3://bucket/key.
 *     echo $picker->button( 'my-file-url', [ 'name' => 'my-file-name', 'accept' => [ 'zip', 'wav' ] ] );
 *
 *     // Or in JavaScript, once enqueue() has run on the page, through this
 *     // build's entry -- Picker::handle() names it.
 *     const file = await window.SugarCommerceStorageBrowser[ handle ].open();
 *
 * The same picker is offered to other packages as a "file source" --
 * {@see source()} -- in a shape that names no class of this package, so a
 * field library can show the button without depending on this one.
 */
final class Picker {

	/**
	 * The pickers already handed to the script in this request, by id.
	 *
	 * @var array<string, true>
	 */
	private static array $added = [];

	/**
	 * Build a picker for one browser.
	 *
	 * @param Config     $config  The browser's configuration.
	 * @param Controller $rest    Its REST routes, which the picker calls.
	 * @param Closure    $buckets Resolves the buckets the browser is confined to.
	 */
	public function __construct(
		private Config $config,
		private Controller $rest,
		private Closure $buckets
	) {
	}

	/**
	 * This picker's id: the browser's, so two browsers on a page are two
	 * sources, and the build's, so two plugins bundling this library for the
	 * same provider are two sources as well. It is the picker's key in
	 * `window.sugarcommerceFileSources`, which every plugin's copy writes to.
	 *
	 * @return string `sugarcommerce_storage_browser_r2_shop` unprefixed.
	 */
	public function id(): string {
		return Runtime::key( $this->config->hook_suffix() );
	}

	/**
	 * What the button and the dialog are called.
	 *
	 * @return string
	 */
	public function label(): string {
		return (string) $this->config->filter(
			'picker_label',
			/* translators: %s: the storage provider's name, such as Cloudflare R2. */
			sprintf( __( 'Choose from %s', 'sugarcommerce' ), $this->config->provider_name ),
			$this->config->provider_id
		);
	}

	/**
	 * The script's handle.
	 *
	 * @return string
	 */
	public static function handle(): string {
		return Runtime::handle( 'picker' );
	}

	/**
	 * What the script is told about this picker.
	 *
	 * @return array{label: string, restUrl: string, nonce: string, defaultBucket: string, buckets: array<int, array{name: string}>, icons: array<string, string>}
	 */
	public function config(): array {
		$confined = array_values( array_unique( array_filter( array_map( 'strval', ( $this->buckets )() ) ) ) );

		return (array) $this->config->filter(
			'picker_config',
			[
				'label'         => $this->label(),
				'restUrl'       => esc_url_raw( rest_url( $this->rest->route_path() ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'defaultBucket' => $this->config->default_bucket,
				// Known here when the browser is confined; asked for over
				// REST otherwise, which costs a ListBuckets.
				'buckets'       => array_map( static fn( string $name ): array => [ 'name' => $name ], $confined ),
				'icons'         => Icon::for_picker(),
			],
			$this->config->provider_id
		);
	}

	/**
	 * Load the picker on this page, for this browser.
	 *
	 * Safe to call more than once, and from more than one browser: each adds
	 * itself, under its id, to the `pickers` of this build's entry in
	 * `window.SugarCommerceStorageBrowser` -- the entry keyed by the script's
	 * handle, so another plugin's copy on the page keeps its own.
	 *
	 * @return bool Whether it is loaded: false when the user may not use the browser.
	 */
	public function enqueue(): bool {
		if ( ! $this->config->user_is_allowed() ) {
			return false;
		}

		AssetFiles::enqueue_style( Runtime::handle( 'picker' ), __FILE__, 'css/picker.css' );

		if ( ! wp_script_is( self::handle(), 'enqueued' ) && ! AssetFiles::enqueue_built_script( self::handle(), __FILE__, 'picker' ) ) {
			return false;
		}

		if ( empty( self::$added[ $this->id() ] ) ) {
			self::$added[ $this->id() ] = true;

			wp_add_inline_script(
				self::handle(),
				sprintf(
					'window.%1$s=window.%1$s||{};window.%1$s[%2$s]=window.%1$s[%2$s]||{};window.%1$s[%2$s].pickers=window.%1$s[%2$s].pickers||{};window.%1$s[%2$s].pickers[%3$s]=%4$s;',
					Assets::REGISTRY,
					(string) wp_json_encode( self::handle() ),
					(string) wp_json_encode( $this->id() ),
					(string) wp_json_encode( $this->config() )
				),
				'before'
			);
		}

		return true;
	}

	/**
	 * A button that fills an input with the chosen file.
	 *
	 * @param string $input The input's id.
	 * @param array  $args  {
	 *     Optional.
	 *
	 *     @type string   $name    An input's id for the file's name, filled when it is empty.
	 *     @type string   $returns What the input gets: address (s3://bucket/key, the default), path (bucket/key) or key.
	 *     @type string[] $accept  Extensions that may be chosen; empty for any.
	 *     @type string   $label   The button's words; the picker's label by default.
	 *     @type string   $class   The button's classes. Default 'button'.
	 *     @type string   $bucket  The bucket to open on.
	 *     @type string   $prefix  The folder to open on.
	 * }
	 *
	 * @return string The button's markup; '' when the user may not use the browser.
	 */
	public function button( string $input, array $args = [] ): string {
		if ( ! $this->enqueue() ) {
			return '';
		}

		$attributes = [
			'type'                         => 'button',
			'class'                        => (string) ( $args['class'] ?? 'button' ),
			// The build's handle: each copy of the script attaches only
			// the buttons its own build drew.
			'data-storage-picker'          => self::handle(),
			'data-storage-picker-source'   => $this->id(),
			'data-storage-picker-target'   => '#' . $input,
			'data-storage-picker-name'     => empty( $args['name'] ) ? '' : '#' . $args['name'],
			'data-storage-picker-returns'  => self::returns( (string) ( $args['returns'] ?? 'address' ) ),
			'data-storage-picker-accept'   => implode( ',', array_map( 'strval', (array) ( $args['accept'] ?? [] ) ) ),
			'data-storage-picker-bucket'   => (string) ( $args['bucket'] ?? '' ),
			'data-storage-picker-prefix'   => (string) ( $args['prefix'] ?? '' ),
			'aria-controls'                => $input,
		];

		$html = '';

		foreach ( array_filter( $attributes, static fn( string $value ): bool => '' !== $value ) as $name => $value ) {
			$html .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}

		return sprintf(
			'<button%s>%s<span>%s</span></button>',
			$html,
			Icon::render( 'bucket', 'sc-storage-picker__button-icon' ),
			esc_html( (string) ( $args['label'] ?? $this->label() ) )
		);
	}

	/**
	 * This picker as a file source another package can offer.
	 *
	 * Plain values only -- no object of this package -- so the package
	 * offering it needs nothing of this one: an id, a label, an icon, a
	 * callable that loads the script, which then answers to
	 * `window.sugarcommerceFileSources[ id ].pick( { accept, returns } )`,
	 * and the schemes its addresses use, so a field that cleans a URL keeps
	 * an `s3://bucket/key` it would otherwise drop.
	 *
	 * @return array{id: string, label: string, icon: string, enqueue: callable(): bool, protocols: string[]}
	 */
	public function source(): array {
		return [
			'id'        => $this->id(),
			'label'     => $this->label(),
			'icon'      => Icon::render( 'bucket' ),
			'enqueue'   => [ $this, 'enqueue' ],
			'protocols' => [ 's3' ],
		];
	}

	/**
	 * What a field may be given: one of the three, or the address.
	 *
	 * @param string $returns What was asked for.
	 *
	 * @return string address, path or key.
	 */
	public static function returns( string $returns ): string {
		return in_array( $returns, [ 'address', 'path', 'key' ], true ) ? $returns : 'address';
	}
}
