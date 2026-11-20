/**
 * The part arithmetic and the resume state.
 */

import { describe, expect, test } from 'vitest';

import {
	backoff,
	createState,
	fingerprint,
	markDone,
	markMissing,
	missingParts,
	objectKey,
	partLength,
	partRange,
	partSettings,
	progress,
	retryable,
	signingBatch,
	storageMessage,
	usesParts,
} from '../../assets/js/lib/multipart';

const MiB = 1048576;

describe( 'settings', () => {
	test( 'defaults stand in for what a page did not send', () => {
		expect( partSettings() ).toEqual( {
			threshold: 64 * MiB,
			singlePutMax: 5 * 1073741824,
			maxSize: 5 * 1099511627776,
			concurrency: 4,
			signBatch: 20,
			retries: 4,
		} );
	} );

	test( 'counts are never below one', () => {
		const settings = partSettings( {
			concurrency: -3,
			signBatch: -1,
			retries: -2,
		} );

		expect( [
			settings.concurrency,
			settings.signBatch,
			settings.retries,
		] ).toEqual( [ 1, 1, 1 ] );
	} );

	test( 'a file goes in parts from the threshold, and always past one PUT', () => {
		const settings = partSettings( { threshold: 100, singlePutMax: 1000 } );

		expect( usesParts( 99, settings ) ).toBe( false );
		expect( usesParts( 100, settings ) ).toBe( true );
		expect(
			usesParts(
				1001,
				partSettings( { threshold: 1e15, singlePutMax: 1000 } )
			)
		).toBe( true );
		expect(
			usesParts(
				1000,
				partSettings( { threshold: 1e15, singlePutMax: 1000 } )
			)
		).toBe( false );
	} );
} );

describe( 'part maths', () => {
	test( 'every part is the part size but the last', () => {
		expect( partLength( 3500, 1000, 1 ) ).toBe( 1000 );
		expect( partLength( 3500, 1000, 3 ) ).toBe( 1000 );
		expect( partLength( 3500, 1000, 4 ) ).toBe( 500 );
		expect( partLength( 3000, 1000, 3 ) ).toBe( 1000 );
		expect( partLength( 3000, 1000, 4 ) ).toBe( 0 );
	} );

	test( 'a part number turns back into its byte range', () => {
		expect( partRange( 3500, 1000, 1 ) ).toEqual( { start: 0, end: 1000 } );
		expect( partRange( 3500, 1000, 4 ) ).toEqual( {
			start: 3000,
			end: 3500,
		} );
	} );

	test( 'parts storage already holds are counted on resume', () => {
		const state = createState(
			{ id: 'u', part_size: 1000, part_count: 4, done: [ 1, 4 ] },
			3500
		);

		expect( state.doneBytes ).toBe( 1500 );
		expect( missingParts( state ) ).toEqual( [ 2, 3 ] );
	} );

	test( 'a part is counted once, and never one past the end', () => {
		const state = createState(
			{ id: 'u', part_size: 1000, part_count: 2, done: [] },
			1500
		);

		markDone( state, 1 );
		markDone( state, 1 );
		markDone( state, 3 );
		markDone( state, 0 );

		expect( state.doneBytes ).toBe( 1000 );
		expect( [ ...state.done ] ).toEqual( [ 1 ] );
	} );

	test( 'parts storage says are missing are sent again', () => {
		const state = createState(
			{ id: 'u', part_size: 1000, part_count: 3, done: [ 1, 2, 3 ] },
			2500
		);

		markMissing( state, [ 2, 7 ] );

		expect( state.doneBytes ).toBe( 1500 );
		expect( missingParts( state ) ).toEqual( [ 2 ] );
	} );

	test( 'progress is what storage holds plus what is on its way', () => {
		const state = createState(
			{ id: 'u', part_size: 1000, part_count: 3, done: [ 1 ] },
			2500
		);

		state.inFlight.set( 2, 400 );
		expect( progress( state ) ).toEqual( {
			loaded: 1400,
			total: 2500,
			percent: 56,
		} );

		state.inFlight.set( 3, 5000 );
		expect( progress( state ).loaded ).toBe( 2500 );
	} );

	test( 'an empty file is no percent rather than NaN', () => {
		expect(
			progress(
				createState( { id: 'u', part_size: 1, part_count: 0 }, 0 )
			).percent
		).toBe( 0 );
	} );
} );

describe( 'signing and retrying', () => {
	test( 'URLs are signed in batches of the parts that have none', () => {
		const urls = new Map( [ [ 3, 'u3' ] ] );

		expect( signingBatch( 1, [ 2, 3, 4, 5, 6 ], urls, 4 ) ).toEqual( [
			1, 2, 4, 5,
		] );
		expect( signingBatch( 1, [ 1, 2 ], new Map(), 20 ) ).toEqual( [
			1, 2,
		] );
	} );

	test( 'only what may go the second time is retried', () => {
		[ 0, 403, 408, 429, 500, 503 ].forEach( ( status ) =>
			expect( retryable( status ) ).toBe( true )
		);
		[ -1, 400, 404, 409, 412 ].forEach( ( status ) =>
			expect( retryable( status ) ).toBe( false )
		);
	} );

	test( 'each wait doubles', () => {
		expect( [ 1, 2, 3, 4 ].map( backoff ) ).toEqual( [
			1000, 2000, 4000, 8000,
		] );
	} );
} );

describe( 'names', () => {
	test( 'a key is the folder and the name', () => {
		expect( objectKey( '', 'a.zip' ) ).toBe( 'a.zip' );
		expect( objectKey( 'packs', 'a.zip' ) ).toBe( 'packs/a.zip' );
		expect( objectKey( 'packs/', 'a.zip' ) ).toBe( 'packs/a.zip' );
	} );

	test( 'the same file dropped again has the same fingerprint', () => {
		expect(
			fingerprint( { name: 'a.zip', size: 10, lastModified: 5 } )
		).toBe( 'a.zip|10|5' );
	} );

	test( "storage's own words come out of its error document", () => {
		expect(
			storageMessage(
				'<Error><Code>X</Code><Message>Request has expired</Message></Error>'
			)
		).toBe( 'Request has expired' );
		expect( storageMessage( '' ) ).toBe( '' );
	} );
} );
