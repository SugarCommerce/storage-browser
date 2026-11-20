<?php
/**
 * Browser Assets
 *
 * Decides what the browser loads on a given admin page, and hands its
 * JavaScript the configuration it needs.
 *
 * @package     SugarCommerce\StorageBrowser\Admin
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Admin;

use SugarCommerce\StorageBrowser\Utils\Runtime;
use SugarCommerce\StorageBrowser\Rest\Controller;
use SugarCommerce\Storage\Files\Mime;
use SugarCommerce\S3\Bucket;
use SugarCommerce\Plugin\Assets as AssetFiles;

/**
 * Class Assets
 */
class Assets {

	/**
	 * The page global the scripts find their configuration in.
	 *
	 * Shared by every build on purpose; its keys are not. Each script's entry
	 * is keyed by the script's own handle, which Runtime::handle() names for
	 * the build, so two plugins' copies on one page each read their own REST
	 * URL, nonce and strings. A script finds its entry from the id WordPress
	 * gives its element, "{handle}-js" (assets/js/lib/config.js).
	 */
	public const string REGISTRY = 'SugarCommerceStorageBrowser';

	/**
	 * Admin pages that edit a single post.
	 */
	private const array EDIT_HOOKS = [ 'post.php', 'post-new.php' ];

	/**
	 * Integration scripts, by the post type whose editor they belong to.
	 */
	// Named for the post type rather than the plugin: the handles and
	// filenames end up in page source and in a distributed ZIP, and neither
	// needs to carry someone else's trademark.
	private const array INTEGRATIONS = [
		'product'  => [ 'browser-wc', 'wc' ],
		'download' => [ 'browser-edd', 'edd' ],
	];

	/**
	 * Whether the browser scripts made it onto this page.
	 *
	 * @var bool
	 */
	private bool $browser_loaded = false;

	/**
	 * What has been handed to which script in this request: handle => parts.
	 *
	 * Static because two browsers of one build share a script, and the first
	 * to load it configures it, as before.
	 *
	 * @var array<string, array<string, true>>
	 */
	private static array $configured = [];

	/**
	 * The page's configuration, once built.
	 *
	 * @var array|null
	 */
	private ?array $global = null;

	/**
	 * Build the asset loader for one browser instance.
	 *
	 * @param Config     $config      Browser configuration.
	 * @param Screen     $screen      Screen tests.
	 * @param Controller $rest        REST controller, for the route the JavaScript calls.
	 * @param callable   $admin_hooks Resolver for the admin pages this browser loads on.
	 * @param callable   $host_hooks  Resolver for the admin pages that open a media
	 *                                frame of their own and want the browser in it.
	 */
	public function __construct(
		private Config $config,
		private Screen $screen,
		private Controller $rest,
		private $admin_hooks,
		private $host_hooks = null
	) {
	}

	/**
	 * Load the admin stylesheet on its own
	 *
	 * The settings screen renders before there are credentials to build a
	 * browser from, and it is the browser that normally brings this in -- so
	 * the validation panel a consumer draws while unconfigured had no styles
	 * at all. Static, because at that point there is no instance.
	 *
	 * @return void
	 */
	public static function enqueue_admin_style(): void {
		AssetFiles::enqueue_style( Runtime::handle( 'admin' ), __FILE__, 'css/admin.css' );
	}

	/**
	 * Load the settings-screen assets.
	 *
	 * @param string $hook Current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue_settings_assets( string $hook ): void {
		if ( ! $this->matches_admin_hook( $hook ) || ! $this->config->user_is_allowed() ) {
			return;
		}

		AssetFiles::enqueue_style( Runtime::handle( 'admin' ), __FILE__, 'css/admin.css' );
		$this->enqueue_script( Runtime::handle( 'connection' ), 'connection' );
	}

	/**
	 * The browser script's handle, which also marks the browser it draws.
	 *
	 * @return string
	 */
	public static function browser_handle(): string {
		return Runtime::handle( 'browser' );
	}

