<?php
/**
 * Media Library Tab
 *
 * The browser as it appears inside WordPress's media modal: the tab itself,
 * and everything rendered in the iframe behind it.
 *
 * @package     SugarCommerce\StorageBrowser\Admin
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Admin;

use SugarCommerce\StorageBrowser\Client;
use SugarCommerce\StorageBrowser\Tables\Buckets;
use SugarCommerce\StorageBrowser\Tables\Objects;
use Closure;
use Exception;

/**
 * Class MediaLibrary
 */
class MediaLibrary {

	/*
	 * The nonce warnings below are read-only navigation state. This screen
	 * renders the bucket and prefix the query string names -- once the
	 * browser's capability and allow-list have said yes, since media-upload.php
	 * only asks upload_files -- and changes nothing; every operation that does
	 * change something goes through the REST controller, which checks a
	 * capability and a bucket allow-list on every route. A nonce here would protect against a cross-site request
	 * that causes a listing to be displayed to the person who was already
	 * looking at listings.
	 *
	 * phpcs:disable WordPress.Security.NonceVerification.Recommended
	 */

	/**
	 * Build the media tab for one browser instance.
	 *
	 * @param Config $config Browser configuration.
	 * @param Screen $screen Screen tests.
	 * @param Assets $assets Asset loader.
	 * @param Client       $client          Client the listings read through.
	 * @param Closure|null $allowed_buckets Resolver for the bucket allow-list;
	 *                                      empty means any bucket.
	 */
	public function __construct(
		private Config $config,
		private Screen $screen,
		private Assets $assets,
		private Client $client,
		private ?Closure $allowed_buckets = null
	) {
	}

	/**
	* Add the S3 tab to the media uploader
	*
	* @param array $tabs Current media uploader tabs
	*
	* @return array Modified tabs array
	*/
	public function add_media_tab( array $tabs ): array {
		// Check if this tab should be shown for the current context
		if ( ! $this->should_show_tab() ) {
			return $tabs;
		}

		$tabs[ $this->config->tab_id() ] = $this->config->provider_name;

		return $tabs;
	}

	/**
	* Check if the S3 tab should be shown for the current context
	*
	* @return bool True if tab should be shown, false otherwise
	*/
	private function should_show_tab(): bool {
		// Check if user has the required capability
		if ( ! $this->config->user_is_allowed() ) {
			return false;
		}

		// Check context and post type restrictions
		if ( ! $this->screen->allows_current_post() ) {
			return false;
		}

		// Don't add in specific contexts
		if ( wp_script_is( 'fes_form' ) || wp_script_is( 'cfm_form' ) ) {
			return false;
		}

		return true;
	}

	/**
	* Add media view strings for all contexts
	*
	* @param array $strings Current media view strings
	*
	* @return array Modified strings array
	*/
	public function add_media_view_strings( array $strings ): array {
		// Add our tab to all media modals
		if ( ! isset( $strings['tabs'] ) ) {
			$strings['tabs'] = [];
		}

		$strings['tabs'][ $this->config->tab_id() ] = $this->config->provider_name;

		return $strings;
	}

	/**
	* Handle the media tab content
	*
	* @return void
	*/
	public function handle_media_tab(): void {
		// media-upload.php lets anybody with upload_files open a tab by its
		// name, whatever capability this browser was given.
		if ( ! $this->config->user_is_allowed() ) {
			wp_die( esc_html__( 'You do not have permission to browse this storage.', 'sugarcommerce' ), '', [ 'response' => 403 ] );
		}

		wp_iframe( [ $this, 'render_tab_content' ] );
	}

