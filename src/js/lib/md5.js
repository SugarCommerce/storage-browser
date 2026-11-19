/* eslint-disable no-bitwise -- MD5 is bit arithmetic. */
/**
 * MD5, for the Content-MD5 of an upload part.
 *
 * The browser's own crypto has SHA but no MD5, and Content-MD5 is the
 * checksum every S3-compatible store checks a part against, refusing one
 * whose bytes changed on the way (BadDigest). Not for anything secret: MD5
 * is a checksum here, which is all it is still good for.
 */

// Per-round shift amounts.
const S = [
	7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22, 5, 9, 14, 20, 5,
	9, 14, 20, 5, 9, 14, 20, 5, 9, 14, 20, 4, 11, 16, 23, 4, 11, 16, 23, 4, 11,
	16, 23, 4, 11, 16, 23, 6, 10, 15, 21, 6, 10, 15, 21, 6, 10, 15, 21, 6, 10,
	15, 21,
];

// The sine table: floor(abs(sin(i + 1)) * 2^32).
const K = new Int32Array( 64 );

for ( let i = 0; i < 64; i++ ) {
	K[ i ] = Math.floor( Math.abs( Math.sin( i + 1 ) ) * 4294967296 ) | 0;
}

/**
 * Read one 64-byte block as sixteen little-endian words.
 *
 * @param {Uint8Array} bytes  The bytes.
 * @param {number}     offset Where the block starts.
 * @param {Int32Array} words  Where the words go.
 */
function load( bytes, offset, words ) {
	for ( let j = 0; j < 16; j++ ) {
		const o = offset + j * 4;
		words[ j ] =
			bytes[ o ] |
			( bytes[ o + 1 ] << 8 ) |
			( bytes[ o + 2 ] << 16 ) |
			( bytes[ o + 3 ] << 24 );
	}
}

/**
 * Fold one block into the state.
 *
 * @param {Int32Array} state The running state.
 * @param {Int32Array} words The block, as words.
 */
function cycle( state, words ) {
	let [ a, b, c, d ] = state;

	for ( let j = 0; j < 64; j++ ) {
		let f;
		let g;

		if ( j < 16 ) {
			f = ( b & c ) | ( ~b & d );
			g = j;
		} else if ( j < 32 ) {
			f = ( d & b ) | ( ~d & c );
			g = ( 5 * j + 1 ) & 15;
		} else if ( j < 48 ) {
			f = b ^ c ^ d;
			g = ( 3 * j + 5 ) & 15;
		} else {
			f = c ^ ( b | ~d );
			g = ( 7 * j ) & 15;
		}

		const t = d;
		d = c;
		c = b;
		const x = ( a + f + K[ j ] + words[ g ] ) | 0;
		b = ( b + ( ( x << S[ j ] ) | ( x >>> ( 32 - S[ j ] ) ) ) ) | 0;
		a = t;
	}

	state[ 0 ] = ( state[ 0 ] + a ) | 0;
	state[ 1 ] = ( state[ 1 ] + b ) | 0;
	state[ 2 ] = ( state[ 2 ] + c ) | 0;
	state[ 3 ] = ( state[ 3 ] + d ) | 0;
}

/**
 * The sixteen-byte digest of some bytes.
 *
 * @param {ArrayBuffer|Uint8Array} input The bytes.
 * @return {Uint8Array} The digest.
 */
export function digest( input ) {
	const bytes = input instanceof Uint8Array ? input : new Uint8Array( input );
	const length = bytes.length;
	const state = new Int32Array( [
		0x67452301,
		0xefcdab89 | 0,
		0x98badcfe | 0,
		0x10325476,
	] );
	const words = new Int32Array( 16 );
	const whole = length - ( length % 64 );

	for ( let offset = 0; offset < whole; offset += 64 ) {
		load( bytes, offset, words );
		cycle( state, words );
	}

	// What is left, a 1 bit, zeros, and the length in bits as a 64-bit
	// little-endian number: one block, or two when the length will not fit
	// after what is left.
	const rest = length - whole;
	const tail = new Uint8Array( rest < 56 ? 64 : 128 );
	const low = ( length % 0x20000000 ) * 8;
	const high = Math.floor( length / 0x20000000 );
	const end = tail.length - 8;

	tail.set( bytes.subarray( whole ) );
	tail[ rest ] = 0x80;

	for ( let k = 0; k < 4; k++ ) {
		tail[ end + k ] = ( low >>> ( 8 * k ) ) & 255;
		tail[ end + 4 + k ] = ( high >>> ( 8 * k ) ) & 255;
	}

	for ( let offset = 0; offset < tail.length; offset += 64 ) {
		load( tail, offset, words );
		cycle( state, words );
	}

	const out = new Uint8Array( 16 );

	for ( let n = 0; n < 4; n++ ) {
		out[ n * 4 ] = state[ n ] & 255;
		out[ n * 4 + 1 ] = ( state[ n ] >>> 8 ) & 255;
		out[ n * 4 + 2 ] = ( state[ n ] >>> 16 ) & 255;
		out[ n * 4 + 3 ] = ( state[ n ] >>> 24 ) & 255;
	}

	return out;
}

/**
 * The Content-MD5 header value: the digest, base64 encoded.
 *
 * @param {ArrayBuffer|Uint8Array} input The bytes.
 * @return {string} Base64 of the digest.
 */
export function md5Base64( input ) {
	return btoa( String.fromCharCode( ...digest( input ) ) );
}

/**
 * The digest as 32 hex digits.
 *
 * @param {ArrayBuffer|Uint8Array} input The bytes.
 * @return {string} Hex digest.
 */
export function md5Hex( input ) {
	return Array.from( digest( input ), ( byte ) =>
		byte.toString( 16 ).padStart( 2, '0' )
	).join( '' );
}
