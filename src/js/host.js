/**
 * The browser on a page that is not a post editor.
 *
 * It knows nothing about the page: it puts the browser into whatever media
 * frame the page opens, and when files are chosen it hands them to the
 * page, which is the only thing that knows where they go. The page says so
 * by defining, before a frame is opened:
 *
 *     window.S3BrowserHost = {
 *         insert( files ) {
 *             // files: [ { fileName, bucket, key } ]
 *         }
 *     };
 */

import domReady from '@wordpress/dom-ready';

import { addBrowserTab, closeFrame, listenForInserts } from './lib/host-page';

let lastFrame = null;

domReady( () => {
	listenForInserts( ( files ) => {
		const host = window.S3BrowserHost;

		// Asked for on the way in rather than at load: a page may define
		// its handler after this script has run.
		if ( ! host || typeof host.insert !== 'function' ) {
			window.console.warn(
				'S3 Browser: this page has not defined window.S3BrowserHost.insert.'
			);
			return;
		}

		try {
			host.insert( files );
		} catch ( e ) {
			// The page's own handler failed. Leave the frame open so the
			// files are still there to try again with.
			window.console.error(
				'S3 Browser: the page could not insert the files.',
				e
			);
			return;
		}

		closeFrame( lastFrame );
	} );

	addBrowserTab( {
		// Says the frame was opened by a host page. A browser confined to
		// certain post types would otherwise find no post here to check.
		extra: () => ( { s3_host: '1' } ),

		// Whichever frame opened last is the one a chosen file belongs to.
		onOpen: ( frame ) => {
			lastFrame = frame;
		},
	} );
} );