	/**
	 * The view, bucket and prefix asked for, as far as they are allowed
	 *
	 * A bucket the allow-list does not name, or that is not a bucket name at
	 * all, is the bucket list instead: the listing is drawn here on the
	 * server, so the REST routes' allow-list check never sees it.
	 *
	 * @return array{0: string, 1: string, 2: string} View, bucket, prefix.
	 */
	private function requested(): array {
		$view   = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : '';
		$bucket = isset( $_GET['bucket'] ) ? sanitize_text_field( wp_unslash( $_GET['bucket'] ) ) : '';
		$prefix = isset( $_GET['prefix'] ) ? sanitize_text_field( wp_unslash( $_GET['prefix'] ) ) : '';

		// If no bucket is specified, use the default bucket if configured
		if ( '' === $bucket && '' === $view && '' !== $this->config->default_bucket ) {
			$bucket = $this->config->default_bucket;
		}

		if ( 'buckets' === $view || ! $this->bucket_allowed( $bucket ) ) {
			return [ 'buckets', '', '' ];
		}

		return [ 'objects', $bucket, $prefix ];
	}

	/**
	 * Whether a bucket may be listed here: well formed, and on the
	 * allow-list when there is one
	 *
	 * @param string $bucket Bucket name.
	 *
	 * @return bool
	 */
	private function bucket_allowed( string $bucket ): bool {
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/i', $bucket ) ) {
			return false;
		}

		$allowed = null === $this->allowed_buckets ? [] : (array) ( $this->allowed_buckets )();