	/**
	 * Load whatever this admin page needs.
	 *
	 * @param string $hook Current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue_browser_assets( string $hook ): void {
		if ( ! $this->config->user_is_allowed() ) {
			return;
		}

		// The media upload iframe hosts the browser -- but only in this
		// browser's own tab. Every tab of the media frame is that one hook,
		// so without asking which tab it is, two browsers on a site (two
		// plugins, or two contexts) each loaded their scripts into the
		// other's tab, and whichever ran first drove both.
		if ( 'media-upload-popup' === $hook ) {
			if ( $this->is_own_tab() ) {
				$this->enqueue_browser();
			}

			return;
		}

		// A custom admin page hosts the browser itself; a post editor only
		// needs the script that opens it.
		if ( $this->matches_admin_hook( $hook ) ) {
			$this->enqueue_browser();

			return;
		}

		if ( in_array( $hook, self::EDIT_HOOKS, true ) ) {
			$this->enqueue_integration();

			return;
		}

		// A page of somebody's own that opens a media frame. It needs what a
		// post editor needs -- the script that adds the tab -- but there is
		// no post type to choose one by, so it gets the one that asks the
		// page itself where a file goes.
		if ( $this->matches_host_hook( $hook ) ) {
			$this->enqueue_host_integration();
		}
	}

	/**
	 * What every script of this browser is told: which instance, where its
	 * routes are, the token an insert request carries, upload limits.
	 *
	 * Filtered by `browser_global_config`; built once per instance.
	 *
	 * @return array
	 */
	public function global_config(): array {
		return $this->global ??= (array) $this->config->filter(
			'browser_global_config',
			[
				'providerId'        => $this->config->hook_suffix(),
				'providerName'      => $this->config->provider_name,

				// Which provider, apart from which instance: the id above
				// carries the context too. The script needs the bare type to
				// show CORS in the shape that provider's own console takes.
				'providerType'      => $this->config->provider_id,

				// The media tab, named for the build as well as the
				// instance: what the frame opens and what a link stays in.
				'tabId'             => $this->config->tab_id(),
				'baseUrl'           => admin_url( 'media-upload.php' ),
				// Where to post an insert request, and a token proving it came
				// from this site's own browser. The frame the browser runs in
				// can have an opaque origin, so the receiving page cannot
				// identify the sender by origin alone. The nonce's action is
				// the build's, so another plugin's copy on the same page holds
				// a different token and never acts on this browser's request.
				'adminOrigin'       => $this->admin_origin(),
				'insertToken'       => wp_create_nonce( Runtime::key( 'insert' ) ),
				// The integration scripts run on the edit screen, not inside
				// the browser, so they never see the browser's own strings.
				'i18n'              => [
					/* translators: %d: number of files already present */
					'alreadyAdded' => __( '%d of the selected files are already in this list and were skipped.', 'sugarcommerce' ),
					/* translators: {bucket}: a bucket name; keep the braces. */
					'checking'     => __( 'Checking "{bucket}": listing, writing, reading back, deleting, large uploads and uploads from this site…', 'sugarcommerce' ),
				],
				// Files from this size up go in parts, each with its own
				// checksum, and resume when dropped again; past one PUT's
				// limit nothing else will do.
				'multipart'         => [
					'threshold'    => 64 * MB_IN_BYTES,
					'singlePutMax' => Bucket::MAX_PUT_SIZE,
					'maxSize'      => Bucket::MAX_OBJECT_SIZE,
					'concurrency'  => 4,
					'signBatch'    => 20,
					'retries'      => 4,
				],
				'restUrl'           => esc_url_raw( rest_url( $this->rest->route_path() ) ),
				'restNonce'         => wp_create_nonce( 'wp_rest' ),
				'defaultBucket'     => $this->config->default_bucket,
				'allowedExtensions' => Mime::allowed_extensions( $this->config->context ),
				'allowedMimeTypes'  => Mime::allowed_types( $this->config->context ),
			],
			$this->config->provider_id
		);
	}

	/**
	 * The inline script that puts part of a script's configuration in the
	 * registry, under the script's own handle.
	 *
	 * @param string $handle The script's handle.
	 * @param string $part   global or browser.
	 * @param array  $value  What it is told.
	 *
	 * @return string
	 */
	public static function entry_script( string $handle, string $part, array $value ): string {
		return sprintf(
			'window.%1$s=window.%1$s||{};window.%1$s[%2$s]=window.%1$s[%2$s]||{};window.%1$s[%2$s][%3$s]=%4$s;',
			self::REGISTRY,
			(string) wp_json_encode( $handle ),
			(string) wp_json_encode( $part ),
			(string) wp_json_encode( $value )
		);
	}

