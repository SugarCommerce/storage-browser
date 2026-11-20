/**
 * The listing: search, "Load more", ticking files and inserting them,
 * moving between buckets and folders, and refreshing the cache.
 *
 * The table itself is drawn by PHP (Tables\Buckets, Tables\Objects); this
 * filters and extends it.
 */

import { request } from './api';
import { browserConfig, globalConfig, t } from './config';
import {
	all,
	clearBusy,
	fadeOut,
	notify,
	on,
	one,
	reloadSoon,
	setBusy,
	showError,
} from './dom';
import { insertFiles } from './insert';

const state = {
	rows: [],
	loaded: 0,
	loading: false,
	timer: null,
};

/**
 * Go somewhere else in the browser: a bucket, or a folder in one.
 *
 * @param {Object} params bucket, prefix.
 */
export function navigateTo( params ) {
	const query = new URLSearchParams( {
		...params,
		chromeless: 1,
		post_id: browserConfig().postId || 0,
		tab: globalConfig().tabId,
	} );

	// Opened by a host page, which has no post to be recognised by. Carried
	// on every move, or the folder being opened would be refused where the
	// bucket it is in was allowed.
	if ( browserConfig().host ) {
		query.set( 's3_host', '1' );
	}

	window.location.href = `${ window.location.href.split( '?' )[ 0 ] }?${ query }`;
}

/**
 * The rows of the table that are files or folders, not the "no results" one.
 *
 * @return {Element[]} Rows.
 */
const tableRows = () =>
	all( '.wp-list-table tbody tr:not(.s3-no-results):not(.no-items)' );

/**
 * Remember the table's rows, for search to filter from.
 */
export function snapshotRows() {
	state.rows = tableRows();
}

/**
 * Show only the rows whose name holds the search term.
 *
 * @param {string} term What was typed.
 */
export function filterTable( term ) {
	const body = one( '.wp-list-table tbody' );

	if ( ! body ) {
		return;
	}

	const stats = one( '.s3-search-stats' );
	const bottom = one( '.tablenav.bottom' );

	body.querySelectorAll( '.s3-no-results' ).forEach( ( row ) =>
		row.remove()
	);

	if ( ! term ) {
		state.rows.forEach( ( row ) => {
			row.hidden = false;
		} );

		if ( stats ) {
			stats.textContent = '';
		}

		if ( bottom ) {
			bottom.hidden = false;
		}

		return;
	}

	const needle = term.toLowerCase();
	let visible = 0;

	state.rows.forEach( ( row ) => {
		const name = (
			row.querySelector( '.column-name' )?.textContent || ''
		).toLowerCase();
		row.hidden = ! name.includes( needle );
		visible += row.hidden ? 0 : 1;
	} );

	if ( bottom ) {
		bottom.hidden = true;
	}

	if ( visible === 0 ) {
		const cell = document.createElement( 'td' );
		const row = document.createElement( 'tr' );

		cell.colSpan = all( '.wp-list-table thead th' ).length || 1;
		cell.textContent = t( 'search', 'noFilesFound' ).replace(
			'{term}',
			term
		);
		row.className = 's3-no-results';
		row.append( cell );
		body.append( row );
	}

	if ( stats ) {
		stats.textContent =
			visible === 0
				? t( 'search', 'noMatchesFound' )
				: t( 'search', 'itemsMatch' )
						.replace( '{visible}', String( visible ) )
						.replace( '{total}', String( state.rows.length ) );
	}
}

/**
 * Say how many items are listed, and whether more are to come.
 *
 * @param {boolean} hasMore Whether "Load more" has more.
 */
function updateTotal( hasMore ) {
	const count = one( '#s3-total-count' );

	if ( ! count ) {
		return;
	}

	const noun =
		state.loaded === 1
			? t( 'display', 'singleItem' )
			: t( 'display', 'multipleItems' );
	count.textContent = `${ state.loaded } ${ noun }${ hasMore ? t( 'display', 'moreAvailable' ) : '' }`;
}

/**
 * Fetch the next page of the folder and add its rows.
 *
 * @param {HTMLButtonElement} button The "Load more" button.
 */
function loadMore( button ) {
	const token = button.dataset.token;

	if ( ! token || state.loading ) {
		return;
	}

	state.loading = true;
	setBusy( button, t( 'loading', 'loadingText' ) );

	request( 'listObjects', {
		bucket: button.dataset.bucket,
		prefix: button.dataset.prefix || '',
		continuation_token: token,
	} )
		.then( ( page ) => {
			const body = one( '.wp-list-table tbody' );
			const template = document.createElement( 'template' );

			template.innerHTML = page.html || '';
			body.append( template.content );
			state.loaded += page.count || 0;
			snapshotRows();
			clearBusy( button );

			if ( page.has_more && page.continuation_token ) {
				button.dataset.token = page.continuation_token;
				updateTotal( true );
			} else {
				fadeOut( button.closest( '.pagination-links' ) || button );
				updateTotal( false );
			}

			filterTable( one( '#s3-js-search' )?.value || '' );
		} )
		.catch( ( error ) => {
			showError( error.message );
			clearBusy( button );
		} )
		.finally( () => {
			state.loading = false;
		} );
}

/**
 * Empty the cache this view was drawn from, and draw it again.
 *
 * @param {HTMLButtonElement} button The refresh button.
 */
function refreshCache( button ) {
	if ( ! setBusy( button, t( 'ui', 'refreshing' ) ) ) {
		return;
	}

	request( 'clearCache', {
		type: button.dataset.type,
		bucket: button.dataset.bucket || '',
		prefix: button.dataset.prefix || '',
	} )
		.then( ( answer ) => {
			notify(
				answer.message || t( 'cache', 'cacheRefreshed' ),
				'success'
			);
			reloadSoon();
		} )
		.catch( ( error ) => {
			notify( error.message, 'error' );
			clearBusy( button );
		} );
}

