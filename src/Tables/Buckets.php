<?php
/**
 * S3 Buckets List Table - Simplified Row Actions
 *
 * @package     SugarCommerce\StorageBrowser\Tables
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tables;

use SugarCommerce\StorageBrowser\Admin\Icon;
use WP_List_Table;

/**
 * S3 Buckets List Table
 */
class Buckets extends WP_List_Table {

	/*
	 * Paging and search state read from the query string, for display only.
	 * WP_List_Table works this way throughout core.
	 *
	 * phpcs:disable WordPress.Security.NonceVerification.Recommended
	 */

	/**
	 * Client instance
	 */
	private $client;

	/**
	 * Provider ID
	 */
	private $provider_id;

	/**
	 * The buckets this browser may show; empty for any.
	 *
	 * @var string[]
	 */
	private array $allowed = [];

	/**
	 * Constructor
	 *
	 * @param array $args Additional args
	 */
	public function __construct( $args = array() ) {
		parent::__construct( [
			'singular' => 'bucket',
			'plural'   => 'buckets',
			'ajax'     => false,
		] );

		$this->client      = $args['client'];
		$this->provider_id = $args['provider_id'];
		$this->allowed     = array_values( array_map( 'strval', (array) ( $args['allowed'] ?? [] ) ) );
	}

	/**
	 * Get columns
	 */
	public function get_columns() {
		return [
			'name'    => __( 'Bucket Name', 'sugarcommerce' ),
			'created' => __( 'Creation Date', 'sugarcommerce' ),
		];
	}

	/**
	 * Prepare items
	 */
	public function prepare_items() {
		$this->_column_headers = [ $this->get_columns(), [], [] ];

		$result = $this->client->get_buckets( true );

		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			$this->items = [];

			return;
		}

		$items = [];

		foreach ( $result['buckets'] as $bucket ) {
			// An allow-list is the whole of what this browser may show.
			if ( [] !== $this->allowed && ! in_array( (string) $bucket['name'], $this->allowed, true ) ) {
				continue;
			}

			$items[] = [
				'name'    => $bucket['name'],
				'created' => '' === $bucket['created'] ? '' : (string) wp_date( 'M j, Y g:i A', (int) strtotime( $bucket['created'] ) ),
				'raw'     => $bucket['created'],
			];
		}

		$this->items = $items;

		// ListBuckets answers with every bucket at once.
		$this->set_pagination_args( [
			'total_items' => count( $items ),
			'per_page'    => max( 1, count( $items ) ),
			'total_pages' => 1,
		] );
	}

	/**
	 * Column default
	 */
	public function column_default( $item, $column_name ) {
		return $item[ $column_name ] ?? '';
	}

	/**
	 * Column name - Now clickable with proper attributes
	 */
	public function column_name( $item ) {
		$bucket  = $item['name'];
		$actions = $this->get_row_actions( $item );

		$primary_content = sprintf(
			'%s <a href="#" class="bucket-name" data-bucket="%s"><strong>%s</strong></a>',
			Icon::render( 'bucket' ),
			esc_attr( $bucket ),
			esc_html( $bucket )
		);

		return $primary_content . $this->row_actions( $actions );
	}

	/**
	 * Column created - Display formatted creation date with relative time
	 */
	public function column_created( $item ) {
		$formatted_date = $item['created']; // Already formatted in prepare_items
		$raw_date       = $item['raw']; // As storage gave it

		// Add relative time if we have a valid date
		$relative_time = '';
		if ( ! empty( $raw_date ) ) {
			$timestamp = strtotime( $raw_date );
			if ( $timestamp ) {
				$relative_time = sprintf(
					'<br><small class="description">%s</small>',
					esc_html(
						sprintf(
							/* translators: %s: a span of time, such as "2 days". */
							__( '%s ago', 'sugarcommerce' ),
							human_time_diff( $timestamp )
						)
					)
				);
			}
		}

		return $formatted_date . $relative_time;
	}

	/**
	 * Generate row actions - SIMPLIFIED to just Browse and Details
	 *
	 * @param array $item Item data
	 *
	 * @return array Array of action links
	 */
	protected function get_row_actions( array $item ): array {
		$bucket  = $item['name'];
		$actions = [];

		// Browse action
		$actions['browse'] = sprintf(
			'<a href="#" class="browse-bucket-button" data-bucket="%s">%s</a>',
			esc_attr( $bucket ),
			esc_html__( 'Browse', 'sugarcommerce' )
		);

		// Details action - comprehensive modal
		$actions['details'] = sprintf(
			'<a href="#" class="s3-bucket-details" data-bucket="%s" data-provider="%s">%s</a>',
			esc_attr( $bucket ),
			esc_attr( $this->provider_id ),
			esc_html__( 'Details', 'sugarcommerce' )
		);

		return $actions;
	}

	/**
	 * Display table navigation
	 *
	 * @param string $which Which tablenav ('top' or 'bottom')
	 */
	public function display_tablenav( $which ) {
		?>
		<div class="tablenav <?php echo esc_attr( $which ); ?>">
			<?php if ( $which === 'top' ) : ?>
				<div class="s3-top-nav">
					<div class="s3-actions-container">
						<?php
						// Use WordPress native button classes
						printf(
							'<button type="button" class="button button-secondary s3-refresh-button" data-type="buckets" data-provider="%s">%s</button>',
							esc_attr( $this->provider_id ),
							esc_html__( 'Refresh', 'sugarcommerce' )
						);
						?>
					</div>
				</div>
			<?php else : ?>
				<!-- Bottom navigation with WordPress styling -->
				<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					$count = count( $this->items );
					echo esc_html( sprintf(
						/* translators: %s: number of buckets */
						_n( '%s bucket', '%s buckets', $count, 'sugarcommerce' ),
						number_format_i18n( $count )
					) );
					?>
				</span>
					<?php if ( isset( $this->_pagination_args['marker'] ) && ! empty( $this->_pagination_args['marker'] ) ) : ?>
						<span class="pagination-links">
						<?php
						$marker  = $this->_pagination_args['marker'];
						$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0;
						$url     = add_query_arg( [
							'chromeless' => 1,
							'post_id'    => $post_id,
							'tab'        => 's3_' . $this->provider_id,
							'view'       => 'buckets',
							'marker'     => urlencode( $marker ),
						] );
						?>
						<!-- WordPress native pagination button -->
						<a class="button button-secondary" href="<?php echo esc_url( $url ); ?>">
							<?php esc_html_e( 'Next Page', 'sugarcommerce' ); ?> &raquo;
						</a>
					</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<br class="clear"/>
		</div>
		<?php
	}

	/**
	 * No items found text
	 */
	public function no_items() {
		echo esc_html__( 'No buckets found.', 'sugarcommerce' );
	}
}