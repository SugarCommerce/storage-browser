/**
 * The uploader against a fake bucket: parts, checksums, retries, resuming
 * from what storage kept, and cancelling.
 */

import { describe, expect, test } from 'vitest';

import { file, md5, world } from './support/fake-storage';

describe( 'uploads', () => {
	test( 'a small file goes in one PUT', async () => {
		const { state, uploader } = world( {
			partSize: 1000,
			settings: { threshold: 1e6 },
		} );
		const { bytes, file: f } = file( 500 );

		const answer = await uploader.upload( f, {
			bucket: 'files',
			key: 'one.zip',
		} ).promise;

		expect( answer ).toEqual( { key: 'one.zip' } );
		expect( state.objects[ 'one.zip' ] ).toEqual( bytes );
		expect( state.calls ).toEqual( [ 'uploadUrl' ] );
	} );

	test( 'a file over the threshold goes in parts, each with its Content-MD5', async () => {
		const { state, uploader } = world( { partSize: 1000 } );
		const { bytes, file: f } = file( 24500 );

		const result = await uploader.upload( f, {
			bucket: 'files',
			key: 'packs/pack.zip',
		} ).promise;

		expect( result.address ).toBe( 's3://bucket/packs/pack.zip' );
		expect( state.objects[ 'packs/pack.zip' ] ).toEqual( bytes );
		expect( state.puts ).toHaveLength( 25 );
		state.puts.forEach( ( put ) => expect( put.md5 ).not.toBe( '' ) );
		// Signed in batches, not part by part.
		expect(
			state.calls.filter( ( c ) => c === 'signParts' ).length
		).toBeLessThan( 25 );
	} );

	test( 'progress counts what storage holds, never past the size', async () => {
		const seen = [];
		const { uploader } = world( { partSize: 1000 } );
		const { file: f } = file( 3500 );

		await uploader.upload( f, {
			bucket: 'files',
			key: 'p.zip',
			onProgress: ( p ) => seen.push( p ),
		} ).promise;

		expect( seen.at( -1 ) ).toEqual( {
			loaded: 3500,
			total: 3500,
			percent: 100,
		} );
		seen.forEach( ( p ) => expect( p.loaded ).toBeLessThanOrEqual( 3500 ) );
	} );

	test( 'a part that fails for a moment is sent again', async () => {
		const { state, uploader } = world( {
			partSize: 1000,
			faults: [
				{ part: 3, times: 1, status: 503 },
				{ part: 5, times: 2, status: 0 },
			],
		} );
		const { bytes, file: f } = file( 8000 );

		await uploader.upload( f, { bucket: 'files', key: 'a.zip' } ).promise;

		expect( state.objects[ 'a.zip' ] ).toEqual( bytes );
		expect( state.puts.filter( ( p ) => p.number === 3 ) ).toHaveLength(
			2
		);
		expect( state.puts.filter( ( p ) => p.number === 5 ) ).toHaveLength(
			3
		);
	} );

	test( 'a refusal that will not change is not retried', async () => {
		const { state, uploader } = world( {
			partSize: 1000,
			settings: { concurrency: 1 },
			faults: [
				{ part: 2, times: 1, status: 400, message: 'InvalidPart' },
			],
		} );
		const { file: f } = file( 3000 );

		await expect(
			uploader.upload( f, { bucket: 'files', key: 'r.zip' } ).promise
		).rejects.toThrow( 'Part 2 failed: InvalidPart Drop it again.' );
		expect( state.puts.filter( ( p ) => p.number === 2 ) ).toHaveLength(
			1
		);
	} );

	test( 'an expired URL is signed again', async () => {
		const { state, uploader } = world( {
			partSize: 1000,
			faults: [
				{
					part: 2,
					times: 1,
					status: 403,
					message: 'Request has expired',
				},
			],
		} );
		const { bytes, file: f } = file( 4000 );

		await uploader.upload( f, { bucket: 'files', key: 'a.zip' } ).promise;

		expect( state.objects[ 'a.zip' ] ).toEqual( bytes );
		expect(
			state.calls.filter( ( c ) => c === 'signParts' ).length
		).toBeGreaterThanOrEqual( 2 );
	} );

	test( 'an interrupted upload resumes from the parts storage kept', async () => {
		const notes = [];
		const { state, uploader } = world( {
			partSize: 1000,
			settings: { concurrency: 1 },
			faults: [ { part: 4, times: 3, status: 0 } ],
		} );
		const { bytes, file: f } = file( 10000 );

		await expect(
			uploader.upload( f, { bucket: 'files', key: 'big.zip' } ).promise
		).rejects.toThrow( /Part 4 failed/ );
		expect( state.objects[ 'big.zip' ] ).toBeUndefined();
		expect(
			Object.keys( Object.values( state.uploads )[ 0 ].parts ).map(
				Number
			)
		).toEqual( [ 1, 2, 3 ] );

		state.puts.length = 0;

		// The same file dropped again.
		const again = await uploader.upload( f, {
			bucket: 'files',
			key: 'big.zip',
			onNote: ( text ) => notes.push( text ),
		} ).promise;

		expect( again.address ).toBe( 's3://bucket/big.zip' );
		expect( state.objects[ 'big.zip' ] ).toEqual( bytes );
		expect(
			state.puts.map( ( p ) => p.number ).sort( ( a, b ) => a - b )
		).toEqual( [ 4, 5, 6, 7, 8, 9, 10 ] );
		expect( notes ).toContain( 'Resuming: 3 of 10' );
	} );

	test( 'a part whose answer was lost is not sent again on resume', async () => {
		const { state, uploader } = world( {
			partSize: 1000,
			settings: { concurrency: 1, retries: 1 },
			faults: [ { part: 2, times: 1, status: 0, keep: true } ],
		} );
		const { bytes, file: f } = file( 3000 );

		await expect(
			uploader.upload( f, { bucket: 'files', key: 'k.zip' } ).promise
		).rejects.toThrow();

		state.puts.length = 0;
		await uploader.upload( f, { bucket: 'files', key: 'k.zip' } ).promise;

		expect( state.objects[ 'k.zip' ] ).toEqual( bytes );
		expect( state.puts.map( ( p ) => p.number ) ).toEqual( [ 3 ] );
	} );

	test( 'parts storage lost before assembly are sent again, once', async () => {
		const { state, uploader } = world( { partSize: 1000 } );
		const { bytes, file: f } = file( 3500 );

		state.beforeComplete = ( upload ) => delete upload.parts[ 2 ];

		await uploader.upload( f, { bucket: 'files', key: 'l.zip' } ).promise;

		expect( state.objects[ 'l.zip' ] ).toEqual( bytes );
		expect( state.puts.filter( ( p ) => p.number === 2 ) ).toHaveLength(
			2
		);
		expect(
			state.calls.filter( ( c ) => c === 'completeUpload' )
		).toHaveLength( 2 );
	} );

	test( 'parts lost again at the second assembly fail rather than go round for ever', async () => {
		const { state, uploader } = world( { partSize: 1000 } );
		const { file: f } = file( 2500 );
		const lose = ( upload ) => {
			delete upload.parts[ 1 ];
			state.beforeComplete = lose;
		};

		state.beforeComplete = lose;

		await expect(
			uploader.upload( f, { bucket: 'files', key: 'n.zip' } ).promise
		).rejects.toMatchObject( { code: 's3_upload_incomplete' } );
		expect(
			state.calls.filter( ( c ) => c === 'completeUpload' )
		).toHaveLength( 2 );
	} );

	test( 'a bucket whose CORS refuses Content-MD5 still gets the file, without checksums', async () => {
		const notes = [];
		const { state, uploader } = world( {
			partSize: 1000,
			corsRefusesMd5: true,
		} );
		const { bytes, file: f } = file( 5000 );

		await uploader.upload( f, {
			bucket: 'files',
			key: 'm.zip',
			onNote: ( n ) => notes.push( n ),
		} ).promise;

		expect( state.objects[ 'm.zip' ] ).toEqual( bytes );
		expect( state.puts ).toHaveLength( 5 );
		expect( state.puts.every( ( p ) => p.md5 === '' ) ).toBe( true );
		expect( notes ).toContain( 'No checksums' );
	} );

	test( 'a damaged part is refused by its checksum and not kept', async () => {
		const { state, uploader } = world( {
			partSize: 1000,
			settings: { concurrency: 1, retries: 1 },
			checksum: () => md5( 'something else' ),
		} );
		const { file: f } = file( 2000 );

		await expect(
			uploader.upload( f, { bucket: 'files', key: 'd.zip' } ).promise
		).rejects.toThrow( /BadDigest/ );
		expect(
			Object.keys( Object.values( state.uploads )[ 0 ].parts )
		).toEqual( [] );
	} );

	test( 'cancelling stops the parts and frees the upload in storage', async () => {
		const held = {};
		const { state, uploader } = world( {
			partSize: 1000,
			settings: { concurrency: 1 },
			// Cancel is pressed while the third part is on its way.
			onPut: ( s ) => s.puts.length === 3 && held.sending.abort(),
		} );
		const { file: f } = file( 50000 );

		held.sending = uploader.upload( f, { bucket: 'files', key: 'c.zip' } );

		await expect( held.sending.promise ).rejects.toThrow(
			'Upload cancelled'
		);
		await new Promise( ( resolve ) => setTimeout( resolve, 5 ) );

		expect( state.calls ).toContain( 'abortUpload' );
		expect( state.uploads ).toEqual( {} );
		expect( state.puts ).toHaveLength( 3 );
	} );

	test( 'a file larger than storage keeps is refused before anything is sent', async () => {
		const { state, uploader } = world( {
			partSize: 1000,
			settings: { maxSize: 100 },
		} );
		const { file: f } = file( 101, 'huge.bin' );

		await expect(
			uploader.upload( f, { bucket: 'files', key: 'h.bin' } ).promise
		).rejects.toThrow( 'huge.bin too large' );
		expect( state.calls ).toEqual( [] );
	} );
} );