/**
 * The files currently ticked, in the order they appear.
 *
 * @return {Array<Object>} { fileName, bucket, key } each.
 */
export function selectedFiles() {
	return all( '.s3-select-file:checked' ).map( ( box ) => ( {
		fileName: box.dataset.filename,
		bucket: box.dataset.bucket,
		key: box.dataset.key,
	} ) );
}

/**
 * Show the selection bar once something is ticked, and say how many.
 */
export function updateSelectionBar() {
	const count = all( '.s3-select-file:checked' ).length;
	const bar = one( '.s3-selection-bar' );

	// The class reserves room at the foot of the page; the bar is fixed, so
	// without it the last row sits underneath.
	document.body.classList.toggle( 's3-has-selection', count > 0 );

	if ( ! bar ) {
		return;
	}

	bar.hidden = count === 0;

	if ( count ) {
		bar.querySelector( '.count' ).textContent =
			count === 1
				? t( 'files', 'oneSelected' )
				: t( 'files', 'manySelected' ).replace( '%d', String( count ) );
	}
}

/**
 * Untick everything and hide the bar.
 *
 * After an insert as well as from the Clear button: EDD keeps one media
 * frame and reopens it, so this document survives the dialog closing, and
 * the next open would show the old selection still ticked.
 */
export function clearSelection() {
	all( '.s3-select-file, .wp-list-table .check-column input' ).forEach(
		( box ) => {
			box.checked = false;
		}
	);
	updateSelectionBar();
}

/**
 * Insert files, and untick them once they have gone.
 *
 * @param {Array<Object>} files { fileName, bucket, key } each.
 */
export function insert( files ) {
	if ( insertFiles( files ) ) {
		clearSelection();
	}
}

/**
 * Keep tooltips near the right or left edge on the page.
 *
 * @param {Element} target The element with the tooltip.
 */
function placeTooltip( target ) {
	const rect = target.getBoundingClientRect();
	const half = 100;

	target.removeAttribute( 'data-tooltip-position' );

	if ( rect.left + half > window.innerWidth - 20 ) {
		target.setAttribute( 'data-tooltip-position', 'right' );
	} else if ( rect.left - half < 20 ) {
		target.setAttribute( 'data-tooltip-position', 'left' );
	}
}

/**
 * Wire the listing up.
 */
export function initList() {
	const root = document;

	snapshotRows();
	state.loaded = state.rows.length;

	const more = one( '#s3-load-more' );
	updateTotal( !! more && ! more.hidden );

	on( root, 'click', '.s3-insert-file', ( event, link ) => {
		event.preventDefault();
		insert( [
			{
				fileName: link.dataset.filename,
				bucket: link.dataset.bucket,
				key: link.dataset.key,
			},
		] );
	} );

	// Ticking is additive: the per-row Insert still works on its own, so a
	// single file costs no extra clicks.
	on(
		root,
		'change',
		'.s3-select-file, .wp-list-table thead .check-column input, .wp-list-table tfoot .check-column input',
		( event, input ) => {
			if ( ! input.classList.contains( 's3-select-file' ) ) {
				all( '.s3-select-file' ).forEach( ( box ) => {
					if ( ! box.closest( 'tr' )?.hidden ) {
						box.checked = input.checked;
					}
				} );
			}

			updateSelectionBar();
		}
	);

	on( root, 'click', '.s3-clear-selection', ( event ) => {
		event.preventDefault();
		clearSelection();
	} );

	on( root, 'click', '.s3-insert-selected', ( event ) => {
		event.preventDefault();
		insert( selectedFiles() );
	} );

	on(
		root,
		'click',
		'.s3-browser-container a.bucket-name, .s3-browser-container a.browse-bucket-button, .s3-browser-container .browse-bucket-button',
		( event, link ) => {
			event.preventDefault();
			event.stopPropagation();

			if ( link.dataset.bucket ) {
				navigateTo( { bucket: link.dataset.bucket, prefix: '' } );
			}
		}
	);

	on(
		root,
		'click',
		'.s3-browser-container a.s3-folder-link',
		( event, link ) => {
			event.preventDefault();
			navigateTo( {
				bucket:
					link.dataset.bucket ||
					one( '#s3-load-more' )?.dataset.bucket ||
					globalConfig().defaultBucket,
				prefix: link.dataset.prefix,
			} );
		}
	);

	const search = one( '#s3-js-search' );
	const clear = one( '#s3-js-search-clear' );

	search?.addEventListener( 'input', () => {
		if ( clear ) {
			clear.hidden = ! search.value;
			clear.style.display = search.value ? '' : 'none';
		}

		clearTimeout( state.timer );
		state.timer = setTimeout( () => filterTable( search.value ), 200 );
	} );

	clear?.addEventListener( 'click', () => {
		search.value = '';
		search.dispatchEvent( new Event( 'input' ) );
		search.focus();
	} );

	on( root, 'click', '#s3-load-more', ( event, button ) => {
		event.preventDefault();
		loadMore( button );
	} );

	on( root, 'click', '.s3-refresh-button', ( event, button ) => {
		event.preventDefault();
		refreshCache( button );
	} );

	on( root, 'mouseover', '.s3-has-tooltip', ( event, target ) =>
		placeTooltip( target )
	);

	if ( browserConfig().autoLoad ) {
		window.addEventListener(
			'scroll',
			() => {
				const button = one( '#s3-load-more' );

				if (
					! state.loading &&
					button &&
					button.offsetParent !== null
				) {
					if (
						button.getBoundingClientRect().top <
						window.innerHeight + 200
					) {
						loadMore( button );
					}
				}
			},
			{ passive: true }
		);
	}
}
