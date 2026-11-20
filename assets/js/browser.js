/**
 * The bucket browser, in the media frame's tab or on an admin page.
 */

import domReady from '@wordpress/dom-ready';

import { initBuckets } from './lib/buckets';
import { entryFor, handle } from './lib/config';
import { shouldStart } from './lib/start';
import { initFiles } from './lib/files';
import { initFolders } from './lib/folders';
import { initList, navigateTo } from './lib/list';
import { initUploads } from './lib/upload-panel';

domReady( () => {
	const entry = entryFor();

	// Another plugin's copy may load on the page too; it starts its own.
	if ( ! shouldStart( entry, handle ) ) {
		return;
	}

	entry.started = true;

	initList();
	initFiles();
	initFolders();
	initBuckets( ( bucket ) => navigateTo( { bucket, prefix: '' } ) );
	initUploads();
} );
