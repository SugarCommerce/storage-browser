/**
 * "Choose from your bucket", for any field.
 *
 * Each build publishes its picker in its own entry of
 * `window.SugarCommerceStorageBrowser`, keyed by the script's handle
 * (Picker::handle() in PHP):
 *
 *     const picker = window.SugarCommerceStorageBrowser[ handle ];
 *     const file = await picker.open( { accept: [ 'zip' ] } );
 *     // { bucket, key, name, size, mimeType, address: 's3://bucket/key' }, or null
 *
 *     picker.attach( button, { input: '#file-url', name: '#file-name' } );
 *
 * A button whose `data-storage-picker` is the handle is attached by itself
 * (Picker::button() draws one): `data-storage-picker-target` (the input's
 * selector), `-name` (an input for the file's name), `-returns` (address,
 * path or key), `-accept` (extensions, comma separated) and `-source`
 * (which browser, when a page has several).
 *
 * Each source is also offered to other packages through
 * `window.sugarcommerceFileSources`, a registry of "somewhere a file can
 * come from" that knows nothing about buckets -- so a field library can
 * show a "Choose from your bucket" button without depending on this one.
 */

import domReady from '@wordpress/dom-ready';

import { entryFor, handle } from './lib/config';
import { createPicker } from './picker/build';

const entry = entryFor();
const picker = createPicker( handle, entry );

entry.open = picker.open;
entry.attach = picker.attach;
entry.attachAll = picker.attachAll;

picker.register(
	( window.sugarcommerceFileSources = window.sugarcommerceFileSources || {} )
);

domReady( () => {
	picker.attachAll();

	// Buttons a repeater or a panel adds later.
	new window.MutationObserver( () => picker.attachAll() ).observe(
		document.body,
		{
			childList: true,
			subtree: true,
		}
	);
} );
