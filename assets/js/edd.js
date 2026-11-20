/**
 * The browser on Easy Digital Downloads' download screen.
 *
 * Runs on the edit screen, not inside the browser. Two jobs: remember which
 * file row opened the browser, and carry out an insert the browser asks for.
 */

/* eslint-disable no-alert -- confirm() and prompt() are how the browser has always asked. */

import domReady from '@wordpress/dom-ready';

import {
	closeFrame,
	comparablePath,
	listenForInserts,
	setValue,
} from './lib/host-page';
import { globalConfig } from './lib/config';
import { on } from './lib/dom';

const ROW = '.edd_repeatable_row';
const URL_FIELD = '.edd_repeatable_upload_field';
const NAME_FIELD = '.edd_repeatable_name_field';

/**
 * Remember the row whose button was clicked: a single file goes there, and
 * extra rows are added after it.
 *
 * @param {Element} button The row's upload button.
 */
function trackButton( button ) {
	const row = button.closest( ROW );

	window.edd_fileurl =
		button.parentElement?.previousElementSibling?.querySelector(
			'input'
		) ||
		row?.querySelector( URL_FIELD ) ||
		null;
	window.edd_filename = row?.querySelector( NAME_FIELD ) || null;
	window.edd_row = row;
	window.edd_media_frame_context = 'edd_file';
}

/**
 * Say how many were skipped as already listed.
 *
 * @param {number} skipped How many.
 */
function sayAlreadyAdded( skipped ) {
	const i18n = globalConfig().i18n || {};

	window.alert(
		( i18n.alreadyAdded || '%d skipped' ).replace( '%d', String( skipped ) )
	);
}

/**
 * Write files into the file table, adding rows as needed.
 *
 * Files already listed are skipped: EDD does not deduplicate, and picking
 * one by accident out of a long list is easy. The clicked row is about to
 * be replaced, so what it holds does not count as "already there".
 *
 * @param {Array<Object>} files { fileName, bucket, key } each.
 */
export function insertFiles( files ) {
	if ( ! files.length ) {
		return;
	}

	const target =
		window.edd_row && window.edd_row.isConnected ? window.edd_row : null;
	const existing = Array.from( document.querySelectorAll( URL_FIELD ) )
		.filter( ( field ) => ! target || field.closest( ROW ) !== target )
		.map( ( field ) => comparablePath( field.value ) )
		.filter( Boolean );
	const wanted = files.filter(
		( file ) => ! existing.includes( `${ file.bucket }/${ file.key }` )
	);
	const skipped = files.length - wanted.length;

	if ( ! wanted.length ) {
		sayAlreadyAdded( skipped );
		closeFrame();
		return;
	}

	// The row whose button opened the browser is the one to fill. Without
	// one, never the last row -- it usually holds a real file -- but an
	// empty one, or a new one.
	let row =
		target ||
		Array.from( document.querySelectorAll( ROW ) ).find(
			( candidate ) =>
				! ( candidate.querySelector( URL_FIELD )?.value || '' ).trim()
		) ||
		null;

	wanted.forEach( ( file, index ) => {
		if ( index > 0 || ! row ) {
			// EDD clones the last row on click.
			Array.from( document.querySelectorAll( '.edd_add_repeatable' ) )
				.find( ( button ) => button.offsetParent !== null )
				?.click();
			const rows = document.querySelectorAll( ROW );
			row = rows[ rows.length - 1 ];
		}

		setValue(
			row?.querySelector( URL_FIELD ),
			`${ file.bucket }/${ file.key }`
		);
		setValue( row?.querySelector( NAME_FIELD ), file.fileName );
	} );

	if ( skipped ) {
		sayAlreadyAdded( skipped );
	}

	closeFrame();
}

domReady( () => {
	on( document, 'click', '.edd_upload_file_button', ( event, button ) =>
		trackButton( button )
	);

	on( document, 'click', '.wp-media-buttons .button', () => {
		if ( window.edd_media_frame_context === 'edd_file' ) {
			delete window.edd_media_frame_context;
			delete window.edd_fileurl;
			delete window.edd_filename;
		}
	} );

	listenForInserts( insertFiles );
} );
