/**
 * The picker dialog driven from the keyboard, against a fake listing.
 */

import { afterEach, beforeAll, beforeEach, expect, test, vi } from 'vitest';

vi.mock( '../../assets/js/lib/api', () => ( {
	request: vi.fn(),
} ) );

import { request } from '../../assets/js/lib/api';
import { openPicker } from '../../assets/js/picker/dialog';

const LISTINGS = {
	'': {
		folders: [ { name: 'packs', prefix: 'packs/' } ],
		objects: [ { key: 'readme.txt', name: 'readme.txt', size: 10 } ],
	},
	'packs/': {
		folders: [],
		objects: [
			{
				key: 'packs/drums.zip',
				name: 'drums.zip',
				size: 2048,
				mime_type: 'application/zip',
			},
			{ key: 'packs/keys.zip', name: 'keys.zip', size: 4096 },
		],
	},
};

const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
const key = ( input, name ) =>
	input.dispatchEvent(
		new KeyboardEvent( 'keydown', {
			key: name,
			bubbles: true,
			cancelable: true,
		} )
	);
const source = {
	restUrl: '/r',
	nonce: 'n',
	defaultBucket: 'files',
	buckets: [ { name: 'files' } ],
	icons: {},
};

beforeAll( () => {
	// jsdom has <dialog> but not the modal part of it.
	HTMLDialogElement.prototype.showModal ??= function () {
		this.open = true;
	};
	HTMLDialogElement.prototype.close ??= function () {
		this.open = false;
	};
	Element.prototype.scrollIntoView ??= () => {};
} );

beforeEach( () => {
	request.mockImplementation( ( name, data ) =>
		Promise.resolve(
			name === 'browse'
				? LISTINGS[ data.prefix ]
				: { buckets: [ { name: 'files' } ] }
		)
	);
} );

afterEach( () => {
	document.body.innerHTML = '';
	request.mockReset();
} );

test( 'arrows, Enter into a folder, Enter on a file chooses it', async () => {
	const result = openPicker( source );
	await settle();

	const input = document.querySelector( '.sc-storage-picker__input' );
	const options = () =>
		Array.from( document.querySelectorAll( '[role="option"]' ) );

	expect( document.activeElement ).toBe( input );
	expect(
		options().map( ( o ) =>
			o.textContent.replace( /\s+/g, ' ' ).trim()
		)[ 0 ]
	).toContain( 'packs' );

	// The folder is active first; Enter opens it.
	expect( input.getAttribute( 'aria-activedescendant' ) ).toBe(
		options()[ 0 ].id
	);
	key( input, 'Enter' );
	await settle();

	expect( request ).toHaveBeenLastCalledWith(
		'browse',
		expect.objectContaining( { prefix: 'packs/' } ),
		expect.anything()
	);

	key( input, 'ArrowDown' );
	expect( options()[ 1 ].getAttribute( 'aria-selected' ) ).toBe( 'true' );
	expect(
		document.querySelector( '.sc-storage-picker__choose' ).disabled
	).toBe( false );

	key( input, 'Enter' );

	await expect( result ).resolves.toEqual( {
		bucket: 'files',
		key: 'packs/keys.zip',
		name: 'keys.zip',
		size: 4096,
		mimeType: '',
		address: 's3://files/packs/keys.zip',
	} );
	expect( document.querySelector( '.sc-storage-picker' ) ).toBeNull();
} );

test( 'Backspace in an empty box goes up a folder', async () => {
	openPicker( source, { prefix: 'packs/' } );
	await settle();

	const input = document.querySelector( '.sc-storage-picker__input' );

	key( input, 'Backspace' );
	await settle();

	expect( request ).toHaveBeenLastCalledWith(
		'browse',
		expect.objectContaining( { prefix: '' } ),
		expect.anything()
	);
	expect(
		document.querySelector( '[aria-current="location"]' ).textContent
	).toContain( 'files' );
} );

test( 'Escape and Cancel choose nothing', async () => {
	const escaped = openPicker( source );
	await settle();
	key( document.querySelector( '.sc-storage-picker__input' ), 'Escape' );
	await expect( escaped ).resolves.toBeNull();

	const cancelled = openPicker( source );
	await settle();
	document.querySelector( '.sc-storage-picker__cancel' ).click();
	await expect( cancelled ).resolves.toBeNull();
} );

test( 'only the extensions asked for are offered', async () => {
	openPicker( source, { prefix: '', accept: [ 'zip' ] } );
	await settle();

	expect( document.body.textContent ).not.toContain( 'readme.txt' );
} );

test( 'a search asks the server, and a stale answer is dropped', async () => {
	let release;
	request.mockImplementation( ( name, data ) => {
		if ( name !== 'browse' ) {
			return Promise.resolve( { buckets: [] } );
		}

		if ( data.search === 'dr' ) {
			return new Promise( ( resolve ) => {
				release = () =>
					resolve( {
						folders: [],
						objects: [
							{ key: 'old.zip', name: 'old.zip', size: 1 },
						],
					} );
			} );
		}

		return Promise.resolve(
			data.search
				? {
						folders: [],
						objects: [ LISTINGS[ 'packs/' ].objects[ 0 ] ],
					}
				: LISTINGS[ '' ]
		);
	} );

	vi.useFakeTimers();
	openPicker( source );
	await vi.runAllTimersAsync();

	const input = document.querySelector( '.sc-storage-picker__input' );

	input.value = 'dr';
	input.dispatchEvent( new Event( 'input' ) );
	await vi.advanceTimersByTimeAsync( 300 );

	input.value = 'drums';
	input.dispatchEvent( new Event( 'input' ) );
	await vi.advanceTimersByTimeAsync( 300 );

	release();
	await vi.runAllTimersAsync();
	vi.useRealTimers();

	expect( document.body.textContent ).toContain( 'drums.zip' );
	expect( document.body.textContent ).not.toContain( 'old.zip' );
	expect(
		document.querySelector( '.sc-storage-picker__path' ).textContent
	).toBe( 'packs/' );
} );
