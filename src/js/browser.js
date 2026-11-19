/**
 * The bucket browser, in the media frame's tab or on an admin page.
 */

import domReady from '@wordpress/dom-ready';

import { initBuckets } from './lib/buckets';
import { initFiles } from './lib/files';
import { initFolders } from './lib/folders';
import { initList, navigateTo } from './lib/list';
import { initUploads } from './lib/upload-panel';

domReady( () => {
	// One browser per document, however many copies of the script load.
	if ( window.S3BrowserInitialized ) {
		return;
	}

	window.S3BrowserInitialized = true;

	initList();
	initFiles();
	initFolders();
	initBuckets( ( bucket ) => navigateTo( { bucket, prefix: '' } ) );
	initUploads();
} );
