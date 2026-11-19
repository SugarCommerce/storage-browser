/**
 * A bucket and the WordPress routes in front of it, for the uploader.
 *
 * `request` answers the upload routes as Rest\Controller does; `put` is the
 * bucket, which keeps a part only when its Content-MD5 matches the bytes, and
 * can be told to fail a part a few times in the ways a network does.
 */

import { createHash, randomBytes } from 'node:crypto';

import { createUploader } from '../../../src/js/lib/uploader';
import { partSettings } from '../../../src/js/lib/multipart';

export const STRINGS = {
	uploadCancelled: 'Upload cancelled',
	networkError: 'Network error',
	uploadFailedStatus: 'Upload failed with status',
	failedPresignedUrl: 'Failed to get upload URL',
	resuming: 'Resuming: {done} of {total}',
	assembling: 'Assembling',
	retrying: 'Retrying {part}',
	noChecksums: 'No checksums',
	tooLarge: '{filename} too large',
	partFailed: 'Part {part} failed: {reason}',
	resumeHint: 'Drop it again.',
	corsError: 'CORS refused it',
};

export const md5 = ( bytes ) =>
	createHash( 'md5' ).update( Buffer.from( bytes ) ).digest( 'base64' );

/**
 * A file of random bytes.
 *
 * @param {number} size Bytes.
 * @param {string} name Its name.
 * @return {{bytes: Buffer, file: File}} The bytes and the file.
 */
export function file( size, name = 'pack.zip' ) {
	const bytes = randomBytes( size );

	return {
		bytes,
		file: new File( [ bytes ], name, { lastModified: 1700000000000 } ),
	};
}

/**
 * A storage world and an uploader talking to it.
 *
 * @param {Object} options partSize, settings, faults, corsRefusesMd5, onPut, checksum.
 * @return {{state: Object, uploader: Object}} The world.
 */
