/**
 * The browser on WooCommerce's product screen.
 *
 * Adds the browser as a tab to the product's media frames, remembers which
 * downloadable-file row opened it, and carries out the insert the browser
 * asks for.
 */

import domReady from '@wordpress/dom-ready';

import {
	addBrowserTab,
	closeFrame,
	comparablePath,
	listenForInserts,
	setValue,
} from './lib/host-page';
import { globalConfig } from './lib/config';
import { on } from './lib/dom';

/*
 * A downloadable file's inputs, on a product or a variation. A variation's
 * are named _wc_variation_file_urls[123][] rather than _wc_file_urls[], in
 * a table with the same .downloadable_files class -- so a container
 * selector matches both and lands in whichever is last.
 */
const URL_FIELDS =
	'input[name^="_wc_file_urls"], input[name^="_wc_variation_file_urls"]';
const NAME_FIELDS =
	'input[name^="_wc_file_names"], input[name^="_wc_variation_file_names"]';

let saved = null;
let watching = null;

/**
 * Remember the row whose "Choose file" was clicked.
 *
 * @param {Element} button The button.
 */
function trackButton( button ) {
	const row = button.closest( 'tr' );
	const input = row?.querySelector( URL_FIELDS );

	if ( input ) {
		window.wc_target_input = input;
		window.wc_target_row = row;
		window.wc_target_table = row.closest( '.downloadable_files' );
		window.wc_media_frame_context = 'product_file';
	}

	window.wc_gallery_frame = false;
}

/**
 * Write files into the downloadable files table, adding rows as needed.
 *
 * @param {Array<Object>} files { fileName, bucket, key } each.
 */
export function insertFiles( files ) {
	if ( ! files.length ) {
		return;
	}

	const table =
		( window.wc_target_table &&
			window.wc_target_table.isConnected &&
			window.wc_target_table ) ||
		document.querySelector( '.downloadable_files' );
	const target =
		window.wc_target_row && window.wc_target_row.isConnected
			? window.wc_target_row
			: null;

	if ( ! table ) {
		return;
	}

	const existing = Array.from( table.querySelectorAll( URL_FIELDS ) )
		.filter( ( field ) => ! target || field.closest( 'tr' ) !== target )
		.map( ( field ) => comparablePath( field.value ) )
		.filter( Boolean );
	const wanted = files.filter(
		( file ) => ! existing.includes( `${ file.bucket }/${ file.key }` )
	);

	if ( ! wanted.length ) {
		closeFrame();
		return;
	}

	let row =
		target ||
		Array.from( table.querySelectorAll( 'tbody tr' ) ).find(
			( candidate ) =>
				! ( candidate.querySelector( URL_FIELDS )?.value || '' ).trim()
		) ||
		null;

	wanted.forEach( ( file, index ) => {
		if ( index > 0 || ! row ) {
			table.querySelector( '.insert' )?.click();
			const rows = table.querySelectorAll( 'tbody tr' );
			row = rows[ rows.length - 1 ];
		}

		setValue(
			row?.querySelector( URL_FIELDS ),
			`${ file.bucket }/${ file.key }`
		);
		setValue( row?.querySelector( NAME_FIELDS ), file.fileName );
	} );

	closeFrame();
}

/**
 * WooCommerce's own frame relabels the Select button when its tab is left;
 * switching to the browser's tab and back otherwise leaves it blank. Keep
 * the label it had while a frame is open.
 */
function keepSelectButton() {
	const button = document.querySelector( '.media-button-select' );

	if ( ! button ) {
		return;
	}

	const tab = `menu-item-${ globalConfig().tabId }`;
	const activeTab = document.querySelector( '.media-menu-item.active' );

	if ( ! saved && button.textContent.trim() ) {
		saved = { text: button.textContent, classes: button.className };
	}

	if ( saved && ( ! activeTab || activeTab.id !== tab ) ) {
		if ( ! button.textContent.trim() ) {
			button.textContent = saved.text;
		}

		if (
			! button.classList.contains( 'button-primary' ) &&
			saved.classes.includes( 'button-primary' )
		) {
			button.className = saved.classes;
		}
	}
}

domReady( () => {
	on( document, 'click', '.upload_file_button', ( event, button ) =>
		trackButton( button )
	);

	on( document, 'click', '.wp-media-buttons .button', () => {
		if ( window.wc_media_frame_context === 'product_file' ) {
			delete window.wc_media_frame_context;
		}
	} );

	listenForInserts( insertFiles );

	addBrowserTab( {
		extra: () => {
			const post = window.wp?.media?.view?.settings?.post;

			return post && post.id ? { post_id: String( post.id ) } : {};
		},
		onOpen: ( frame ) => {
			saved = null;
			clearInterval( watching );
			watching = setInterval( keepSelectButton, 100 );
			frame.once?.( 'close', () => clearInterval( watching ) );
		},
	} );
} );
