/**
 * Handing chosen files to whatever opened the browser.
 *
 * The browser renders in a frame. The fields to fill belong to the page
 * that opened it, and that page may not be readable at all -- the block
 * editor's canvas has an opaque origin, and touching its window throws a
 * SecurityError rather than answering. So the page is asked, by a
 * postMessage addressed to the admin's own origin and carrying a token the
 * page checks against its own copy (a frame with an opaque origin posts as
 * "null", so origin alone will not identify the sender). Only the media
 * library's own editor, which has no listener, is written into directly.
 */

/* eslint-disable no-alert -- confirm() and prompt() are how the browser has always asked. */

import { globalConfig, t } from './config';

/**
 * The first ancestor window that can actually be read, or null.
 *
 * Ancestors only: this window would always "succeed" and always find
 * nothing.
 *
 * @param {Window} self This window.
 * @return {Window|null} The host window.
 */
export function hostWindow( self = window ) {
	const candidates = [];

	try {
		if ( self.parent && self.parent !== self ) {
			candidates.push( self.parent );
		}
	} catch {
		// Reading .parent can itself throw in a sandboxed frame.
	}

	try {
		if (
			self.top &&
			self.top !== self &&
			! candidates.includes( self.top )
		) {
			candidates.push( self.top );
		}
	} catch {
		// Same.
	}

	for ( const candidate of candidates ) {
		try {
			// A cross-origin window looks fine until it is read.
			if ( candidate.document && candidate.document.body ) {
				return candidate;
			}
		} catch {
			// Not this one.
		}
	}

	return null;
}

/**
 * Which kind of page opened the browser.
 *
 * @param {Window|null} host hostWindow().
 * @return {string} host, edd, woocommerce_file, wp_editor or unknown.
 */
export function callingContext( host ) {
	if ( ! host ) {
		return 'unknown';
	}

	try {
		// A page that has said where files go, in so many words. First,
		// because it is a statement rather than an inference from globals
		// another plugin left lying about.
		if (
			host.S3BrowserHost &&
			typeof host.S3BrowserHost.insert === 'function'
		) {
			return 'host';
		}

		if ( host.edd_fileurl && host.edd_filename ) {
			return 'edd';
		}

		if (
			host.wc_target_input &&
			host.wc_media_frame_context === 'product_file'
		) {
			return 'woocommerce_file';
		}

		if ( host.wp && host.wp.media && host.wp.media.editor ) {
			return 'wp_editor';
		}
	} catch {
		// A frame that refuses to be read is one this cannot write to either.
	}

	return 'unknown';
}

/**
 * Ask the page that opened the browser to insert these files.
 *
 * @param {Array<Object>} files { fileName, bucket, key } each.
 * @param {Window}        self  This window.
 * @return {boolean} Whether a request could be sent.
 */
export function requestInsert( files, self = window ) {
	const config = globalConfig();

	if ( ! config.adminOrigin || ! config.insertToken ) {
		return false;
	}

	try {
		self.parent.postMessage(
			{ type: 's3-browser:insert', token: config.insertToken, files },
			config.adminOrigin
		);
	} catch {
		return false;
	}

	return true;
}

/**
 * Say why nothing could be written, for the console.
 *
 * @param {Window|null} host hostWindow().
 * @return {string} What was found.
 */
export function describeHost( host ) {
	const notes = [];

	if ( ! host ) {
		return 'no readable ancestor window';
	}

	[
		'edd_fileurl',
		'edd_filename',
		'wc_target_input',
		'wc_media_frame_context',
	].forEach( ( name ) => {
		try {
			notes.push(
				`${ name }: ${ host[ name ] === undefined ? 'unset' : 'set' }`
			);
		} catch {
			notes.push( `${ name }: unreadable` );
		}
	} );

	try {
		notes.push(
			`wp.media: ${ host.wp && host.wp.media ? 'present' : 'absent' }`
		);
	} catch {
		notes.push( 'wp.media: unreadable' );
	}

	return notes.join( ' | ' );
}

/**
 * Give up, but not silently: the key, to copy by hand.
 *
 * @param {Object}      file A chosen file.
 * @param {Window|null} host hostWindow().
 */
function unavailable( file, host ) {
	window.console.warn(
		`S3 Browser: no insert target. ${ describeHost( host ) }`
	);
	window.prompt(
		t( 'files', 'insertUnavailable' ),
		`${ file.bucket }/${ file.key }`
	);
}

/**
 * Insert the chosen files where they belong.
 *
 * EDD, WooCommerce and a host page each own a table of rows, and only the
 * page itself knows which row's button opened the browser and how its
 * platform adds another row -- so all of them are asked. The editor is the
 * one place written into from here.
 *
 * @param {Array<Object>} files { fileName, bucket, key } each.
 * @return {boolean} Whether the files went somewhere; false keeps the selection.
 */
export function insertFiles( files ) {
	if ( ! files.length ) {
		return false;
	}

	const host = hostWindow();
	const context = callingContext( host );

	if ( context === 'wp_editor' ) {
		const url = `${ files[ 0 ].bucket }/${ files[ 0 ].key }`;

		try {
			if ( host.wp.media.editor.activeEditor ) {
				host.wp.media.editor.insert( url );
			} else if ( host.wpActiveEditor ) {
				host.wp.media.editor.insert( url, host.wpActiveEditor );
			} else {
				throw new Error( 'No active editor found' );
			}

			host.wp.media.frame?.close();
		} catch ( error ) {
			window.console.error( 'Editor insertion error:', error );
			window.prompt( t( 'files', 'insertUnavailable' ), url );
		}

		return true;
	}

	if ( context !== 'unknown' || ! host ) {
		if ( requestInsert( files ) ) {
			return true;
		}
	}

	unavailable( files[ 0 ], host );

	return false;
}