export function world( options ) {
	const state = {
		partSize: options.partSize,
		uploads: {},
		objects: {},
		puts: [],
		calls: [],
		faults: options.faults || [],
		corsRefusesMd5: !! options.corsRefusesMd5,
		serial: 0,
		beforeComplete: null,
	};

	const fail = ( status, payload ) => {
		const error = new Error( payload.message );
		error.code = payload.code || '';
		error.data = payload.data || {};
		error.status = status;

		return error;
	};

	const routes = {
		uploadUrl: ( data ) => ( {
			url: `https://bucket.example/${ data.key }?single=1`,
		} ),

		startUpload: ( data ) => {
			const existing = Object.values( state.uploads ).find(
				( upload ) => upload.fingerprint === data.fingerprint
			);
			const upload = existing || {
				id: ( ++state.serial ).toString( 16 ).padStart( 32, '0' ),
				key: data.key,
				size: data.size,
				fingerprint: data.fingerprint,
				parts: {},
			};

			state.uploads[ upload.id ] = upload;

			return {
				id: upload.id,
				part_size: state.partSize,
				part_count: Math.ceil( upload.size / state.partSize ),
				done: Object.keys( upload.parts ).map( Number ),
				resumed: !! existing,
				checksums: ! state.corsRefusesMd5,
			};
		},

		signParts: ( data, upload ) => {
			const urls = {};

			data.parts.forEach( ( number ) => {
				urls[ number ] =
					`https://bucket.example/${ upload.key }?partNumber=${ number }&uploadId=${ upload.id }&sig=${ ++state.serial }`;
			} );

			return { urls };
		},

		completeUpload: ( data, upload ) => {
			if ( state.beforeComplete ) {
				const run = state.beforeComplete;

				state.beforeComplete = null;
				run( upload );
			}

			const count = Math.ceil( upload.size / state.partSize );
			const missing = [];

			for ( let n = 1; n <= count; n++ ) {
				const expected = Math.min(
					state.partSize,
					upload.size - ( n - 1 ) * state.partSize
				);

				if (
					! upload.parts[ n ] ||
					upload.parts[ n ].length !== expected
				) {
					missing.push( n );
				}
			}

			if ( missing.length ) {
				throw fail( 409, {
					code: 's3_upload_incomplete',
					message: 'missing',
					data: { status: 409, missing },
				} );
			}

			state.objects[ upload.key ] = Buffer.concat(
				Array.from(
					{ length: count },
					( _, i ) => upload.parts[ i + 1 ]
				)
			);
			delete state.uploads[ upload.id ];

			return { address: `s3://bucket/${ upload.key }` };
		},

		abortUpload: ( data, upload ) => {
			delete state.uploads[ upload.id ];

			return { aborted: true };
		},
	};

	const request = ( name, data ) =>
		new Promise( ( resolve, reject ) => {
			setImmediate( () => {
				state.calls.push( name );

				try {
					const upload = data.id ? state.uploads[ data.id ] : null;

					if ( data.id && ! upload ) {
						throw fail( 404, {
							code: 'rest_upload_not_found',
							message: 'gone',
						} );
					}

					resolve( routes[ name ]( data, upload ) );
				} catch ( error ) {
					reject( error );
				}
			} );
		} );

	const put = ( url, body, { headers = {}, onProgress } ) => {
		const address = new URL( url );
		const id = address.searchParams.get( 'uploadId' );
		const number = Number( address.searchParams.get( 'partNumber' ) );
		let aborted = false;
		let rejectWith = null;

		const promise = ( async () => {
			const bytes = Buffer.from(
				body instanceof ArrayBuffer
					? new Uint8Array( body )
					: new Uint8Array( await body.arrayBuffer() )
			);
			const record = {
				number,
				md5: headers[ 'Content-MD5' ] || '',
				size: bytes.length,
			};
			const error = ( message, status ) =>
				Object.assign( new Error( message ), { status } );

			state.puts.push( record );
			options.onPut?.( state );

			await new Promise( ( resolve ) => setImmediate( resolve ) );

			if ( aborted ) {
				throw error( 'Upload cancelled', -1 );
			}

			if ( address.searchParams.get( 'single' ) ) {
				state.objects[ address.pathname.slice( 1 ) ] = bytes;
				onProgress?.( bytes.length, bytes.length );
				return;
			}

			// A browser refuses the request itself when the preflight does not
			// allow a header: no status at all.
			if ( state.corsRefusesMd5 && record.md5 ) {
				throw error( 'Network error', 0 );
			}

			const fault = state.faults.find(
				( f ) => f.part === number && f.times > 0
			);

			if ( fault ) {
				fault.times--;

				if ( fault.keep ) {
					state.uploads[ id ].parts[ number ] = bytes;
				}

				throw error(
					fault.status === 0
						? 'Network error'
						: fault.message || 'nope',
					fault.status
				);
			}

			if ( ! state.uploads[ id ] ) {
				throw error( 'NoSuchUpload', 404 );
			}

			if ( record.md5 && record.md5 !== md5( bytes ) ) {
				throw error( 'BadDigest', 400 );
			}

			onProgress?.( bytes.length, bytes.length );
			state.uploads[ id ].parts[ number ] = bytes;
		} )();

		return {
			promise: promise.catch( ( e ) =>
				Promise.reject( rejectWith || e )
			),
			abort: () => {
				aborted = true;
				rejectWith = Object.assign( new Error( 'Upload cancelled' ), {
					status: -1,
				} );
			},
		};
	};

	const uploader = createUploader( {
		request,
		put,
		checksum: options.checksum || md5,
		settings: partSettings( {
			threshold: 10,
			concurrency: 3,
			signBatch: 4,
			retries: 3,
			...( options.settings || {} ),
		} ),
		strings: STRINGS,
		wait: () => Promise.resolve(),
	} );

	return { state, uploader };
}
