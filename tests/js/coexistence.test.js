/**
 * Two plugins' copies of this library on one page: each finds its own
 * configuration, starts its own browser and attaches its own buttons.
 */

import { afterEach, expect, test, vi } from 'vitest';

import { entryFor, handleOf, REGISTRY } from '../../assets/js/lib/config';
import { shouldStart } from '../../assets/js/lib/start';
import { createPicker } from '../../assets/js/picker/build';

const A = 'sugarcommerce-storage-browser';
const B = 'myplugin-sugarcommerce-storage-browser';

afterEach( () => {
	delete window[ REGISTRY ];
	document.body.replaceChildren();
} );

test( 'a script finds its build from its own element', () => {
	const element = document.createElement( 'script' );
	element.id = `${ B }-browser-js`;

	expect( handleOf( element ) ).toBe( `${ B }-browser` );
	expect( handleOf( null ) ).toBe( '' );
} );

test( 'two builds keep two entries in the one registry', () => {
	entryFor( `${ A }-browser` ).global = { restUrl: '/a/' };
	entryFor( `${ B }-browser` ).global = { restUrl: '/b/' };

	expect( entryFor( `${ A }-browser` ).global.restUrl ).toBe( '/a/' );
	expect( entryFor( `${ B }-browser` ).global.restUrl ).toBe( '/b/' );
	expect( Object.keys( window[ REGISTRY ] ) ).toEqual( [
		`${ A }-browser`,
		`${ B }-browser`,
	] );
} );

test( 'each build starts a browser only where it drew one, and once', () => {
	document.body.innerHTML = `<div class="s3-browser-container" data-storage-browser="${ B }-browser"></div>`;

	const a = entryFor( `${ A }-browser` );
	const b = entryFor( `${ B }-browser` );

	expect( shouldStart( a, `${ A }-browser` ) ).toBe( false );
	expect( shouldStart( b, `${ B }-browser` ) ).toBe( true );

	b.started = true;

	expect( shouldStart( b, `${ B }-browser` ) ).toBe( false );
	expect( shouldStart( {}, '' ) ).toBe( false );
} );

test( 'each build attaches only the picker buttons it drew', async () => {
	document.body.innerHTML = `
		<input id="a-url"><button id="a" data-storage-picker="${ A }-picker" data-storage-picker-target="#a-url"></button>
		<input id="b-url"><button id="b" data-storage-picker="${ B }-picker" data-storage-picker-target="#b-url"></button>
	`;

	const chosen = {
		bucket: 'files',
		key: 'x.zip',
		name: 'x.zip',
		address: 's3://files/x.zip',
	};
	const dialogA = vi.fn( () => Promise.resolve( chosen ) );
	const dialogB = vi.fn( () => Promise.resolve( chosen ) );

	const a = createPicker(
		`${ A }-picker`,
		{ pickers: { a_source: { label: 'A' } } },
		dialogA
	);
	const b = createPicker(
		`${ B }-picker`,
		{ pickers: { b_source: { label: 'B' } } },
		dialogB
	);

	// The first copy to look must leave the other's button alone.
	a.attachAll();

	expect( document.getElementById( 'b' ).dataset.storagePickerReady ).toBe(
		undefined
	);

	b.attachAll();
	document.getElementById( 'a' ).click();
	document.getElementById( 'b' ).click();
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

	expect( dialogA ).toHaveBeenCalledTimes( 1 );
	expect( dialogA.mock.calls[ 0 ][ 0 ] ).toEqual( { label: 'A' } );
	expect( dialogB ).toHaveBeenCalledTimes( 1 );
	expect( dialogB.mock.calls[ 0 ][ 0 ] ).toEqual( { label: 'B' } );
	expect( document.getElementById( 'a-url' ).value ).toBe(
		's3://files/x.zip'
	);
	expect( document.getElementById( 'b-url' ).value ).toBe(
		's3://files/x.zip'
	);
} );

test( 'both builds offer their sources side by side', () => {
	const registry = {};

	createPicker( `${ A }-picker`, {
		pickers: { sugarcommerce_storage_browser_r2: { label: 'A' } },
	} ).register( registry );
	createPicker( `${ B }-picker`, {
		pickers: {
			myplugin_sugarcommerce_storage_browser_r2: { label: 'B' },
		},
	} ).register( registry );

	expect( Object.keys( registry ) ).toEqual( [
		'sugarcommerce_storage_browser_r2',
		'myplugin_sugarcommerce_storage_browser_r2',
	] );
	expect( typeof registry.sugarcommerce_storage_browser_r2.pick ).toBe(
		'function'
	);
} );
