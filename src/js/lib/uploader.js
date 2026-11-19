/**
 * Sending a file to storage from the browser.
 *
 * A small file goes in one presigned PUT. A file from the multipart
 * threshold up -- and every file past the 5 GB one PUT may carry -- goes in
 * parts: the server starts the upload (or finds the one this file began)
 * and signs part URLs a batch at a time, the browser PUTs a few parts at
 * once, each with a Content-MD5 storage checks, retrying a part that fails
 * with a backoff and signing an expired URL again, and the server assembles
 * what storage says it holds. A part storage reports missing at the end is
 * sent again, once.
 *
 * Everything that touches the network is handed in, so the whole flow runs
 * under a test with a fake server.
 */

import {
	backoff,
	createState,
	fingerprint,
	markDone,
	markMissing,
	missingParts,
	partRange,
	progress,
	retryable,
	signingBatch,
	storageMessage,
	usesParts,
} from './multipart';

/**
 * PUT bytes to a URL with XMLHttpRequest, for its upload progress.
 *
 * @param {string}   url                The presigned URL.
 * @param {*}        body               A Blob, File or ArrayBuffer.
 * @param {Object}   options            Options.
 * @param {Object}   options.headers    Request headers.
 * @param {Function} options.onProgress Called with the bytes sent so far.
 * @param {Object}   options.strings    networkError, uploadCancelled, uploadFailedStatus.
 * @return {{promise: Promise<void>, abort: Function}} The request.
 */
export function xhrPut(
	url,
	body,
	{ headers = {}, onProgress, strings = {} } = {}
) {
	const xhr = new window.XMLHttpRequest();
	const fail = ( message, status ) => {
		const error = new Error( message );
		error.status = status;

		return error;
	};

	const promise = new Promise( ( resolve, reject ) => {
		xhr.upload.addEventListener( 'progress', ( event ) => {
			if ( onProgress ) {
				onProgress(
					event.loaded,
					event.lengthComputable ? event.total : 0
				);
			}
		} );

		xhr.addEventListener( 'load', () => {
			if ( xhr.status >= 200 && xhr.status < 300 ) {
				resolve();
				return;
			}

			reject(
				fail(
					storageMessage( xhr.responseText ) ||
						`${ strings.uploadFailedStatus || 'Upload failed with status' } ${ xhr.status }`,
					xhr.status
				)
			);
		} );

		xhr.addEventListener( 'error', () =>
			reject( fail( strings.networkError || 'Network error', 0 ) )
		);
		xhr.addEventListener( 'abort', () =>
			reject( fail( strings.uploadCancelled || 'Upload cancelled', -1 ) )
		);

		xhr.open( 'PUT', url, true );
		Object.keys( headers ).forEach( ( name ) =>
			xhr.setRequestHeader( name, headers[ name ] )
		);
		xhr.send( body );
	} );

	return { promise, abort: () => xhr.abort() };
}

/**
 * Build an uploader.
 *
 * @param {Object}   deps          What it talks to.
 * @param {Function} deps.request  ( route name, data ) => Promise, as api.request.
 * @param {Function} deps.put      ( url, body, options ) => { promise, abort }, as xhrPut.
 * @param {Function} deps.checksum ( ArrayBuffer ) => base64 MD5.
 * @param {Object}   deps.settings partSettings().
 * @param {Object}   deps.strings  The `upload` strings.
 * @param {Function} deps.wait     ( ms ) => Promise; a test passes one that does not wait.
 * @return {{upload: Function}} The uploader.
 */