		return [] === $allowed || in_array( $bucket, $allowed, true );
	}

	/**
	 * Buckets the bucket list may show: the allow-list, when there is one
	 *
	 * @return string[] Empty for no restriction.
	 */
	private function listable_buckets(): array {
		return null === $this->allowed_buckets ? [] : array_values( array_map( 'strval', (array) ( $this->allowed_buckets )() ) );
	}

	/**
	* Render the main tab content
	*
	* @return void
	*/
	public function render_tab_content(): void {
		// Load required WordPress styles and scripts
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'list-tables' );
		wp_enqueue_style( 'common' );
		wp_enqueue_style( 'wp-admin' );
		wp_enqueue_style( 'buttons' );

		// Load our specific styles and scripts
		$this->assets->enqueue_browser_assets( 'media-upload-popup' );

		[ $view, $bucket, $prefix ] = $this->requested();

		// Marked with the build's script handle: the browser script starts
		// only where its own build drew a browser.
		printf( '<div class="s3-browser-container" data-storage-browser="%s">', esc_attr( Assets::browser_handle() ) );

		// Pass the resolved bucket rather than letting the upload zone read
		// $_GET again: when a default bucket is configured the browser enters
		// it with no ?bucket= in the URL, and the zone would find nothing and
		// render no toolbar at all — no Upload, no New Folder.
		$this->render_upload_zone( $view === 'objects' ? $bucket : '', $prefix );

		if ( $view === 'buckets' ) {
			$this->display_buckets_list();
		} else {
			$this->display_objects_view( $bucket, $prefix );
			$this->render_selection_bar();
		}

		echo '</div>';
	}

	/**
	 * Print the bar that appears once files are ticked
	 *
	 * Its own markup rather than core's .media-toolbar. That class is styled
	 * for the media frame's DOM -- absolutely positioned inside
	 * .media-frame-toolbar at bottom: -60px -- and this renders in an iframe
	 * tab, which has no such ancestry, so the rules would apply and land in
	 * the wrong place. The buttons still use core's classes, which is where
	 * the look comes from.
	 *
	 * @return void
	 */
	private function render_selection_bar(): void {
		/**
		 * Filter the label on the insert button.
		 *
		 * The host names what it is inserting into, and that name is often
		 * configurable -- EDD lets a store rename "Download" to whatever it
		 * sells -- so the wording belongs to the consumer rather than here.
		 *
		 * @param string $label Button label.
		 */
		$label = $this->config->filter( 'browser_insert_label', __( 'Insert Selected', 'sugarcommerce' ) );

		?>
		<div class="s3-selection-bar" hidden>
			<div class="s3-selection-info">
				<span class="count" aria-live="polite"></span>
				<button type="button" class="button-link s3-clear-selection">
					<?php esc_html_e( 'Clear', 'sugarcommerce' ); ?>
				</button>
			</div>
			<div class="s3-selection-actions">
				<button type="button" class="button button-primary s3-insert-selected">
					<?php echo esc_html( $label ); ?>
				</button>
			</div>
		</div>
		<?php
	}


	/**
	* Display the objects view with breadcrumbs and navigation
	*
	* @param string $bucket The bucket name
	* @param string $prefix The object prefix/path
	*
	* @return void
	*/
	private function display_objects_view( string $bucket, string $prefix = '' ): void {
		// Show breadcrumbs
		$this->display_breadcrumbs( $bucket, $prefix );

		// Display objects list
		$this->display_objects_list( $bucket, $prefix );
	}

	/**
	* Enhanced ContentRendering Trait - Modify render_upload_zone() method
	*/
	public function render_upload_zone( string $bucket = '', string $prefix = '' ) {
		// Fall back to the query string for any caller that still relies on it,
		// as far as it is allowed.
		if ( '' === $bucket ) {
			[ , $bucket, $prefix ] = $this->requested();
		}

		// The toolbar belongs to a bucket view, not the bucket listing.
		if ( '' === $bucket ) {
			return;
		}

		?>
		<div class="s3-upload-wrapper">
			<div class="s3-toolbar-buttons">
				<!-- Use WordPress native button classes -->
				<button type="button" id="s3-toggle-upload" class="button button-primary">
					<?php esc_html_e( 'Upload Files', 'sugarcommerce' ); ?>
				</button>

				<button type="button" id="s3-create-folder" class="button button-secondary"
						data-bucket="<?php echo esc_attr( $bucket ); ?>"
						data-prefix="<?php echo esc_attr( $prefix ); ?>">
					<?php esc_html_e( 'New Folder', 'sugarcommerce' ); ?>
				</button>

				<?php
				// Bucket details — and with it CORS setup, which browser
				// uploads require. It was previously reachable only from the
				// bucket listing, which a site with a default bucket
				// configured never sees: the browser opens straight into the
				// bucket, so there was no route to configuring CORS at all.
				?>
				<button type="button" class="button button-secondary s3-bucket-details"
						data-bucket="<?php echo esc_attr( $bucket ); ?>"
						data-provider="<?php echo esc_attr( $this->config->provider_id ); ?>">
					<?php esc_html_e( 'Bucket Settings', 'sugarcommerce' ); ?>
				</button>
			</div>

			<div id="s3-upload-container" class="s3-upload-container" style="display: none;">
				<div class="s3-upload-header">
					<h3 class="s3-upload-title"><?php esc_html_e( 'Upload Files', 'sugarcommerce' ); ?></h3>
					<button type="button" class="s3-close-upload"
							aria-label="<?php esc_attr_e( 'Close', 'sugarcommerce' ); ?>">
						<?php echo Icon::render( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from fixed names. ?>
					</button>
				</div>
				<div class="s3-upload-zone" data-bucket="<?php echo esc_attr( $bucket ); ?>"
					data-prefix="<?php echo esc_attr( $prefix ); ?>">
					<div class="s3-upload-message">
						<?php echo Icon::render( 'upload' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from fixed names. ?>
						<p><?php esc_html_e( 'Drop files to upload', 'sugarcommerce' ); ?></p>
						<p class="s3-upload-or"><?php esc_html_e( 'or', 'sugarcommerce' ); ?></p>
						<input type="file" multiple class="s3-file-input" id="s3FileUpload">
						<!-- WordPress native button styling -->
						<label for="s3FileUpload" class="button button-secondary">
							<?php esc_html_e( 'Select Files', 'sugarcommerce' ); ?>
						</label>
					</div>
				</div>
				<div class="s3-upload-list"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Draw the breadcrumb trail: Buckets › bucket › each folder.
	 *
	 * The markup is the BreadcrumbList the stylesheet was written against --
	 * a nav, an ordered list with schema.org microdata, separators as
	 * aria-hidden list items, and the current folder marked aria-current.
	 *
	 * @param string $bucket Bucket name.
	 * @param string $prefix Object prefix/path.
	 *
	 * @return void
	 */
	private function display_breadcrumbs( string $bucket, string $prefix = '' ): void {
		$bucket_icon = Icon::render( 'bucket' );
		$folder_icon = Icon::render( 'folder', 's3-folder-icon' );

		// [ label, url or null, icon markup ].
		$items = [
			[ __( 'Buckets', 'sugarcommerce' ), $this->get_buckets_url(), $bucket_icon ],
			[
				$bucket,
				add_query_arg(
					[
						'tab'    => $this->config->tab_id(),
						'bucket' => $bucket,
					],
					remove_query_arg( [ 'prefix', 's', 'continuation_token' ] )
				),
				$folder_icon,
			],
		];

		$parts        = '' === $prefix ? [] : explode( '/', rtrim( $prefix, '/' ) );
		$current_path = '';

		foreach ( $parts as $i => $part ) {
			if ( '' === $part ) {
				continue;
			}

			$current_path .= $part . '/';

			if ( count( $parts ) - 1 === $i ) {
				$items[] = [ $part, null, '' ];
			} else {
				$items[] = [
					$part,
					add_query_arg(
						[
							'tab'    => $this->config->tab_id(),
							'bucket' => $bucket,
							'prefix' => $current_path,
						]
					),
					$folder_icon,
				];
			}
		}

		$html  = '';
		$total = count( $items );

		foreach ( $items as $index => [ $label, $url, $icon ] ) {
			$active = $total - 1 === $index || null === $url;
			$icon   = '' === $icon ? '' : $icon . ' ';
			$inner  = $active
				? sprintf( '%s<span itemprop="name">%s</span>', $icon, esc_html( $label ) )
				: sprintf( '<a href="%s" itemprop="item">%s<span itemprop="name">%s</span></a>', esc_url( $url ), $icon, esc_html( $label ) );

			$html .= sprintf(
				'<li class="%s" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem"%s>%s<meta itemprop="position" content="%d"></li>',
				$active ? ' active' : '',
				$active ? ' aria-current="page"' : '',
				$inner,
				$index + 1
			);

			if ( $total - 1 !== $index ) {
				$html .= '<li class="separator" aria-hidden="true">›</li>';
			}
		}

		printf(
			'<nav class="s3-browser-breadcrumbs" aria-label="%s"><ol class="breadcrumb" itemscope itemtype="https://schema.org/BreadcrumbList">%s</ol></nav>',
			esc_attr__( 'Breadcrumb', 'sugarcommerce' ),
			$html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every label, URL and class above is escaped where it is put in; the icons are the package's own SVG.
		);
	}

	/**
	* Get the URL for the buckets view
	*
	* @return string Buckets view URL
	*/
	private function get_buckets_url(): string {
		return add_query_arg(
				[
					'tab'  => $this->config->tab_id(),
					'view' => 'buckets',
				],
				remove_query_arg( [ 'bucket', 'prefix', 's', 'continuation_token' ] )
		);
	}

	/**
	* Display the buckets list
	*
	* @return void
	*/
	private function display_buckets_list(): void {
		// Create and prepare the list table
		$list_table = new Buckets( [
			'client'      => $this->client,
			'provider_id' => $this->config->provider_id,
			'tab'         => $this->config->tab_id(),
			'allowed'     => $this->listable_buckets(),
		] );

		// Prepare items
		try {
			$list_table->prepare_items();
		} catch ( Exception $e ) {
			printf(
					'<div class="notice notice-error s3-error"><p>%s</p></div>',
					esc_html__( 'Error loading buckets. Please try again.', 'sugarcommerce' )
			);
			return;
		}

		// Display the list table
		$list_table->display();
	}

	/**
	* Display the objects list for a specific bucket
	*
	* @param string $bucket Bucket name
	* @param string $prefix Object prefix
	*
	* @return void
	*/
	private function display_objects_list( string $bucket, string $prefix = '' ): void {
		// Create and prepare the list table
		$list_table = new Objects( [
			'client'      => $this->client,
			'bucket'      => $bucket,
			'prefix'      => $prefix,
			'provider_id' => $this->config->provider_id,
		] );

		// Prepare items
		try {
			$list_table->prepare_items();
		} catch ( Exception $e ) {
			printf(
					'<div class="notice notice-error s3-error"><p>%s</p></div>',
					esc_html__( 'Error loading files. Please try again.', 'sugarcommerce' )
			);
			return;
		}

		// Display the list table
		$list_table->display();
	}
}