	/**
	 * Load one of the built scripts with the page's configuration in front
	 * of it.
	 *
	 * @param string   $handle Its handle.
	 * @param string   $entry  Its entry in assets/build.
	 * @param string[] $deps   What it needs besides its imports.
	 *
	 * @return bool Whether it loaded.
	 */
	private function enqueue_script( string $handle, string $entry, array $deps = [] ): bool {
		if ( ! AssetFiles::enqueue_built_script( $handle, __FILE__, $entry, $deps ) ) {
			return false;
		}

		$this->configure( $handle, 'global', $this->global_config() );

		return true;
	}

	/**
	 * Hand a script part of its configuration, once.
	 *
	 * @param string $handle The script's handle.
	 * @param string $part   global or browser.
	 * @param array  $value  What it is told.
	 *
	 * @return void
	 */
	private function configure( string $handle, string $part, array $value ): void {
		if ( isset( self::$configured[ $handle ][ $part ] ) ) {
			return;
		}

		self::$configured[ $handle ][ $part ] = true;

		wp_add_inline_script( $handle, self::entry_script( $handle, $part, $value ), 'before' );
	}

	/**
	 * The origin the admin is served from.
	 *
	 * Used as the postMessage target, so an insert request reaches the page
	 * that opened the browser and nowhere else.
	 *
	 * @return string
	 */
	private function admin_origin(): string {
		$parts = wp_parse_url( admin_url() );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'];

		return empty( $parts['port'] ) ? $origin : $origin . ':' . $parts['port'];
	}

	/**
	 * Whether the media frame is showing this browser's tab.
	 *
	 * @return bool
	 */
	private function is_own_tab(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which tab is showing, not an action.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return $this->config->tab_id() === $tab;
	}

	/**
	 * Whether a hook is one of the admin pages this browser was told about.
	 *
	 * @param string $hook Hook suffix to test.
	 *
	 * @return bool
	 */
	private function matches_admin_hook( string $hook ): bool {
		return in_array( $hook, ( $this->admin_hooks )(), true );
	}

	/**
	 * Whether an admin page is one that hosts the browser in its own frames.
	 *
	 * @param string $hook Hook suffix to test.
	 *
	 * @return bool
	 */
	private function matches_host_hook( string $hook ): bool {
		return null !== $this->host_hooks && in_array( $hook, ( $this->host_hooks )(), true );
	}

	/**
	 * Load the script that opens the browser from a page that is not a post
	 * editor.
	 *
	 * @return void
	 */
	private function enqueue_host_integration(): void {
		$this->enqueue_script( Runtime::handle( 'browser-host' ), 'host', [ 'media-views' ] );
	}

	/**
	 * Load the browser's own styles and scripts, then configure them.
	 *
	 * @return void
	 */
	private function enqueue_browser(): void {
		AssetFiles::enqueue_style( Runtime::handle( 'browser' ), __FILE__, 'css/browser.css' );
		AssetFiles::enqueue_style( Runtime::handle( 'upload' ), __FILE__, 'css/upload.css' );

		$this->browser_loaded = $this->enqueue_script( self::browser_handle(), 'browser' );

		$this->configure_browser();
	}

	/**
	 * Load the script that opens the browser from a post editor.
	 *
	 * @return void
	 */
	private function enqueue_integration(): void {
		$post_type = (string) get_post_type();

		if ( ! isset( self::INTEGRATIONS[ $post_type ] ) || ! $this->screen->allows_post_type( $post_type ) ) {
			return;
		}

		[ $handle, $entry ] = self::INTEGRATIONS[ $post_type ];

		$this->enqueue_script( Runtime::handle( $handle ), $entry );
	}

	/**
	 * Hand the browser script its own configuration and translations.
	 *
	 * @return void
	 */
	private function configure_browser(): void {
		if ( ! $this->browser_loaded ) {
			return;
		}

		$this->configure( self::browser_handle(), 'browser', (array) $this->config->filter(
			'browser_config',
			[
				'postId'   => $this->screen->current_post_id(),

				// Whether a host page opened this frame. Every move within
				// the browser is a new request, and each has to say so
				// again or the next folder would be refused.
				'host'     => $this->screen->is_host_request(),
				'autoLoad' => apply_filters( Runtime::hook( 'browser_auto_load' ), false, $this->config->provider_id ),
				'i18n'     => $this->config->filter( 'browser_translations', Translations::all(), $this->config->provider_id ),

				// Drawn here, so the scripts insert them rather than build
				// markup for an icon set they cannot see.
				'icons'    => Icon::for_scripts(),
			],
			$this->config->provider_id
		) );
	}
}
