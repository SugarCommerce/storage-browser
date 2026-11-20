/**
 * One upload's row in the upload list: its bar, its numbers, its notes.
 */

import { __ } from '@wordpress/i18n';

import { element, formatFileSize, html, icon } from './dom';

/**
 * A row for a file about to be sent.
 *
 * @param {File}   file The file.
 * @param {string} id   The row's id.
 * @return {Element} The row.
 */
export function createRow( file, id ) {
	const row = element( html`
		<div class="s3-upload-item" id="${ id }">
			<div class="s3-upload-item-info">
				<span class="s3-filename">${ file.name }</span>
				<span class="s3-filesize"
					>${ formatFileSize( file.size ) }</span
				>
			</div>
			<div class="s3-progress-container">
				<div
					class="s3-progress-bar"
					role="progressbar"
					aria-valuemin="0"
					aria-valuemax="100"
					aria-valuenow="0"
					aria-label="${ file.name }"
				>
					<div class="s3-progress" style="width: 0"></div>
				</div>
				<span class="s3-progress-text">0%</span>
				<span class="s3-transfer-data"></span>
			</div>
			<div class="s3-upload-status">
				<button
					type="button"
					class="s3-cancel-upload"
					data-upload-id="${ id }"
					aria-label="${ __( 'Cancel upload', 'sugarcommerce' ) }"
					title="${ __( 'Cancel upload', 'sugarcommerce' ) }"
				>
					${ icon( 'cancel' ) }
				</button>
			</div>
		</div>
	` );

	row.s3Speed = { loaded: 0, time: Date.now() };

	return row;
}

/**
 * Move a row's bar, and every half second say how fast.
 *
 * @param {Element} row              createRow().
 * @param {Object}  progress         How far along.
 * @param {number}  progress.loaded  Bytes sent.
 * @param {number}  progress.total   Bytes in all.
 * @param {number}  progress.percent Percent sent.
 */
export function showProgress( row, { loaded, total, percent } ) {
	const bar = row.querySelector( '.s3-progress' );

	bar.style.width = `${ percent }%`;
	row.querySelector( '.s3-progress-bar' ).setAttribute(
		'aria-valuenow',
		String( percent )
	);
	row.querySelector( '.s3-progress-text' ).textContent = `${ percent }%`;

	const now = Date.now();
	const seconds = ( now - row.s3Speed.time ) / 1000;

	if ( seconds > 0.5 ) {
		const speed = Math.max( 0, loaded - row.s3Speed.loaded ) / seconds;

		row.querySelector( '.s3-transfer-data' ).textContent =
			`${ formatFileSize( loaded ) } / ${ formatFileSize(
				total
			) } • ${ formatFileSize( speed ) }/s`;
		row.s3Speed = { loaded, time: now };
	}
}

/**
 * A line under a row's bar: resuming, retrying, assembling.
 *
 * @param {Element} row  createRow().
 * @param {string}  text The line.
 */
export function noteRow( row, text ) {
	let note = row.querySelector( '.s3-upload-part-note' );

	if ( ! note ) {
		note = document.createElement( 'div' );
		note.className = 's3-upload-part-note';
		note.setAttribute( 'aria-live', 'polite' );
		row.querySelector( '.s3-progress-container' ).after( note );
	}

	note.textContent = text;
}

/**
 * Show how a row ended.
 *
 * @param {Element} row   createRow().
 * @param {string}  state success, error or cancelled.
 */
export function finishRow( row, state ) {
	const icons = { success: 'done', error: 'failed', cancelled: 'cancel' };

	row.classList.add( `s3-upload-${ state }` );
	row.querySelector( '.s3-upload-status' ).innerHTML = icon(
		icons[ state ]
	).markup;
}
