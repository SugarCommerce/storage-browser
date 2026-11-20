/**
 * What the picker shows and hands back.
 */

import { describe, expect, test } from 'vitest';

import {
	accepts,
	choice,
	crumbs,
	entries,
	parentOf,
	valueFor,
} from '../../assets/js/picker/listing';

describe( 'folders', () => {
	test( 'a prefix is a trail of folders', () => {
		expect( crumbs( 'packs/drums/' ) ).toEqual( [
			{ name: 'packs', prefix: 'packs/' },
			{ name: 'drums', prefix: 'packs/drums/' },
		] );
		expect( crumbs( '' ) ).toEqual( [] );
		expect( crumbs( '/packs//drums' ) ).toHaveLength( 2 );
	} );

	test( 'up from a folder is its parent, and the top stays the top', () => {
		expect( parentOf( 'packs/drums/' ) ).toBe( 'packs/' );
		expect( parentOf( 'packs/' ) ).toBe( '' );
		expect( parentOf( '' ) ).toBe( '' );
	} );
} );

describe( 'entries', () => {
	const listing = {
		folders: [ { name: 'drums', prefix: 'drums/' } ],
		objects: [
			{ key: 'Mix.WAV', name: 'Mix.WAV', size: 10 },
			{ key: 'cover.jpg', name: 'cover.jpg', size: 5 },
			{ key: 'readme', name: 'readme', size: 1 },
		],
	};

	test( 'folders come first, then files', () => {
		expect(
			entries( listing ).map( ( e ) => `${ e.type }:${ e.name }` )
		).toEqual( [
			'folder:drums',
			'file:Mix.WAV',
			'file:cover.jpg',
			'file:readme',
		] );
	} );

	test( 'only the extensions asked for can be chosen, whatever their case', () => {
		expect(
			entries( listing, { accept: [ '.wav' ] } ).map( ( e ) => e.name )
		).toEqual( [ 'drums', 'Mix.WAV' ] );
		expect( accepts( 'readme', [ 'zip' ] ) ).toBe( false );
		expect( accepts( 'readme', [] ) ).toBe( true );
	} );

	test( 'typed words narrow the list at once', () => {
		expect(
			entries( listing, { term: ' MIX ' } ).map( ( e ) => e.name )
		).toEqual( [ 'Mix.WAV' ] );
	} );
} );

describe( 'a choice', () => {
	const chosen = choice( 'files', {
		key: 'packs/a b.zip',
		name: 'a b.zip',
		size: '42',
		mime_type: 'application/zip',
	} );

	test( 'carries the bucket, key, name, size and an s3:// address', () => {
		expect( chosen ).toEqual( {
			bucket: 'files',
			key: 'packs/a b.zip',
			name: 'a b.zip',
			size: 42,
			mimeType: 'application/zip',
			address: 's3://files/packs/a b.zip',
		} );
	} );

	test( 'a field gets the address, the path or the key', () => {
		expect( valueFor( chosen ) ).toBe( 's3://files/packs/a b.zip' );
		expect( valueFor( chosen, 'path' ) ).toBe( 'files/packs/a b.zip' );
		expect( valueFor( chosen, 'key' ) ).toBe( 'packs/a b.zip' );
	} );
} );
