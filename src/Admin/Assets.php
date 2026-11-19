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
	 * Handle for the shared configuration script.
	 *
	 * One per page regardless of how many browsers are on it, which is why it
	 * carries no instance-specific state beyond the identifiers.
	 */
	private const GLOBAL_CONFIG = 'browser-config';

	/**
	 * Admin pages that edit a single post.
	 */
	private const EDIT_HOOKS = [ 'post.php', 'post-new.php' ];

	/**
	 * Integration scripts, by the post type whose editor they belong to.
	 */
	// Named for the post type rather than the plugin: the handles and
	// filenames end up in page source and in a distributed ZIP, and neither
	// needs to carry someone else's trademark.
	private const INTEGRATIONS = [
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

		$config_handle = $this->enqueue_global_config();

		AssetFiles::enqueue_style( Runtime::handle( 'admin' ), __FILE__, 'css/admin.css' );
		Script::enqueue( Runtime::handle( 'connection' ), 'connection', [ $config_handle ] );
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
	 * Register and localize the shared configuration script.
	 *
	 * @return string Handle, for depending on.
	 */
	public function enqueue_global_config(): string {
		$handle = Runtime::handle( self::GLOBAL_CONFIG );

		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, false );
		}

		if ( ! wp_script_is( $handle ) ) {
			wp_enqueue_script( $handle, false, [], '1.0', true );
		}

		if ( wp_script_is( $handle, 'localized' ) ) {
			return $handle;
		}

		wp_localize_script( $handle, 'S3BrowserGlobalConfig', $this->config->filter(
			'browser_global_config',
			[
				'providerId'        => $this->config->hook_suffix(),
				'providerName'      => $this->config->provider_name,

				// Which provider, apart from which instance: the id above
				// carries the context too. The script needs the bare type to
				// show CORS in the shape that provider's own console takes.
				'providerType'      => $this->config->provider_id,
				'baseUrl'           => admin_url( 'media-upload.php' ),
				// Where to post an insert request, and a token proving it came
				// from this site's own browser. The frame the browser runs in
				// can have an opaque origin, so the receiving page cannot
				// identify the sender by origin alone.
				'adminOrigin'       => $this->admin_origin(),
				'insertToken'       => wp_create_nonce( 's3_browser_insert' ),
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
		) );

		return $handle;
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
		Script::enqueue( Runtime::handle( 'browser-host' ), 'host', [ 'media-views', $this->enqueue_global_config() ] );
	}

	/**
	 * Load the browser's own styles and scripts, then configure them.
	 *
	 * @return void
	 */
	private function enqueue_browser(): void {
		$config_handle = $this->enqueue_global_config();

		AssetFiles::enqueue_style( Runtime::handle( 'browser' ), __FILE__, 'css/browser.css' );
		AssetFiles::enqueue_style( Runtime::handle( 'upload' ), __FILE__, 'css/upload.css' );

		$this->browser_loaded = Script::enqueue( Runtime::handle( 'browser' ), 'browser', [ $config_handle ] );

		$this->localize_browser();
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

		Script::enqueue( Runtime::handle( $handle ), $entry, [ $this->enqueue_global_config() ] );
	}

	/**
	 * Hand the browser script its configuration and translations.
	 *
	 * @return void
	 */
	private function localize_browser(): void {
		if ( ! $this->browser_loaded ) {
			return;
		}

		wp_localize_script( Runtime::handle( 'browser' ), 's3BrowserConfig', $this->config->filter(
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
