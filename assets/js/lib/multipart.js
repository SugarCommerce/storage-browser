/**
 * The arithmetic of a multipart upload, with nothing to do with the page.
 *
 * Every part but the last is the same size -- R2 refuses an upload whose
 * parts are not, and it is what lets a part number be turned back into a
 * byte range on any later request. What storage holds is the truth: a part
 * this side remembers may never have been kept, and a part storage kept may
 * never have been heard back about.
 */

const MiB = 1048576;
const GiB = 1073741824;
const TiB = 1099511627776;

/**
 * The multipart settings, with defaults for a page that sent none.
 *
 * @param {Object} given S3BrowserGlobalConfig.multipart.
 * @return {{threshold: number, singlePutMax: number, maxSize: number, concurrency: number, signBatch: number, retries: number}} Settings.
 */
export function partSettings( given = {} ) {
	return {
		threshold: given.threshold || 64 * MiB,
		singlePutMax: given.singlePutMax || 5 * GiB,
		maxSize: given.maxSize || 5 * TiB,
		concurrency: Math.max( 1, given.concurrency || 4 ),
		signBatch: Math.max( 1, given.signBatch || 20 ),
		retries: Math.max( 1, given.retries || 4 ),
	};
}

/**
 * Whether a file goes in parts: from the threshold up, and always past what
 * one PUT may carry.
 *
 * @param {number} size     Bytes.
 * @param {Object} settings partSettings().
 * @return {boolean} Whether it goes in parts.
 */
export function usesParts( size, settings ) {
	return size >= settings.threshold || size > settings.singlePutMax;
}

/**
 * Bytes in one part: the part size, except the last.
 *
 * @param {number} size     The file's size.
 * @param {number} partSize The upload's part size.
 * @param {number} number   The part, from 1.
 * @return {number} Bytes.
 */
export function partLength( size, partSize, number ) {
	return Math.max(
		0,
		Math.min( partSize, size - ( number - 1 ) * partSize )
	);
}

/**
 * Where a part's bytes start and end in the file.
 *
 * @param {number} size     The file's size.
 * @param {number} partSize The upload's part size.
 * @param {number} number   The part, from 1.
 * @return {{start: number, end: number}} A slice() range.
 */
export function partRange( size, partSize, number ) {
	const start = ( number - 1 ) * partSize;

	return { start, end: start + partLength( size, partSize, number ) };
}

/**
 * The state of one upload, as the server described it when it began or
 * resumed: which parts storage already holds.
 *
 * @param {Object}   upload            The start route's answer.
 * @param {string}   upload.id         The upload's id.
 * @param {number}   upload.part_size  Bytes per part.
 * @param {number}   upload.part_count Parts.
 * @param {number[]} upload.done       Parts storage holds.
 * @param {number}   size              The file's size.
 * @return {Object} The state.
 */
export function createState( upload, size ) {
	const state = {
		id: upload.id,
		size,
		partSize: upload.part_size,
		partCount: upload.part_count,
		done: new Set(),
		doneBytes: 0,
		inFlight: new Map(),
		urls: new Map(),
	};

	( upload.done || [] ).forEach( ( number ) => markDone( state, number ) );

	return state;
}

/**
 * Record a part as in storage.
 *
 * @param {Object} state  createState().
 * @param {number} number The part.
 */
export function markDone( state, number ) {
	state.inFlight.delete( number );

	if ( number < 1 || number > state.partCount || state.done.has( number ) ) {
		return;
	}

	state.done.add( number );
	state.doneBytes += partLength( state.size, state.partSize, number );
}

/**
 * Forget parts storage says it does not hold after all.
 *
 * @param {Object}   state   createState().
 * @param {number[]} numbers The parts it is missing.
 */
export function markMissing( state, numbers ) {
	numbers.forEach( ( number ) => {
		if ( state.done.delete( number ) ) {
			state.doneBytes -= partLength( state.size, state.partSize, number );
		}
	} );
}

/**
 * The parts still to send, in order.
 *
 * @param {Object} state createState().
 * @return {number[]} Part numbers.
 */
export function missingParts( state ) {
	const missing = [];

	for ( let number = 1; number <= state.partCount; number++ ) {
		if ( ! state.done.has( number ) ) {
			missing.push( number );
		}
	}

	return missing;
}

/**
 * How far along it is: what storage holds plus what is on its way.
 *
 * @param {Object} state createState().
 * @return {{loaded: number, total: number, percent: number}} Progress.
 */
export function progress( state ) {
	let moving = 0;

	state.inFlight.forEach( ( bytes ) => {
		moving += bytes;
	} );

	const loaded = Math.min( state.size, state.doneBytes + moving );

	return {
		loaded,
		total: state.size,
		percent: state.size ? Math.floor( ( loaded / state.size ) * 100 ) : 0,
	};
}

/**
 * The parts to sign together: this one, and the next ones that have no URL
 * yet, up to the batch size.
 *
 * @param {number}   number   The part a URL is needed for now.
 * @param {number[]} upcoming The parts queued after it.
 * @param {Map}      urls     Part => URL already signed.
 * @param {number}   batch    The most to ask for at once.
 * @return {number[]} Part numbers.
 */
export function signingBatch( number, upcoming, urls, batch ) {
	return [ number ]
		.concat( upcoming.filter( ( n ) => n !== number && ! urls.has( n ) ) )
		.slice( 0, batch );
}

/**
 * Whether a failed part is worth sending again: a dropped connection, an
 * expired or refused URL (signed again), a timeout, throttling, or the
 * provider's own trouble. Anything else will fail the same way twice.
 *
 * @param {number} status The PUT's HTTP status; 0 for no answer.
 * @return {boolean} Whether to retry.
 */
export function retryable( status ) {
	return (
		status === 0 ||
		status === 403 ||
		status === 408 ||
		status === 429 ||
		status >= 500
	);
}

/**
 * How long to wait before an attempt: 1, 2, 4, 8 seconds.
 *
 * @param {number} attempt The attempt that failed, from 1.
 * @return {number} Milliseconds.
 */
export function backoff( attempt ) {
	return 1000 * Math.pow( 2, attempt - 1 );
}

/**
 * What finds the same upload again when the file is dropped a second time.
 *
 * @param {{name: string, size: number, lastModified: number}} file The file.
 * @return {string} Fingerprint.
 */
export function fingerprint( file ) {
	return [ file.name, file.size, file.lastModified ].join( '|' );
}

/**
 * The key a file lands at in a folder.
 *
 * @param {string} prefix The folder, with or without its slash.
 * @param {string} name   The file's name.
 * @return {string} Object key.
 */
export function objectKey( prefix, name ) {
	if ( ! prefix ) {
		return name;
	}

	return ( prefix.endsWith( '/' ) ? prefix : prefix + '/' ) + name;
}

/**
 * Storage's own words out of an XML error document, when it sent one.
 *
 * @param {string} body The response text.
 * @return {string} The <Message>, or ''.
 */
export function storageMessage( body ) {
	const found = /<Message>([^<]*)<\/Message>/.exec( body || '' );

	return found ? found[ 1 ] : '';
}
