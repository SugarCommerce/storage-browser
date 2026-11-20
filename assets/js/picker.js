/**
 * "Choose from your bucket", for any field.
 *
 *     const file = await window.SugarCommerceStoragePicker.open( { accept: [ 'zip' ] } );
 *     // { bucket, key, name, size, mimeType, address: 's3://bucket/key' }, or null
 *
 *     window.SugarCommerceStoragePicker.attach( button, { input: '#file-url', name: '#file-name' } );
 *
 * A button with `data-storage-picker` is attached by itself:
 * `data-storage-picker-target` (the input's selector), `-name` (an input
 * for the file's name), `-returns` (address, path or key), `-accept`
 * (extensions, comma separated) and `-source` (which browser, when a page
 * has several).
 *
 * Each source is also offered to other packages through
 * `window.sugarcommerceFileSources`, a registry of "somewhere a file can
 * come from" that knows nothing about buckets -- so a field library can
 * show a "Choose from your bucket" button without depending on this one.
 */

import domReady from '@wordpress/dom-ready';

import { openPicker } from './picker/dialog';
import { valueFor } from './picker/listing';
import { setValue } from './lib/host-page';

/**
 * The sources PHP configured, by id.
 *
 * @return {Object} Source id => configuration.
 */
const sources = () => window.SugarCommerceStoragePickers || {};

/**
 * One source's configuration: the one asked for, or the first.
 *
 * @param {string} id A source id; '' for the first.
 * @return {Object|null} Its configuration.
 */
function sourceFor( id = '' ) {
	const all = sources();

	if ( id && all[ id ] ) {
		return all[ id ];
	}

	const first = Object.keys( all )[ 0 ];

	return first ? all[ first ] : null;
}

/**
 * Open the picker.
 *
 * @param {Object} options source, bucket, prefix, accept, title.
 * @return {Promise<Object|null>} The chosen file, or null.
 */
export function open( options = {} ) {
	const source = sourceFor( options.source );

	if ( ! source ) {
		return Promise.reject(
			new Error( 'No storage picker is configured on this page.' )
		);
	}

	return openPicker( source, options );
}

/**
 * A list of extensions out of an attribute.
 *
 * @param {string|string[]} accept "zip, .wav" or [ 'zip' ].
 * @return {string[]} Extensions.
 */
const extensions = ( accept ) =>
	( Array.isArray( accept ) ? accept : String( accept || '' ).split( ',' ) )
		.map( ( item ) => item.trim().replace( /^\./, '' ) )
		.filter( Boolean );

/**
 * An element out of a selector or an element.
 *
 * @param {string|Element|null} target A selector or an element.
 * @return {Element|null} The element.
 */
const elementOf = ( target ) =>
	typeof target === 'string'
		? document.querySelector( target )
		: target || null;

/**
 * Make a button open the picker and fill an input with the choice.
 *
 * @param {Element} button  The button.
 * @param {Object}  options input, name, returns, accept, source, bucket, prefix, onChoose.
 * @return {Function} Detaches it.
 */
export function attach( button, options = {} ) {
	const handler = ( event ) => {
		event.preventDefault();

		open( {
			source: options.source,
			bucket: options.bucket,
			prefix: options.prefix,
			accept: extensions( options.accept ),
			title: options.title,
		} ).then( ( chosen ) => {
			if ( ! chosen ) {
				return;
			}

			setValue(
				elementOf( options.input ),
				valueFor( chosen, options.returns )
			);

			const name = elementOf( options.name );

			if ( name && ! name.value.trim() ) {
				setValue( name, chosen.name );
			}

			options.onChoose?.( chosen );
			button.dispatchEvent(
				new CustomEvent( 'storage-picker:chosen', {
					bubbles: true,
					detail: chosen,
				} )
			);
		} );
	};

	button.addEventListener( 'click', handler );

	return () => button.removeEventListener( 'click', handler );
}

/**
 * Attach every `[data-storage-picker]` button not attached yet.
 *
 * @param {Document|Element} root Where to look.
 */
export function attachAll( root = document ) {
	root.querySelectorAll(
		'[data-storage-picker]:not([data-storage-picker-ready])'
	).forEach( ( button ) => {
		const data = button.dataset;

		button.dataset.storagePickerReady = '1';
		attach( button, {
			input: data.storagePickerTarget,
			name: data.storagePickerName,
			returns: data.storagePickerReturns || 'address',
			accept: data.storagePickerAccept,
			source: data.storagePickerSource,
			bucket: data.storagePickerBucket,
			prefix: data.storagePickerPrefix,
		} );
	} );
}

/**
 * Offer each source to the shared registry other packages read.
 *
 * pick( { accept, returns } ) resolves with { value, name, ...choice } or
 * null, the shape a field needs and no more.
 */
function register() {
	const registry = ( window.sugarcommerceFileSources =
		window.sugarcommerceFileSources || {} );

	Object.keys( sources() ).forEach( ( id ) => {
		registry[ id ] = {
			label: sources()[ id ].label,
			pick: ( { accept = [], returns = 'address' } = {} ) =>
				open( { source: id, accept: extensions( accept ) } ).then(
					( chosen ) =>
						chosen
							? { value: valueFor( chosen, returns ), ...chosen }
							: null
				),
		};
	} );
}

window.SugarCommerceStoragePicker = { open, attach, attachAll };

register();

domReady( () => {
	attachAll();

	// Buttons a repeater or a panel adds later.
	new window.MutationObserver( () => attachAll() ).observe( document.body, {
		childList: true,
		subtree: true,
	} );
} );
