/**
 * One build's picker: its sources, its API, and the buttons it attaches.
 *
 * Another plugin may bundle its own copy of this library, with its own
 * picker script on the same page. Each copy is made from its own registry
 * entry and attaches only the buttons its build drew -- Picker::button()
 * puts the script's handle in `data-storage-picker` -- so neither opens the
 * other's buckets with its own REST route and nonce.
 */

import { openPicker } from './dialog';
import { valueFor } from './listing';
import { setValue } from '../lib/host-page';

/**
 * A list of extensions out of an attribute.
 *
 * @param {string|string[]} accept "zip, .wav" or [ 'zip' ].
 * @return {string[]} Extensions.
 */
export const extensions = ( accept ) =>
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
 * Make one build's picker.
 *
 * @param {string}   key    The build's picker script handle.
 * @param {Object}   entry  Its registry entry; `pickers` is what PHP configured, by source id.
 * @param {Function} dialog Opens the dialog: ( source, options ) => Promise.
 * @return {Object} open, attach, attachAll, register.
 */
export function createPicker( key, entry, dialog = openPicker ) {
	const sources = () => entry.pickers || {};

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
	function open( options = {} ) {
		const source = sourceFor( options.source );

		if ( ! source ) {
			return Promise.reject(
				new Error( 'No storage picker is configured on this page.' )
			);
		}

		return dialog( source, options );
	}

	/**
	 * Make a button open the picker and fill an input with the choice.
	 *
	 * @param {Element} button  The button.
	 * @param {Object}  options input, name, returns, accept, source, bucket, prefix, onChoose.
	 * @return {Function} Detaches it.
	 */
	function attach( button, options = {} ) {
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
	 * Attach every button this build drew that is not attached yet.
	 *
	 * @param {Document|Element} root Where to look.
	 */
	function attachAll( root = document ) {
		root.querySelectorAll(
			'[data-storage-picker]:not([data-storage-picker-ready])'
		).forEach( ( button ) => {
			const data = button.dataset;

			if ( data.storagePicker !== key ) {
				return;
			}

			data.storagePickerReady = '1';
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
	 * Its ids are Picker::id(), named for the build, so two plugins' copies
	 * add two sources rather than one overwriting the other. pick( { accept,
	 * returns } ) resolves with { value, name, ...choice } or null, the shape
	 * a field needs and no more.
	 *
	 * @param {Object} registry window.sugarcommerceFileSources.
	 */
	function register( registry ) {
		Object.keys( sources() ).forEach( ( id ) => {
			registry[ id ] = {
				label: sources()[ id ].label,
				pick: ( { accept = [], returns = 'address' } = {} ) =>
					open( { source: id, accept: extensions( accept ) } ).then(
						( chosen ) =>
							chosen
								? {
										value: valueFor( chosen, returns ),
										...chosen,
									}
								: null
					),
			};
		} );
	}

	return { open, attach, attachAll, register };
}