export function createUploader( {
	request,
	put = xhrPut,
	checksum,
	settings,
	strings = {},
	wait = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) ),
} ) {
	const say = ( key, fallback, values = {} ) =>
		Object.keys( values ).reduce(
			( text, name ) =>
				text.split( `{${ name }}` ).join( String( values[ name ] ) ),
			strings[ key ] || fallback
		);

	const cancelledError = () => {
		const error = new Error( say( 'uploadCancelled', 'Upload cancelled' ) );
		error.cancelled = true;

		return error;
	};

	/**
	 * Send one file.
	 *
	 * @param {File}     file              The file.
	 * @param {Object}   target            Where it goes and who hears about it.
	 * @param {string}   target.bucket     Bucket.
	 * @param {string}   target.key        Object key.
	 * @param {Function} target.onProgress Called with { loaded, total, percent }.
	 * @param {Function} target.onNote     Called with a line to show under the bar.
	 * @return {{promise: Promise<Object>, abort: Function}} The upload; resolves with the finish route's answer.
	 */
	function upload(
		file,
		{ bucket, key, onProgress = () => {}, onNote = () => {} }
	) {
		if ( usesParts( file.size, settings ) ) {
			return inParts( file, bucket, key, onProgress, onNote );
		}

		let current = null;
		let cancelled = false;

		const promise = request( 'uploadUrl', { bucket, key } ).then(
			( signed ) => {
				if ( cancelled ) {
					throw cancelledError();
				}

				if ( ! signed || ! signed.url ) {
					throw new Error(
						( signed && signed.message ) ||
							say(
								'failedPresignedUrl',
								'Could not get an upload URL.'
							)
					);
				}

				current = put( signed.url, file, {
					headers: {
						'Content-Type': file.type || 'application/octet-stream',
					},
					strings,
					onProgress: ( loaded, total ) => {
						const size = total || file.size;
						onProgress( {
							loaded,
							total: size,
							percent: size
								? Math.round( ( loaded / size ) * 100 )
								: 0,
						} );
					},
				} );

				return current.promise.then(
					() => ( { key } ),
					( error ) => {
						if ( error.status === -1 ) {
							throw cancelledError();
						}

						// No answer at all from a cross-origin PUT is what a
						// missing CORS rule looks like from here.
						if ( error.status === 0 ) {
							throw new Error(
								say( 'corsError', error.message )
							);
						}

						throw error;
					}
				);
			}
		);

		return {
			promise,
			abort: () => {
				cancelled = true;

				if ( current ) {
					current.abort();
				}
			},
		};
	}

	/**
	 * Send a file in parts, resuming the upload this file began.
	 *
	 * @param {File}     file       The file.
	 * @param {string}   bucket     Bucket.
	 * @param {string}   key        Object key.
	 * @param {Function} onProgress Progress callback.
	 * @param {Function} onNote     Note callback.
	 * @return {{promise: Promise<Object>, abort: Function}} The upload.
	 */
	function inParts( file, bucket, key, onProgress, onNote ) {
		const job = {
			state: null,
			checksums: true,
			cancelled: false,
			halted: false,
			puts: new Map(),
			signing: null,
		};

		const report = () => onProgress( progress( job.state ) );

		const stopPuts = () => {
			job.puts.forEach( ( request_ ) => request_.abort() );
			job.puts.clear();
		};

		const partUrl = async ( number, upcoming ) => {
			const { state } = job;

			if ( state.urls.has( number ) ) {
				return state.urls.get( number );
			}

			// One signing at a time; a part that needs a URL the running one
			// did not ask for waits for it and asks again.
			if ( job.signing ) {
				await job.signing.catch( () => {} );

				return partUrl( number, upcoming );
			}

			const wanted = signingBatch(
				number,
				upcoming,
				state.urls,
				settings.signBatch
			);

			job.signing = request( 'signParts', {
				bucket,
				id: state.id,
				parts: wanted,
			} ).then( ( signed ) => {
				Object.keys( ( signed && signed.urls ) || {} ).forEach( ( n ) =>
					state.urls.set( Number( n ), signed.urls[ n ] )
				);
			} );

			try {
				await job.signing;
			} finally {
				job.signing = null;
			}

			if ( ! state.urls.has( number ) ) {
				throw new Error(
					say( 'failedPresignedUrl', 'Could not get an upload URL.' )
				);
			}

			return state.urls.get( number );
		};

		const sendPart = async ( number, upcoming, attempt ) => {
			const { state } = job;

			try {
				const url = await partUrl( number, upcoming );
				const { start, end } = partRange(
					state.size,
					state.partSize,
					number
				);
				const bytes = await file.slice( start, end ).arrayBuffer();

				if ( job.cancelled || job.halted ) {
					throw cancelledError();
				}

				const headers = job.checksums
					? { 'Content-MD5': checksum( bytes ) }
					: {};
				const sending = put( url, bytes, {
					headers,
					strings,
					onProgress: ( loaded ) => {
						state.inFlight.set( number, loaded );
						report();
					},
				} );

				job.puts.set( number, sending );

				try {
					await sending.promise;
				} finally {
					job.puts.delete( number );
				}

				markDone( state, number );
				report();
			} catch ( error ) {
				state.inFlight.delete( number );

				if ( job.cancelled || job.halted || error.cancelled ) {
					throw cancelledError();
				}

				// An expired or refused URL is signed again.
				if ( error.status === 403 ) {
					state.urls.delete( number );
				}

				if (
					! retryable( error.status ) ||
					attempt >= settings.retries
				) {
					throw new Error(
						`${ say( 'partFailed', 'Part {part} failed: {reason}', {
							part: number,
							reason: error.message,
						} ) } ${ say( 'resumeHint', '' ) }`.trim()
					);
				}

				onNote(
					say( 'retrying', 'Retrying part {part}…', { part: number } )
				);
				await wait( backoff( attempt ) );

				return sendPart( number, upcoming, attempt + 1 );
			}
		};

		const sendMissing = async () => {
			const queue = missingParts( job.state );
			let next = 0;

			job.halted = false;

			const worker = async () => {
				while ( ! job.halted && next < queue.length ) {
					if ( job.cancelled ) {
						throw cancelledError();
					}

					const number = queue[ next++ ];
					await sendPart( number, queue.slice( next ), 1 );
				}
			};

			const workers = [];

			for (
				let w = 0;
				w < Math.min( settings.concurrency, queue.length );
				w++
			) {
				workers.push( worker() );
			}

			// One part given up on stops the rest, without cancelling: the
			// parts already in storage stay, for the same file dropped again.
			try {
				await Promise.all( workers );
			} catch ( error ) {
				job.halted = true;
				stopPuts();
				throw error;
			}
		};

		const complete = async ( mayRetry ) => {
			onNote( say( 'assembling', 'Assembling…' ) );

			try {
				return await request( 'completeUpload', {
					bucket,
					id: job.state.id,
				} );
			} catch ( error ) {
				const missing = error.data && error.data.missing;

				if (
					! mayRetry ||
					error.code !== 's3_upload_incomplete' ||
					! missing
				) {
					throw error;
				}

				markMissing( job.state, missing );
				await sendMissing();

				return complete( false );
			}
		};

		const run = async () => {
			if ( file.size > settings.maxSize ) {
				throw new Error(
					say( 'tooLarge', '{filename} is too large.', {
						filename: file.name,
					} )
				);
			}

			const started = await request( 'startUpload', {
				bucket,
				key,
				size: file.size,
				mime_type: file.type || 'application/octet-stream',
				fingerprint: fingerprint( file ),
			} );

			job.state = createState( started, file.size );

			// Cancelled while it was being started: give the parts back.
			if ( job.cancelled ) {
				request( 'abortUpload', { bucket, id: job.state.id } ).catch(
					() => {}
				);
				throw cancelledError();
			}

			// The server asked the bucket whether it lets this page send
			// Content-MD5; one that does not refuses the part outright.
			if ( started.checksums === false ) {
				job.checksums = false;
				onNote( say( 'noChecksums', '' ) );
			}

			if ( started.resumed && job.state.done.size ) {
				onNote(
					say(
						'resuming',
						'Resuming: {done} of {total} parts already sent.',
						{
							done: job.state.done.size,
							total: job.state.partCount,
						}
					)
				);
			}

			report();
			await sendMissing();

			return complete( true );
		};

		return {
			promise: run(),
			abort: () => {
				job.cancelled = true;
				stopPuts();

				// Free the parts in storage rather than leave them billed
				// until the daily clear-up.
				if ( job.state ) {
					request( 'abortUpload', {
						bucket,
						id: job.state.id,
					} ).catch( () => {} );
				}
			},
		};
	}

	return { upload };
}
